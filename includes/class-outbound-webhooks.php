<?php
namespace WPSMSHub;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Outbound Webhooks - Send events to Zapier, Make, or any webhook URL.
 * Fires on: message_sent, message_failed, message_delivered, inbound_received.
 */
class Outbound_Webhooks {

    public function __construct() {
        add_action( 'wp_sms_hub_after_send', [ $this, 'on_send' ], 20, 3 );
        add_action( 'wp_sms_hub_delivery_update', [ $this, 'on_delivery' ], 10, 3 );
        add_action( 'wp_sms_hub_inbound_received', [ $this, 'on_inbound' ], 10, 4 );
    }

    /**
     * Fire webhook on message sent/failed.
     */
    public function on_send( array $results, string $message, array $args ) {
        foreach ( $results as $r ) {
            $event = $r['success'] ? 'message_sent' : 'message_failed';
            self::dispatch( $event, [
                'event'      => $event,
                'recipient'  => $r['recipient'] ?? '',
                'message'    => $message,
                'provider'   => $args['provider'] ?? get_option( 'wpsmshub_active_provider' ),
                'message_id' => $r['message_id'] ?? null,
                'error'      => $r['error'] ?? null,
                'trigger'    => $args['trigger_src'] ?? '',
                'timestamp'  => current_time( 'c' ),
                'site'       => get_bloginfo( 'url' ),
            ] );
        }
    }

    /**
     * Fire webhook on delivery status update.
     */
    public function on_delivery( int $log_id, string $status, array $parsed ) {
        self::dispatch( 'message_delivered', [
            'event'       => 'message_' . $status,
            'log_id'      => $log_id,
            'status'      => $status,
            'provider_id' => $parsed['provider_id'] ?? '',
            'raw_status'  => $parsed['raw_status'] ?? '',
            'timestamp'   => current_time( 'c' ),
            'site'        => get_bloginfo( 'url' ),
        ] );
    }

    /**
     * Fire webhook on inbound message received.
     */
    public function on_inbound( string $from, string $message, string $provider, array $raw ) {
        self::dispatch( 'inbound_received', [
            'event'     => 'inbound_received',
            'from'      => $from,
            'message'   => $message,
            'provider'  => $provider,
            'timestamp' => current_time( 'c' ),
            'site'      => get_bloginfo( 'url' ),
        ] );
    }

    /**
     * Dispatch payload to all configured webhook URLs.
     */
    public static function dispatch( string $event, array $payload ) {
        $webhooks = self::get_webhooks();
        if ( empty( $webhooks ) ) return;

        foreach ( $webhooks as $wh ) {
            // Check if this webhook listens to this event
            $events = $wh['events'] ?? [];
            if ( ! empty( $events ) && ! in_array( $event, $events ) && ! in_array( 'all', $events ) ) {
                continue;
            }

            if ( empty( $wh['url'] ) ) continue;

            // Non-blocking send
            wp_remote_post( $wh['url'], [
                'timeout'   => 5,
                'blocking'  => false,
                'headers'   => [
                    'Content-Type'    => 'application/json',
                    'User-Agent'      => 'WP-SMS-Hub/1.0',
                    'X-Webhook-Event' => $event,
                ],
                'body'      => wp_json_encode( $payload ),
            ] );
        }
    }

    /**
     * Get configured outbound webhooks.
     * Format: [ { url, events: ['all'] or ['message_sent','message_failed'], name } ]
     */
    public static function get_webhooks(): array {
        $raw = get_option( 'wpsmshub_outbound_webhooks', [] );
        return is_array( $raw ) ? $raw : [];
    }

    /**
     * Save outbound webhooks.
     */
    public static function save_webhooks( array $webhooks ): bool {
        $clean = [];
        foreach ( $webhooks as $wh ) {
            if ( empty( $wh['url'] ) ) continue;
            $clean[] = [
                'name'   => sanitize_text_field( $wh['name'] ?? 'Webhook' ),
                'url'    => esc_url_raw( $wh['url'] ),
                'events' => array_map( 'sanitize_text_field', (array) ( $wh['events'] ?? [ 'all' ] ) ),
            ];
        }
        return update_option( 'wpsmshub_outbound_webhooks', $clean );
    }

    /**
     * Add a single webhook URL.
     */
    public static function add_webhook( string $url, string $name = 'Webhook', array $events = [ 'all' ] ): bool {
        $webhooks = self::get_webhooks();
        $webhooks[] = [
            'name'   => sanitize_text_field( $name ),
            'url'    => esc_url_raw( $url ),
            'events' => $events,
        ];
        return self::save_webhooks( $webhooks );
    }

    /**
     * Remove a webhook by index.
     */
    public static function remove_webhook( int $index ): bool {
        $webhooks = self::get_webhooks();
        if ( ! isset( $webhooks[ $index ] ) ) return false;
        array_splice( $webhooks, $index, 1 );
        return self::save_webhooks( $webhooks );
    }
}
