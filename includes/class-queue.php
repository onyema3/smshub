<?php
namespace WPSMSHub;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * SMS Queue - Background processing for bulk sends.
 * Uses WP Cron to process messages in batches.
 */
class Queue {
    const BATCH_SIZE = 20;
    const CRON_HOOK  = 'smshub_process_queue';

    public function __construct() {
        add_action( self::CRON_HOOK, [ $this, 'process_batch' ] );

        // Schedule recurring cron if not already scheduled
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time(), 'smshub_every_minute', self::CRON_HOOK );
        }

        // Register custom cron interval
        add_filter( 'cron_schedules', [ $this, 'add_cron_interval' ] );
    }

    public function add_cron_interval( array $schedules ): array {
        $schedules['smshub_every_minute'] = [
            'interval' => 60,
            'display'  => __( 'Every Minute (SMS Hub)', 'wp-sms-hub' ),
        ];
        return $schedules;
    }

    /**
     * Enqueue a message for background sending.
     */
    public static function enqueue( string $to, string $message, array $args = [] ): int {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'smshub_queue', [
            'recipient'    => $to,
            'message'      => $message,
            'provider'     => $args['provider'] ?? '',
            'sender_id'    => $args['sender_id'] ?? '',
            'trigger_src'  => $args['trigger_src'] ?? '',
            'status'       => 'queued',
            'attempts'     => 0,
            'max_attempts' => (int) ( $args['max_attempts'] ?? 3 ),
            'scheduled_at' => $args['scheduled_at'] ?? current_time( 'mysql' ),
            'created_at'   => current_time( 'mysql' ),
        ] );
        return (int) $wpdb->insert_id;
    }

    /**
     * Enqueue multiple recipients (bulk send).
     * Returns array of queue IDs.
     */
    public static function enqueue_bulk( array $recipients, string $message, array $args = [] ): array {
        $ids = [];
        foreach ( $recipients as $to ) {
            $ids[] = self::enqueue( SMS_Manager::normalize_phone( $to ), $message, $args );
        }
        return $ids;
    }

    /**
     * Process a batch of queued messages via WP Cron.
     */
    public function process_batch() {
        global $wpdb;
        $table = $wpdb->prefix . 'smshub_queue';
        $now   = current_time( 'mysql' );

        // Get next batch of messages ready to send
        $items = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE status IN ('queued', 'retry')
             AND scheduled_at <= %s
             AND attempts < max_attempts
             ORDER BY created_at ASC
             LIMIT %d",
            $now, self::BATCH_SIZE
        ), ARRAY_A );

        if ( empty( $items ) ) return;

        foreach ( $items as $item ) {
            // Mark as processing
            $wpdb->update( $table, [ 'status' => 'processing' ], [ 'id' => $item['id'] ] );

            // Send via SMS_Manager (which handles retry/failover internally)
            $result = SMS_Manager::send_single(
                $item['recipient'],
                $item['message'],
                [
                    'provider'    => $item['provider'] ?: null,
                    'sender_id'   => $item['sender_id'] ?: null,
                    'trigger_src' => $item['trigger_src'] ?: 'queue',
                ]
            );

            $attempts = (int) $item['attempts'] + 1;

            if ( $result['success'] ) {
                $wpdb->update( $table, [
                    'status'     => 'sent',
                    'attempts'   => $attempts,
                    'sent_at'    => current_time( 'mysql' ),
                ], [ 'id' => $item['id'] ] );
            } else {
                // Check if we should retry
                if ( $attempts < (int) $item['max_attempts'] ) {
                    // Exponential backoff: 30s, 2min, 8min
                    $delay = pow( 4, $attempts ) * 30;
                    $next  = gmdate( 'Y-m-d H:i:s', time() + $delay );
                    $wpdb->update( $table, [
                        'status'       => 'retry',
                        'attempts'     => $attempts,
                        'scheduled_at' => $next,
                        'last_error'   => $result['error'] ?? 'Unknown',
                    ], [ 'id' => $item['id'] ] );
                } else {
                    $wpdb->update( $table, [
                        'status'     => 'failed',
                        'attempts'   => $attempts,
                        'last_error' => $result['error'] ?? 'Max retries reached',
                    ], [ 'id' => $item['id'] ] );
                }
            }
        }
    }

    /**
     * Get queue stats for dashboard.
     */
    public static function get_stats(): array {
        global $wpdb;
        $table = $wpdb->prefix . 'smshub_queue';
        return [
            'queued'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status='queued'" ),
            'processing' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status='processing'" ),
            'retry'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status='retry'" ),
            'sent'       => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status='sent'" ),
            'failed'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status='failed'" ),
        ];
    }

    /**
     * Get progress for a batch (by trigger_src).
     */
    public static function get_batch_progress( string $trigger_src ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'smshub_queue';
        $total = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE trigger_src = %s", $trigger_src
        ) );
        $done = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE trigger_src = %s AND status IN ('sent','failed')", $trigger_src
        ) );
        return [ 'total' => $total, 'completed' => $done, 'percent' => $total ? round( $done / $total * 100 ) : 0 ];
    }

    /**
     * Schedule a message for future delivery.
     */
    public static function schedule( string $to, string $message, string $scheduled_at, array $args = [] ): int {
        $args['scheduled_at'] = $scheduled_at;
        $args['trigger_src']  = $args['trigger_src'] ?? 'scheduled';
        return self::enqueue( SMS_Manager::normalize_phone( $to ), $message, $args );
    }

    /**
     * Get upcoming scheduled messages (not yet sent).
     */
    public static function get_scheduled( int $limit = 50 ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'smshub_queue';
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE status IN ('queued','retry') AND trigger_src = 'scheduled' ORDER BY scheduled_at ASC LIMIT %d",
            $limit
        ), ARRAY_A );
    }

    /**
     * Cancel a scheduled message.
     */
    public static function cancel( int $id ): bool {
        global $wpdb;
        return (bool) $wpdb->delete( $wpdb->prefix . 'smshub_queue', [ 'id' => $id, 'status' => 'queued' ], [ '%d', '%s' ] );
    }

    /**
     * Clean up old completed queue entries.
     */
    public static function cleanup( int $days = 7 ): int {
        global $wpdb;
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}smshub_queue WHERE status IN ('sent','failed') AND created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $days
        ) );
        return $wpdb->rows_affected;
    }
}
