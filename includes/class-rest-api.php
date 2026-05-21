<?php
namespace WPSMSHub;

if ( ! defined( 'ABSPATH' ) ) exit;

class REST_API {
    public function __construct() {
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
    }

    public function register_routes() {
        $ns = 'wp-sms-hub/v1';
        register_rest_route( $ns, '/send', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'send' ],
            'permission_callback' => [ $this, 'auth' ],
        ] );
        register_rest_route( $ns, '/log', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_log' ],
            'permission_callback' => [ $this, 'auth' ],
        ] );
        register_rest_route( $ns, '/providers', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_providers' ],
            'permission_callback' => [ $this, 'auth' ],
        ] );
    }

    public function auth( \WP_REST_Request $request ): bool {
        // Accept either WP cookie auth or API key in X-SMS-Hub-Key header
        $header_key = $request->get_header( 'X-SMS-Hub-Key' );

        // Check master API key first
        $api_key = get_option( 'wpsmshub_rest_api_key', '' );
        if ( $api_key && $header_key === $api_key ) return true;

        // Check sub-account API keys
        if ( $header_key ) {
            $sub_account = Sub_Accounts::get_by_api_key( $header_key );
            if ( $sub_account ) {
                // Check rate limits for sub-account
                $limits = Sub_Accounts::check_limits( (int) $sub_account['id'] );
                if ( ! $limits['allowed'] ) {
                    // Store denial reason for the send handler
                    $request->set_param( '_smshub_denied', $limits['reason'] );
                    return true; // Auth passes but send will check the denial
                }
                // Store sub-account info on the request for usage tracking
                $request->set_param( '_smshub_sub_account_id', (int) $sub_account['id'] );
                return true;
            }
        }

        // Apply IP whitelist filter
        $allowed = apply_filters( 'wp_sms_hub_rest_auth', true, $request );
        if ( ! $allowed ) return false;

        return current_user_can( 'manage_options' );
    }

    public function send( \WP_REST_Request $request ): \WP_REST_Response {
        // Check if denied due to sub-account rate limits
        $denied = $request->get_param( '_smshub_denied' );
        if ( $denied ) {
            return new \WP_REST_Response( [ 'success' => false, 'error' => $denied ], 429 );
        }

        $to      = $request->get_param( 'to' );
        $message = $request->get_param( 'message' );
        $provider = $request->get_param( 'provider' );
        $sender   = $request->get_param( 'sender_id' );

        if ( ! $to || ! $message ) {
            return new \WP_REST_Response( [ 'success' => false, 'error' => '`to` and `message` are required.' ], 400 );
        }

        $sub_account_id = $request->get_param( '_smshub_sub_account_id' );
        $trigger_src    = 'rest_api';
        if ( $sub_account_id ) {
            $trigger_src = 'subaccount:' . $sub_account_id . ':rest_api';
        }

        $result = SMS_Manager::send( $to, $message, [
            'provider'  => $provider,
            'sender_id' => $sender,
            'trigger_src' => $trigger_src,
        ] );

        // Track usage for sub-account
        if ( $sub_account_id && $result['success'] ) {
            Sub_Accounts::increment_usage( $sub_account_id );
        }

        return new \WP_REST_Response( $result, $result['success'] ? 200 : 502 );
    }

    public function get_log( \WP_REST_Request $request ): \WP_REST_Response {
        $data = Log::get_list([
            'per_page' => (int) ( $request->get_param( 'per_page' ) ?? 50 ),
            'offset'   => (int) ( $request->get_param( 'offset' )   ?? 0 ),
            'status'   => $request->get_param( 'status' ),
            'provider' => $request->get_param( 'provider' ),
        ]);
        return new \WP_REST_Response( $data );
    }

    public function get_providers( \WP_REST_Request $request ): \WP_REST_Response {
        $list = [];
        foreach ( SMS_Manager::get_providers() as $key => $p ) {
            $list[] = [ 'key' => $key, 'label' => $p->get_label() ];
        }
        return new \WP_REST_Response( [ 'providers' => $list, 'active' => get_option( 'wpsmshub_active_provider' ) ] );
    }
}
