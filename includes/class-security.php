<?php
namespace WPSMSHub;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Security - IP whitelist for API, webhook signature verification.
 */
class Security {

    public function __construct() {
        // Verify IP whitelist on REST API calls
        add_filter( 'wp_sms_hub_rest_auth', [ $this, 'check_ip_whitelist' ], 10, 2 );
    }

    /**
     * Check if the request IP is whitelisted (if whitelist is configured).
     */
    public function check_ip_whitelist( bool $allowed, \WP_REST_Request $request ): bool {
        if ( ! $allowed ) return false;

        $whitelist = get_option( 'wpsmshub_ip_whitelist', '' );
        if ( empty( trim( $whitelist ) ) ) return $allowed; // No whitelist = allow all

        $allowed_ips = array_filter( array_map( 'trim', explode( "\n", $whitelist ) ) );
        if ( empty( $allowed_ips ) ) return $allowed;

        $client_ip = self::get_client_ip();

        foreach ( $allowed_ips as $ip ) {
            if ( $ip === $client_ip ) return true;
            // Support CIDR notation
            if ( str_contains( $ip, '/' ) && self::ip_in_cidr( $client_ip, $ip ) ) return true;
        }

        return false;
    }

    /**
     * Verify webhook signature (HMAC-SHA256).
     * Call this in webhook handlers to validate authenticity.
     */
    public static function verify_webhook_signature( \WP_REST_Request $request, string $provider ): bool {
        $secret = get_option( 'wpsmshub_webhook_secret_' . $provider, '' );
        if ( empty( $secret ) ) return true; // No secret configured = skip verification

        $body = $request->get_body();
        $signature = $request->get_header( 'X-Hub-Signature-256' )
                  ?? $request->get_header( 'X-Signature' )
                  ?? $request->get_header( 'X-Webhook-Signature' )
                  ?? '';

        if ( empty( $signature ) ) return false;

        // Remove prefix if present (e.g., "sha256=")
        $signature = preg_replace( '/^(sha256=|hmac-sha256=)/i', '', $signature );

        $expected = hash_hmac( 'sha256', $body, $secret );

        return hash_equals( $expected, $signature );
    }

    /**
     * Generate a webhook secret for a provider.
     */
    public static function generate_webhook_secret( string $provider ): string {
        $secret = bin2hex( random_bytes( 32 ) );
        update_option( 'wpsmshub_webhook_secret_' . $provider, $secret );
        return $secret;
    }

    /**
     * Get client IP address.
     */
    public static function get_client_ip(): string {
        $headers = [ 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR' ];
        foreach ( $headers as $h ) {
            if ( ! empty( $_SERVER[ $h ] ) ) {
                return sanitize_text_field( explode( ',', $_SERVER[ $h ] )[0] );
            }
        }
        return '0.0.0.0';
    }

    /**
     * Check if IP is within a CIDR range.
     */
    private static function ip_in_cidr( string $ip, string $cidr ): bool {
        list( $subnet, $bits ) = explode( '/', $cidr );
        $ip_long     = ip2long( $ip );
        $subnet_long = ip2long( $subnet );
        $mask        = -1 << ( 32 - (int) $bits );
        return ( $ip_long & $mask ) === ( $subnet_long & $mask );
    }
}
