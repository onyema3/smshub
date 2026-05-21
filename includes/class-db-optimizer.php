<?php
namespace WPSMSHub;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Database Optimization - Archiving, cleanup, and index management.
 */
class DB_Optimizer {
    const ARCHIVE_CRON = 'smshub_db_archive';

    public function __construct() {
        add_action( self::ARCHIVE_CRON, [ $this, 'run_maintenance' ] );
        if ( ! wp_next_scheduled( self::ARCHIVE_CRON ) ) {
            wp_schedule_event( time(), 'daily', self::ARCHIVE_CRON );
        }
    }

    /**
     * Run all maintenance tasks.
     */
    public function run_maintenance() {
        $this->archive_old_logs();
        $this->cleanup_queue();
        $this->optimize_tables();
    }

    /**
     * Archive logs older than configured days to archive table.
     */
    public function archive_old_logs() {
        $days = (int) get_option( 'wpsmshub_archive_after_days', 90 );
        if ( $days <= 0 ) return;

        global $wpdb;
        $log_table     = $wpdb->prefix . 'smshub_log';
        $archive_table = $wpdb->prefix . 'smshub_log_archive';

        // Create archive table if not exists (same structure as log)
        $wpdb->query( "CREATE TABLE IF NOT EXISTS {$archive_table} LIKE {$log_table}" );

        // Move old records to archive
        $cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
        $moved = $wpdb->query( $wpdb->prepare(
            "INSERT INTO {$archive_table} SELECT * FROM {$log_table} WHERE created_at < %s",
            $cutoff
        ) );

        if ( $moved ) {
            $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$log_table} WHERE created_at < %s",
                $cutoff
            ) );
            Audit::log( 'db_archive', "Archived {$moved} log entries older than {$days} days" );
        }
    }

    /**
     * Clean up completed/failed queue entries.
     */
    public function cleanup_queue() {
        global $wpdb;
        $table = $wpdb->prefix . 'smshub_queue';
        $deleted = $wpdb->query(
            "DELETE FROM {$table} WHERE status IN ('sent','failed') AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)"
        );
        if ( $deleted ) {
            Audit::log( 'db_cleanup', "Removed {$deleted} old queue entries" );
        }
    }

    /**
     * Optimize tables for better performance.
     */
    public function optimize_tables() {
        global $wpdb;
        $tables = [
            $wpdb->prefix . 'smshub_log',
            $wpdb->prefix . 'smshub_queue',
            $wpdb->prefix . 'smshub_contacts',
        ];
        foreach ( $tables as $table ) {
            $wpdb->query( "OPTIMIZE TABLE {$table}" );
        }
    }

    /**
     * Get database stats for admin display.
     */
    public static function get_db_stats(): array {
        global $wpdb;
        $tables = [
            'log'       => $wpdb->prefix . 'smshub_log',
            'contacts'  => $wpdb->prefix . 'smshub_contacts',
            'queue'     => $wpdb->prefix . 'smshub_queue',
            'templates' => $wpdb->prefix . 'smshub_templates',
            'triggers'  => $wpdb->prefix . 'smshub_triggers',
        ];

        $stats = [];
        foreach ( $tables as $key => $table ) {
            $row = $wpdb->get_row( "SELECT COUNT(*) as rows_count, ROUND((DATA_LENGTH + INDEX_LENGTH) / 1024 / 1024, 2) as size_mb FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$table}'" );
            $stats[ $key ] = [
                'rows' => (int) ( $row->rows_count ?? 0 ),
                'size' => (float) ( $row->size_mb ?? 0 ),
            ];
        }

        // Archive stats
        $archive_table = $wpdb->prefix . 'smshub_log_archive';
        $archive_exists = $wpdb->get_var( "SHOW TABLES LIKE '{$archive_table}'" );
        if ( $archive_exists ) {
            $stats['archive'] = [
                'rows' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$archive_table}" ),
            ];
        }

        return $stats;
    }

    /**
     * Manual trigger for admin.
     */
    public static function run_now(): array {
        $optimizer = new self();
        $optimizer->run_maintenance();
        return [ 'status' => 'completed', 'stats' => self::get_db_stats() ];
    }
}
