<?php
namespace WPSMSHub;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Audit Trail - Logs every admin action for compliance and accountability.
 */
class Audit {

    public function __construct() {
        // Hook into settings save
        add_action( 'wp_ajax_smshub_save_settings', [ $this, 'log_settings_change' ], 1 );
        add_action( 'wp_ajax_smshub_delete_contact', [ $this, 'log_contact_delete' ], 1 );
        add_action( 'wp_ajax_smshub_bulk_delete_contacts', [ $this, 'log_bulk_delete' ], 1 );
        add_action( 'wp_ajax_smshub_clear_log', [ $this, 'log_clear_log' ], 1 );
        add_action( 'wp_ajax_smshub_create_sub_account', [ $this, 'log_sub_account_create' ], 1 );
        add_action( 'wp_ajax_smshub_delete_sub_account', [ $this, 'log_sub_account_delete' ], 1 );
        add_action( 'wp_ajax_smshub_start_campaign', [ $this, 'log_campaign_start' ], 1 );
    }

    private static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'smshub_audit';
    }

    public static function log( string $action, string $details = '', ?int $user_id = null ): int {
        global $wpdb;
        $wpdb->insert( self::table(), [
            'user_id'    => $user_id ?? get_current_user_id(),
            'action'     => $action,
            'details'    => $details,
            'ip_address' => self::get_ip(),
            'created_at' => current_time( 'mysql' ),
        ] );
        return (int) $wpdb->insert_id;
    }

    public static function get_log( array $args = [] ): array {
        global $wpdb;
        $table  = self::table();
        $limit  = (int) ( $args['per_page'] ?? 50 );
        $offset = (int) ( $args['offset'] ?? 0 );
        $where  = '1=1';
        $values = [];

        if ( ! empty( $args['action'] ) ) {
            $where .= ' AND action = %s';
            $values[] = $args['action'];
        }
        if ( ! empty( $args['user_id'] ) ) {
            $where .= ' AND user_id = %d';
            $values[] = (int) $args['user_id'];
        }

        $values[] = $limit;
        $values[] = $offset;

        $sql = "SELECT * FROM {$table} WHERE {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d";
        $sql = $wpdb->prepare( $sql, $values );

        return [
            'items' => $wpdb->get_results( $sql, ARRAY_A ),
            'total' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE " . explode( ' ORDER', $where )[0], array_slice( $values, 0, -2 ) ?: [ 1 ] ) ),
        ];
    }

    public static function export(): array {
        global $wpdb;
        return $wpdb->get_results( "SELECT * FROM " . self::table() . " ORDER BY created_at DESC LIMIT 10000", ARRAY_A );
    }

    private static function get_ip(): string {
        $headers = [ 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR' ];
        foreach ( $headers as $h ) {
            if ( ! empty( $_SERVER[ $h ] ) ) {
                return sanitize_text_field( explode( ',', $_SERVER[ $h ] )[0] );
            }
        }
        return '0.0.0.0';
    }

    // ── Auto-logging hooks ──────────────────────────────────────────────
    public function log_settings_change() {
        self::log( 'settings_updated', 'Provider: ' . sanitize_text_field( $_POST['active_provider'] ?? '' ) );
    }
    public function log_contact_delete() {
        self::log( 'contact_deleted', 'ID: ' . (int) ( $_POST['id'] ?? 0 ) );
    }
    public function log_bulk_delete() {
        $ids = (array) ( $_POST['ids'] ?? [] );
        self::log( 'contacts_bulk_deleted', 'Count: ' . count( $ids ) );
    }
    public function log_clear_log() {
        self::log( 'sms_log_cleared', 'All SMS log entries removed' );
    }
    public function log_sub_account_create() {
        self::log( 'sub_account_created', 'Name: ' . sanitize_text_field( $_POST['name'] ?? '' ) );
    }
    public function log_sub_account_delete() {
        self::log( 'sub_account_deleted', 'ID: ' . (int) ( $_POST['id'] ?? 0 ) );
    }
    public function log_campaign_start() {
        self::log( 'campaign_started', 'ID: ' . (int) ( $_POST['id'] ?? 0 ) );
    }
}
