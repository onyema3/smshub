<?php
namespace WPSMSHub;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * REST API v2 - Improved API with batch send, cursor pagination, schema validation.
 */
class REST_API_V2 {

    public function __construct() {
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
    }

    public function register_routes() {
        $ns = 'wp-sms-hub/v2';

        // Send single or batch
        register_rest_route( $ns, '/send', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'send' ],
            'permission_callback' => [ $this, 'auth' ],
            'args'                => $this->get_send_args(),
        ] );

        // Batch send (up to 10k)
        register_rest_route( $ns, '/batch', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'batch_send' ],
            'permission_callback' => [ $this, 'auth' ],
        ] );

        // Log with cursor pagination
        register_rest_route( $ns, '/messages', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_messages' ],
            'permission_callback' => [ $this, 'auth' ],
        ] );

        // Single message
        register_rest_route( $ns, '/messages/(?P<id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_message' ],
            'permission_callback' => [ $this, 'auth' ],
        ] );

        // Contacts
        register_rest_route( $ns, '/contacts', [
            'methods'             => [ 'GET', 'POST' ],
            'callback'            => [ $this, 'handle_contacts' ],
            'permission_callback' => [ $this, 'auth' ],
        ] );

        // Balance
        register_rest_route( $ns, '/balance', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_balance' ],
            'permission_callback' => [ $this, 'auth' ],
        ] );

        // Providers
        register_rest_route( $ns, '/providers', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_providers' ],
            'permission_callback' => [ $this, 'auth' ],
        ] );

        // Health check
        register_rest_route( $ns, '/health', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'health_check' ],
            'permission_callback' => '__return_true',
        ] );
    }

    public function auth( \WP_REST_Request $request ): bool {
        $api_key = get_option( 'wpsmshub_rest_api_key', '' );
        $header  = $request->get_header( 'X-SMS-Hub-Key' ) ?? $request->get_header( 'Authorization' );

        // Bearer token support
        if ( $header && str_starts_with( $header, 'Bearer ' ) ) {
            $header = substr( $header, 7 );
        }

        if ( $api_key && $header === $api_key ) return true;

        // Check sub-accounts
        $sub = Sub_Accounts::get_by_api_key( $header ?? '' );
        if ( $sub && $sub['status'] === 'active' ) return true;

        return current_user_can( 'manage_options' );
    }

    // ── Send ────────────────────────────────────────────────────────────
    public function send( \WP_REST_Request $request ): \WP_REST_Response {
        $to      = $request->get_param( 'to' );
        $message = $request->get_param( 'message' );

        if ( ! $to || ! $message ) {
            return new \WP_REST_Response( [ 'error' => 'to and message are required', 'code' => 'missing_params' ], 400 );
        }

        $result = SMS_Manager::send( $to, $message, [
            'provider'    => $request->get_param( 'provider' ),
            'sender_id'   => $request->get_param( 'sender_id' ),
            'trigger_src' => 'api_v2',
        ] );

        $code = $result['success'] ? 200 : 502;
        return new \WP_REST_Response( $result, $code );
    }

    // ── Batch Send ──────────────────────────────────────────────────────
    public function batch_send( \WP_REST_Request $request ): \WP_REST_Response {
        $messages = $request->get_json_params()['messages'] ?? [];

        if ( empty( $messages ) || ! is_array( $messages ) ) {
            return new \WP_REST_Response( [ 'error' => 'messages array required', 'code' => 'missing_params' ], 400 );
        }

        if ( count( $messages ) > 10000 ) {
            return new \WP_REST_Response( [ 'error' => 'Maximum 10000 messages per batch', 'code' => 'batch_too_large' ], 400 );
        }

        $queued = 0;
        foreach ( $messages as $msg ) {
            $to      = $msg['to'] ?? '';
            $body    = $msg['message'] ?? $request->get_param( 'message' ) ?? '';
            if ( ! $to || ! $body ) continue;

            Queue::enqueue( SMS_Manager::normalize_phone( $to ), $body, [
                'provider'    => $msg['provider'] ?? $request->get_param( 'provider' ) ?? '',
                'sender_id'   => $msg['sender_id'] ?? $request->get_param( 'sender_id' ) ?? '',
                'trigger_src' => 'api_v2_batch',
            ] );
            $queued++;
        }

        return new \WP_REST_Response( [
            'success' => true,
            'queued'  => $queued,
            'message' => "{$queued} messages queued for delivery",
        ], 202 );
    }

    // ── Messages (cursor pagination) ────────────────────────────────────
    public function get_messages( \WP_REST_Request $request ): \WP_REST_Response {
        global $wpdb;
        $table  = $wpdb->prefix . 'smshub_log';
        $limit  = min( (int) ( $request->get_param( 'limit' ) ?? 50 ), 200 );
        $cursor = (int) ( $request->get_param( 'cursor' ) ?? 0 );
        $status = $request->get_param( 'status' );

        $where  = $cursor ? $wpdb->prepare( "WHERE id < %d", $cursor ) : "WHERE 1=1";
        if ( $status ) $where .= $wpdb->prepare( " AND status = %s", $status );

        $items = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} {$where} ORDER BY id DESC LIMIT %d",
            $limit
        ), ARRAY_A );

        $next_cursor = ! empty( $items ) ? end( $items )['id'] : null;

        return new \WP_REST_Response( [
            'data'        => $items,
            'next_cursor' => $next_cursor,
            'has_more'    => count( $items ) === $limit,
        ] );
    }

    // ── Single Message ──────────────────────────────────────────────────
    public function get_message( \WP_REST_Request $request ): \WP_REST_Response {
        global $wpdb;
        $id  = (int) $request->get_param( 'id' );
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}smshub_log WHERE id = %d", $id
        ), ARRAY_A );

        if ( ! $row ) return new \WP_REST_Response( [ 'error' => 'Not found', 'code' => 'not_found' ], 404 );
        return new \WP_REST_Response( [ 'data' => $row ] );
    }

    // ── Contacts ────────────────────────────────────────────────────────
    public function handle_contacts( \WP_REST_Request $request ): \WP_REST_Response {
        if ( $request->get_method() === 'POST' ) {
            $res = Contacts::add( [
                'name'  => $request->get_param( 'name' ) ?? '',
                'phone' => $request->get_param( 'phone' ) ?? '',
                'group' => $request->get_param( 'group' ) ?? 'Default',
            ] );
            if ( $res ) return new \WP_REST_Response( [ 'success' => true, 'id' => $res ], 201 );
            return new \WP_REST_Response( [ 'error' => 'Failed (duplicate phone?)' ], 422 );
        }

        // GET
        $data = Contacts::get_list( [
            'per_page' => min( (int) ( $request->get_param( 'limit' ) ?? 50 ), 200 ),
            'offset'   => (int) ( $request->get_param( 'offset' ) ?? 0 ),
            'group'    => $request->get_param( 'group' ),
            'search'   => $request->get_param( 'search' ),
        ] );
        return new \WP_REST_Response( $data );
    }

    // ── Balance ─────────────────────────────────────────────────────────
    public function get_balance( \WP_REST_Request $request ): \WP_REST_Response {
        $key      = $request->get_param( 'provider' ) ?? get_option( 'wpsmshub_active_provider' );
        $provider = SMS_Manager::get_provider( $key );
        if ( ! $provider ) return new \WP_REST_Response( [ 'error' => 'Provider not found' ], 404 );
        return new \WP_REST_Response( $provider->get_balance() );
    }

    // ── Providers ───────────────────────────────────────────────────────
    public function get_providers( \WP_REST_Request $request ): \WP_REST_Response {
        $list = [];
        foreach ( SMS_Manager::get_providers() as $key => $p ) {
            $list[] = [ 'key' => $key, 'label' => $p->get_label() ];
        }
        return new \WP_REST_Response( [
            'providers' => $list,
            'active'    => get_option( 'wpsmshub_active_provider' ),
            'version'   => 'v2',
        ] );
    }

    // ── Health ──────────────────────────────────────────────────────────
    public function health_check( \WP_REST_Request $request ): \WP_REST_Response {
        return new \WP_REST_Response( [
            'status'    => 'ok',
            'version'   => WPSMSHUB_VERSION,
            'api'       => 'v2',
            'timestamp' => current_time( 'c' ),
        ] );
    }

    // ── Schema validation args ──────────────────────────────────────────
    private function get_send_args(): array {
        return [
            'to'        => [ 'required' => true, 'type' => 'string', 'description' => 'Recipient phone number(s), comma-separated' ],
            'message'   => [ 'required' => true, 'type' => 'string', 'description' => 'Message body (max 1600 chars)' ],
            'provider'  => [ 'required' => false, 'type' => 'string', 'description' => 'Provider key (uses active if omitted)' ],
            'sender_id' => [ 'required' => false, 'type' => 'string', 'description' => 'Override sender ID' ],
        ];
    }
}
