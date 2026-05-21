<?php
namespace WPSMSHub;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Delivery Status Webhooks - Receive delivery receipts from SMS providers.
 * Each provider sends DLRs (Delivery Reports) to a unique endpoint.
 */
class Webhooks {
    public function __construct() {
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
    }

    public function register_routes() {
        $ns = 'wp-sms-hub/v1';

        // Generic webhook endpoint: /wp-json/wp-sms-hub/v1/webhook/{provider}
        register_rest_route( $ns, '/webhook/(?P<provider>[a-zA-Z0-9_-]+)', [
            'methods'             => [ 'POST', 'GET' ],
            'callback'            => [ $this, 'handle_webhook' ],
            'permission_callback' => '__return_true', // Public endpoint - providers need to reach it
        ] );

        // Inbound SMS endpoint: /wp-json/wp-sms-hub/v1/inbound/{provider}
        register_rest_route( $ns, '/inbound/(?P<provider>[a-zA-Z0-9_-]+)', [
            'methods'             => [ 'POST', 'GET' ],
            'callback'            => [ $this, 'handle_inbound' ],
            'permission_callback' => '__return_true',
        ] );

        // Delivery status endpoint for admin
        register_rest_route( $ns, '/delivery-stats', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_delivery_stats' ],
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
        ] );
    }

    /**
     * Handle incoming webhook from a provider.
     */
    public function handle_webhook( \WP_REST_Request $request ): \WP_REST_Response {
        $provider_key = $request->get_param( 'provider' );
        $body         = $request->get_json_params() ?: $request->get_query_params();

        // Log the raw webhook for debugging
        do_action( 'wp_sms_hub_webhook_received', $provider_key, $body );

        // Parse based on provider
        $parsed = $this->parse_delivery_report( $provider_key, $body, $request );

        if ( ! $parsed ) {
            return new \WP_REST_Response( [ 'status' => 'unknown_provider' ], 200 );
        }

        // Update the log entry
        if ( ! empty( $parsed['provider_id'] ) ) {
            $this->update_delivery_status( $parsed );
        }

        return new \WP_REST_Response( [ 'status' => 'ok' ], 200 );
    }

    /**
     * Parse delivery report based on provider format.
     */
    private function parse_delivery_report( string $provider, array $body, \WP_REST_Request $request ): ?array {
        switch ( $provider ) {
            case 'Twilio':
            case 'twilio':
                return $this->parse_twilio( $body, $request );

            case 'Termii':
            case 'termii':
                return $this->parse_termii( $body );

            case 'InfoBip':
            case 'infobip':
                return $this->parse_infobip( $body );

            case 'Vonage':
            case 'vonage':
                return $this->parse_vonage( $body );

            case 'AfricasTalking':
            case 'africastalking':
                return $this->parse_africastalking( $body, $request );

            case 'BulkSMSNigeria':
            case 'bulksmsnigeria':
                return $this->parse_bulksmsnigeria( $body );

            case 'KudiSMS':
            case 'kudisms':
                return $this->parse_kudisms( $body );

            default:
                // Generic: expect { message_id, status }
                return $this->parse_generic( $body );
        }
    }

    private function parse_twilio( array $body, \WP_REST_Request $request ): ?array {
        // Twilio sends form-encoded data
        $params = $request->get_body_params();
        $sid    = $params['MessageSid'] ?? $params['SmsSid'] ?? null;
        $status = $params['MessageStatus'] ?? $params['SmsStatus'] ?? null;
        if ( ! $sid ) return null;
        return [
            'provider_id' => $sid,
            'status'      => $this->normalize_status( $status, 'twilio' ),
            'raw_status'  => $status,
        ];
    }

    private function parse_termii( array $body ): ?array {
        $mid    = $body['message_id'] ?? null;
        $status = $body['status'] ?? $body['delivery_status'] ?? null;
        if ( ! $mid ) return null;
        return [
            'provider_id' => $mid,
            'status'      => $this->normalize_status( $status, 'termii' ),
            'raw_status'  => $status,
        ];
    }

    private function parse_infobip( array $body ): ?array {
        $results = $body['results'] ?? [ $body ];
        $parsed  = [];
        foreach ( $results as $r ) {
            $mid    = $r['messageId'] ?? null;
            $status = $r['status']['name'] ?? $r['status'] ?? null;
            if ( $mid ) {
                $parsed[] = [
                    'provider_id' => $mid,
                    'status'      => $this->normalize_status( $status, 'infobip' ),
                    'raw_status'  => $status,
                ];
            }
        }
        // Process all results
        foreach ( $parsed as $p ) {
            $this->update_delivery_status( $p );
        }
        return $parsed[0] ?? null;
    }

    private function parse_vonage( array $body ): ?array {
        $mid    = $body['messageId'] ?? $body['message-id'] ?? null;
        $status = $body['status'] ?? null;
        if ( ! $mid ) return null;
        return [
            'provider_id' => $mid,
            'status'      => $this->normalize_status( $status, 'vonage' ),
            'raw_status'  => $status,
        ];
    }

    private function parse_africastalking( array $body, \WP_REST_Request $request ): ?array {
        $params = $request->get_body_params();
        $mid    = $params['id'] ?? $body['id'] ?? null;
        $status = $params['status'] ?? $body['status'] ?? null;
        if ( ! $mid ) return null;
        return [
            'provider_id' => $mid,
            'status'      => $this->normalize_status( $status, 'africastalking' ),
            'raw_status'  => $status,
        ];
    }

    private function parse_bulksmsnigeria( array $body ): ?array {
        $mid    = $body['message_id'] ?? $body['data']['message_id'] ?? null;
        $status = $body['status'] ?? $body['delivery_status'] ?? null;
        if ( ! $mid ) return null;
        return [
            'provider_id' => $mid,
            'status'      => $this->normalize_status( $status, 'bulksmsnigeria' ),
            'raw_status'  => $status,
        ];
    }

    private function parse_kudisms( array $body ): ?array {
        $mid    = $body['message_id'] ?? null;
        $status = $body['status'] ?? $body['dlr_status'] ?? null;
        if ( ! $mid ) return null;
        return [
            'provider_id' => $mid,
            'status'      => $this->normalize_status( $status, 'kudisms' ),
            'raw_status'  => $status,
        ];
    }

    private function parse_generic( array $body ): ?array {
        $mid    = $body['message_id'] ?? $body['id'] ?? $body['messageId'] ?? null;
        $status = $body['status'] ?? $body['delivery_status'] ?? null;
        if ( ! $mid ) return null;
        return [
            'provider_id' => $mid,
            'status'      => $this->normalize_status( $status, 'generic' ),
            'raw_status'  => $status,
        ];
    }

    /**
     * Normalize provider-specific status to our internal statuses.
     */
    private function normalize_status( ?string $status, string $provider ): string {
        if ( ! $status ) return 'unknown';
        $status = strtolower( $status );

        // Delivered statuses
        $delivered = [ 'delivered', 'sent', 'success', 'accepted', 'DELIVRD' ];
        if ( in_array( $status, array_map( 'strtolower', $delivered ) ) ) return 'delivered';

        // Failed statuses
        $failed = [ 'failed', 'undelivered', 'rejected', 'expired', 'UNDELIV', 'REJECTD' ];
        if ( in_array( $status, array_map( 'strtolower', $failed ) ) ) return 'failed';

        // Pending/in-transit
        $pending = [ 'pending', 'queued', 'sending', 'sent', 'buffered', 'submitted' ];
        if ( in_array( $status, array_map( 'strtolower', $pending ) ) ) return 'sent';

        return 'unknown';
    }

    /**
     * Update log entry with delivery status.
     */
    private function update_delivery_status( array $parsed ) {
        global $wpdb;
        $table = $wpdb->prefix . 'smshub_log';

        // Find the log entry by provider_id
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, status FROM {$table} WHERE provider_id = %s ORDER BY created_at DESC LIMIT 1",
            $parsed['provider_id']
        ) );

        if ( ! $row ) return;

        // Only update if new status is more definitive
        $priority = [ 'pending' => 0, 'sent' => 1, 'delivered' => 2, 'failed' => 2, 'unknown' => 0 ];
        $current_priority = $priority[ $row->status ] ?? 0;
        $new_priority     = $priority[ $parsed['status'] ] ?? 0;

        if ( $new_priority >= $current_priority ) {
            $wpdb->update( $table, [
                'status' => $parsed['status'],
            ], [ 'id' => $row->id ] );

            do_action( 'wp_sms_hub_delivery_update', $row->id, $parsed['status'], $parsed );
        }
    }

    /**
     * Get delivery statistics for the dashboard.
     */
    public function get_delivery_stats( \WP_REST_Request $request ): \WP_REST_Response {
        return new \WP_REST_Response( self::calculate_stats() );
    }

    /**
     * Calculate delivery rate stats.
     */
    public static function calculate_stats(): array {
        global $wpdb;
        $table = $wpdb->prefix . 'smshub_log';
        $total     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE direction='outbound'" );
        $delivered = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status='delivered'" );
        $sent      = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status='sent'" );
        $failed    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status='failed'" );

        return [
            'total'         => $total,
            'delivered'     => $delivered,
            'sent'          => $sent,
            'failed'        => $failed,
            'delivery_rate' => $total > 0 ? round( ( $delivered + $sent ) / $total * 100, 1 ) : 0,
        ];
    }

    /**
     * Handle inbound SMS (two-way).
     * Providers POST inbound messages to /wp-json/wp-sms-hub/v1/inbound/{provider}
     */
    public function handle_inbound( \WP_REST_Request $request ): \WP_REST_Response {
        $provider_key = $request->get_param( 'provider' );
        $body         = $request->get_json_params() ?: $request->get_body_params();

        $from    = $body['from'] ?? $body['msisdn'] ?? $body['sender'] ?? $body['From'] ?? '';
        $message = $body['message'] ?? $body['text'] ?? $body['body'] ?? $body['Body'] ?? '';
        $to      = $body['to'] ?? $body['recipient'] ?? $body['To'] ?? '';

        if ( ! $from || ! $message ) {
            return new \WP_REST_Response( [ 'status' => 'missing_data' ], 200 );
        }

        // Log inbound message
        Log::add( [
            'provider'    => $provider_key,
            'direction'   => 'inbound',
            'recipient'   => $to,
            'sender_id'   => $from,
            'message'     => $message,
            'status'      => 'received',
            'trigger_src' => 'inbound',
        ] );

        // Check auto-reply rules
        $this->process_auto_reply( $from, $message, $provider_key );

        do_action( 'wp_sms_hub_inbound_received', $from, $message, $provider_key, $body );

        return new \WP_REST_Response( [ 'status' => 'ok' ], 200 );
    }

    /**
     * Process auto-reply rules for inbound messages.
     */
    private function process_auto_reply( string $from, string $message, string $provider ) {
        $rules = get_option( 'wpsmshub_auto_reply_rules', [] );
        if ( empty( $rules ) || ! is_array( $rules ) ) return;

        $message_lower = strtolower( trim( $message ) );

        foreach ( $rules as $rule ) {
            $keyword = strtolower( trim( $rule['keyword'] ?? '' ) );
            if ( ! $keyword ) continue;

            $match = false;
            if ( $rule['match'] === 'exact' ) {
                $match = ( $message_lower === $keyword );
            } elseif ( $rule['match'] === 'contains' ) {
                $match = ( str_contains( $message_lower, $keyword ) );
            } elseif ( $rule['match'] === 'starts' ) {
                $match = str_starts_with( $message_lower, $keyword );
            }

            if ( $match && ! empty( $rule['reply'] ) ) {
                SMS_Manager::send( $from, $rule['reply'], [
                    'provider'    => $provider ?: null,
                    'trigger_src' => 'auto_reply:' . $keyword,
                ] );

                // Handle STOP keyword - remove from contacts
                if ( $keyword === 'stop' || $keyword === 'unsubscribe' ) {
                    Contacts::delete_by_phone( $from );
                }
                break; // Only first matching rule fires
            }
        }
    }
}
