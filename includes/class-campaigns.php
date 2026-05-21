<?php
namespace WPSMSHub;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Campaign Manager — bulk SMS campaigns with audience targeting.
 */
class Campaigns {

    const CRON_HOOK = 'smshub_update_campaign_stats';

    private static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'smshub_campaigns';
    }

    public function __construct() {
        add_action( self::CRON_HOOK, [ $this, 'update_all_running_stats' ] );

        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time(), 'smshub_every_minute', self::CRON_HOOK );
        }
    }

    /**
     * Create a new campaign.
     */
    public static function create( array $data ): int|false {
        global $wpdb;
        $res = $wpdb->insert( self::table(), [
            'name'             => sanitize_text_field( $data['name'] ?? '' ),
            'message'          => sanitize_textarea_field( $data['message'] ?? '' ),
            'provider'         => sanitize_text_field( $data['provider'] ?? '' ),
            'sender_id'        => sanitize_text_field( $data['sender_id'] ?? '' ),
            'audience_type'    => sanitize_text_field( $data['audience_type'] ?? 'numbers' ),
            'audience_value'   => sanitize_textarea_field( $data['audience_value'] ?? '' ),
            'status'           => 'draft',
            'total_recipients' => 0,
            'sent_count'       => 0,
            'failed_count'     => 0,
            'scheduled_at'     => ! empty( $data['scheduled_at'] ) ? sanitize_text_field( $data['scheduled_at'] ) : null,
            'started_at'       => null,
            'completed_at'     => null,
            'created_at'       => current_time( 'mysql' ),
        ] );
        return $res ? (int) $wpdb->insert_id : false;
    }

    /**
     * Update a campaign (only if draft).
     */
    public static function update( int $id, array $data ): bool {
        global $wpdb;
        $allowed = [ 'name', 'message', 'provider', 'sender_id', 'audience_type', 'audience_value', 'scheduled_at' ];
        $update  = [];
        foreach ( $allowed as $key ) {
            if ( isset( $data[ $key ] ) ) {
                $update[ $key ] = sanitize_text_field( $data[ $key ] );
            }
        }
        if ( isset( $data['message'] ) ) {
            $update['message'] = sanitize_textarea_field( $data['message'] );
        }
        if ( isset( $data['audience_value'] ) ) {
            $update['audience_value'] = sanitize_textarea_field( $data['audience_value'] );
        }
        if ( empty( $update ) ) return false;
        return (bool) $wpdb->update( self::table(), $update, [ 'id' => $id ] );
    }

    /**
     * Delete a campaign.
     */
    public static function delete( int $id ): bool {
        global $wpdb;
        return (bool) $wpdb->delete( self::table(), [ 'id' => $id ], [ '%d' ] );
    }

    /**
     * Get a single campaign.
     */
    public static function get( int $id ): ?array {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE id = %d", $id
        ), ARRAY_A );
        return $row ?: null;
    }

    /**
     * Get all campaigns.
     */
    public static function get_all(): array {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT * FROM " . self::table() . " ORDER BY created_at DESC",
            ARRAY_A
        );
    }

    /**
     * Start a campaign — resolve audience and enqueue all messages.
     */
    public static function start( int $id ): array {
        global $wpdb;
        $campaign = self::get( $id );
        if ( ! $campaign ) return [ 'success' => false, 'error' => 'Campaign not found' ];
        if ( ! in_array( $campaign['status'], [ 'draft', 'paused' ], true ) ) {
            return [ 'success' => false, 'error' => 'Campaign cannot be started (status: ' . $campaign['status'] . ')' ];
        }

        // Resolve audience
        $recipients = self::resolve_audience( $campaign['audience_type'], $campaign['audience_value'] );
        if ( empty( $recipients ) ) {
            return [ 'success' => false, 'error' => 'No recipients found for audience' ];
        }

        // Process message for link shortening
        $message = $campaign['message'];
        if ( class_exists( __NAMESPACE__ . '\\Link_Tracker' ) ) {
            $message = Link_Tracker::process_message( $message, $id );
        }

        // Queue all messages
        $trigger_src = 'campaign:' . $id;
        Queue::enqueue_bulk( $recipients, $message, [
            'provider'    => $campaign['provider'] ?: null,
            'sender_id'   => $campaign['sender_id'] ?: null,
            'trigger_src' => $trigger_src,
        ] );

        // Update campaign status
        $wpdb->update( self::table(), [
            'status'           => 'running',
            'total_recipients' => count( $recipients ),
            'started_at'       => current_time( 'mysql' ),
        ], [ 'id' => $id ] );

        return [ 'success' => true, 'recipients' => count( $recipients ) ];
    }

    /**
     * Pause a running campaign (cancels remaining queued messages).
     */
    public static function pause( int $id ): bool {
        global $wpdb;
        $campaign = self::get( $id );
        if ( ! $campaign || $campaign['status'] !== 'running' ) return false;

        // Cancel remaining queued messages
        $trigger_src = 'campaign:' . $id;
        $wpdb->update( $wpdb->prefix . 'smshub_queue', [
            'status' => 'cancelled',
        ], [
            'trigger_src' => $trigger_src,
            'status'      => 'queued',
        ] );

        $wpdb->update( self::table(), [ 'status' => 'paused' ], [ 'id' => $id ] );
        return true;
    }

    /**
     * Resume a paused campaign — re-queue cancelled messages.
     */
    public static function resume( int $id ): array {
        return self::start( $id );
    }

    /**
     * Get campaign stats from the queue.
     */
    public static function get_stats( int $id ): array {
        global $wpdb;
        $trigger_src = 'campaign:' . $id;
        $queue_table = $wpdb->prefix . 'smshub_queue';

        $total = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$queue_table} WHERE trigger_src = %s", $trigger_src
        ) );
        $sent = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$queue_table} WHERE trigger_src = %s AND status = 'sent'", $trigger_src
        ) );
        $failed = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$queue_table} WHERE trigger_src = %s AND status = 'failed'", $trigger_src
        ) );
        $pending = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$queue_table} WHERE trigger_src = %s AND status IN ('queued','retry','processing')", $trigger_src
        ) );

        return [
            'total'   => $total,
            'sent'    => $sent,
            'failed'  => $failed,
            'pending' => $pending,
            'percent' => $total ? round( ( $sent + $failed ) / $total * 100 ) : 0,
        ];
    }

    /**
     * Update stats for all running campaigns (cron callback).
     */
    public function update_all_running_stats(): void {
        global $wpdb;
        $running = $wpdb->get_results(
            "SELECT id FROM " . self::table() . " WHERE status = 'running'",
            ARRAY_A
        );

        foreach ( $running as $row ) {
            $stats = self::get_stats( (int) $row['id'] );
            $update = [
                'sent_count'   => $stats['sent'],
                'failed_count' => $stats['failed'],
            ];

            // Mark completed if no pending messages remain
            if ( $stats['pending'] === 0 && $stats['total'] > 0 ) {
                $update['status']       = 'completed';
                $update['completed_at'] = current_time( 'mysql' );
            }

            $wpdb->update( self::table(), $update, [ 'id' => (int) $row['id'] ] );
        }
    }

    /**
     * Resolve audience to an array of phone numbers.
     */
    private static function resolve_audience( string $type, string $value ): array {
        switch ( $type ) {
            case 'group':
                return Contacts::get_phones_by_group( $value );
            case 'all':
                global $wpdb;
                return $wpdb->get_col( "SELECT phone FROM {$wpdb->prefix}smshub_contacts" );
            case 'numbers':
            default:
                return array_filter( array_map( 'trim', explode( ',', $value ) ) );
        }
    }
}
