<?php
namespace WPSMSHub;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Link Shortening & Click Tracking.
 * Shortens URLs in SMS messages and tracks clicks via REST redirect.
 */
class Link_Tracker {

    private static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'smshub_links';
    }

    public function __construct() {
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
    }

    /**
     * Register the redirect REST route.
     */
    public function register_routes(): void {
        register_rest_route( 'wp-sms-hub/v1', '/r/(?P<code>[a-zA-Z0-9]{6})', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'handle_redirect' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'code' => [
                    'required'          => true,
                    'validate_callback' => function( $param ) {
                        return preg_match( '/^[a-zA-Z0-9]{6}$/', $param );
                    },
                ],
            ],
        ] );
    }

    /**
     * Handle redirect: increment click and 302 redirect.
     */
    public function handle_redirect( \WP_REST_Request $request ): \WP_REST_Response {
        $code = $request->get_param( 'code' );
        $link = self::get_by_code( $code );

        if ( ! $link ) {
            return new \WP_REST_Response( [ 'error' => 'Link not found' ], 404 );
        }

        self::increment_click( $code );

        $response = new \WP_REST_Response( null, 302 );
        $response->header( 'Location', $link['original_url'] );
        $response->header( 'Cache-Control', 'no-cache, no-store, must-revalidate' );
        return $response;
    }

    /**
     * Shorten a URL. Returns the short URL.
     */
    public static function shorten( string $url, ?int $campaign_id = null ): string {
        global $wpdb;

        // Check if URL already shortened for this campaign
        if ( $campaign_id ) {
            $existing = $wpdb->get_var( $wpdb->prepare(
                "SELECT short_code FROM " . self::table() . " WHERE original_url = %s AND campaign_id = %d",
                $url, $campaign_id
            ) );
            if ( $existing ) {
                return self::build_short_url( $existing );
            }
        }

        $code = self::generate_unique_code();

        $wpdb->insert( self::table(), [
            'original_url' => esc_url_raw( $url ),
            'short_code'   => $code,
            'campaign_id'  => $campaign_id,
            'clicks'       => 0,
            'created_at'   => current_time( 'mysql' ),
        ] );

        return self::build_short_url( $code );
    }

    /**
     * Get link record by short code.
     */
    public static function get_by_code( string $code ): ?array {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE short_code = %s", $code
        ), ARRAY_A );
        return $row ?: null;
    }

    /**
     * Increment click count for a short code.
     */
    public static function increment_click( string $code ): bool {
        global $wpdb;
        return (bool) $wpdb->query( $wpdb->prepare(
            "UPDATE " . self::table() . " SET clicks = clicks + 1 WHERE short_code = %s", $code
        ) );
    }

    /**
     * Get click stats for a campaign.
     */
    public static function get_stats( ?int $campaign_id = null ): array {
        global $wpdb;
        $table = self::table();

        if ( $campaign_id ) {
            return $wpdb->get_results( $wpdb->prepare(
                "SELECT short_code, original_url, clicks, created_at FROM {$table} WHERE campaign_id = %d ORDER BY clicks DESC",
                $campaign_id
            ), ARRAY_A );
        }

        return $wpdb->get_results(
            "SELECT short_code, original_url, clicks, campaign_id, created_at FROM {$table} ORDER BY created_at DESC LIMIT 100",
            ARRAY_A
        );
    }

    /**
     * Process a message: find all URLs and replace them with short links.
     */
    public static function process_message( string $message, ?int $campaign_id = null ): string {
        // Match URLs in the message
        $pattern = '/https?:\/\/[^\s<>"{}|\\\\^`\[\]]+/i';
        return preg_replace_callback( $pattern, function( $matches ) use ( $campaign_id ) {
            return self::shorten( $matches[0], $campaign_id );
        }, $message );
    }

    /**
     * Generate a unique 6-character alphanumeric code.
     */
    private static function generate_unique_code(): string {
        global $wpdb;
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $max   = strlen( $chars ) - 1;

        do {
            $code = '';
            for ( $i = 0; $i < 6; $i++ ) {
                $code .= $chars[ random_int( 0, $max ) ];
            }
            $exists = $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM " . self::table() . " WHERE short_code = %s", $code
            ) );
        } while ( $exists > 0 );

        return $code;
    }

    /**
     * Build the full short URL from a code.
     */
    private static function build_short_url( string $code ): string {
        return rest_url( 'wp-sms-hub/v1/r/' . $code );
    }
}
