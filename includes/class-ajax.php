<?php
namespace WPSMSHub;

if ( ! defined( 'ABSPATH' ) ) exit;

class Ajax {
    public function __construct() {
        $actions = [
            'smshub_send_sms',
            'smshub_resend_sms',
            'smshub_schedule_sms',
            'smshub_cancel_scheduled',
            'smshub_save_settings',
            'smshub_test_provider',
            'smshub_get_balance',
            'smshub_get_queue_stats',
            'smshub_save_template',
            'smshub_delete_template',
            'smshub_save_trigger',
            'smshub_delete_trigger',
            'smshub_toggle_trigger',
            'smshub_add_contact',
            'smshub_edit_contact',
            'smshub_delete_contact',
            'smshub_bulk_delete_contacts',
            'smshub_export_contacts',
            'smshub_import_contacts',
            'smshub_get_analytics',
            'smshub_delete_log',
            'smshub_clear_log',
        ];
        foreach ( $actions as $action ) {
            add_action( "wp_ajax_{$action}", [ $this, $action ] );
        }
    }

    private function check( string $nonce_action = 'wp_sms_hub_nonce' ) {
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized', 403 );
        check_ajax_referer( $nonce_action, 'nonce' );
    }

    public function smshub_send_sms() {
        $this->check();
        $to      = sanitize_text_field( $_POST['to']      ?? '' );
        $message = sanitize_textarea_field( $_POST['message'] ?? '' );
        $provider = sanitize_text_field( $_POST['provider'] ?? '' );
        $sender   = sanitize_text_field( $_POST['sender_id'] ?? '' );

        if ( ! $to || ! $message ) wp_send_json_error( 'Recipient and message required.' );

        $result = SMS_Manager::send( $to, $message, [
            'provider'  => $provider ?: null,
            'sender_id' => $sender   ?: null,
            'trigger_src' => 'manual',
        ] );

        wp_send_json( $result );
    }

    public function smshub_resend_sms() {
        $this->check();
        $to       = sanitize_text_field( $_POST['to'] ?? '' );
        $message  = sanitize_textarea_field( $_POST['message'] ?? '' );
        $provider = sanitize_text_field( $_POST['provider'] ?? '' );
        if ( ! $to || ! $message ) wp_send_json_error( 'Missing recipient or message.' );
        $result = SMS_Manager::send( $to, $message, [
            'provider'    => $provider ?: null,
            'trigger_src' => 'resend',
        ] );
        wp_send_json( $result );
    }

    public function smshub_save_settings() {
        $this->check();
        $provider_key = sanitize_text_field( $_POST['active_provider'] ?? '' );
        $settings     = $_POST['provider_settings'] ?? [];
        $admin_phone  = sanitize_text_field( $_POST['admin_phone'] ?? '' );

        update_option( 'wpsmshub_active_provider', $provider_key );
        update_option( 'wpsmshub_admin_phone',     $admin_phone );

        // Retry & Failover settings
        $failover = sanitize_text_field( $_POST['failover_provider'] ?? '' );
        $retries  = (int) ( $_POST['max_retries'] ?? 3 );
        update_option( 'wpsmshub_failover_provider', $failover );
        update_option( 'wpsmshub_max_retries', max( 0, min( $retries, 5 ) ) );

        $sender_ids = sanitize_textarea_field( $_POST['sender_ids'] ?? '' );
        update_option( 'wpsmshub_sender_ids', $sender_ids );

        foreach ( $settings as $key => $fields ) {
            $clean = array_map( 'sanitize_text_field', (array) $fields );
            update_option( 'wpsmshub_provider_' . sanitize_key( $key ), $clean );
        }
        wp_send_json_success( 'Settings saved.' );
    }

    public function smshub_get_queue_stats() {
        $this->check();
        wp_send_json_success( Queue::get_stats() );
    }

    public function smshub_test_provider() {
        $this->check();
        $provider_key = sanitize_text_field( $_POST['provider'] ?? '' );
        $to           = sanitize_text_field( $_POST['test_number'] ?? '' );
        if ( ! $to ) wp_send_json_error( 'Test number required.' );

        $result = SMS_Manager::send( $to, 'WP SMS Hub test message - it works!', [
            'provider' => $provider_key,
            'trigger_src' => 'test',
        ] );
        wp_send_json( $result );
    }

    public function smshub_get_balance() {
        $this->check();
        $key      = sanitize_text_field( $_POST['provider'] ?? '' );
        $provider = SMS_Manager::get_provider( $key );
        if ( ! $provider ) wp_send_json_error( 'Provider not found.' );
        wp_send_json_success( $provider->get_balance() );
    }

    public function smshub_save_trigger() {
        $this->check();
        $id   = (int) ( $_POST['trigger_id'] ?? 0 );
        $data = [
            'name'        => sanitize_text_field( $_POST['name']        ?? '' ),
            'event'       => sanitize_text_field( $_POST['event']       ?? '' ),
            'provider'    => sanitize_text_field( $_POST['provider']    ?? '' ),
            'recipients'  => sanitize_textarea_field( $_POST['recipients']  ?? '' ),
            'sender_id'   => sanitize_text_field( $_POST['sender_id']   ?? '' ),
            'message_tpl' => sanitize_textarea_field( $_POST['message_tpl'] ?? '' ),
            'active'      => (int) ( $_POST['active'] ?? 1 ),
        ];
        if ( $id ) {
            $ok = Triggers::update( $id, $data );
        } else {
            $ok = Triggers::create( $data );
        }
        $ok ? wp_send_json_success( [ 'id' => $ok ] ) : wp_send_json_error( 'Failed to save trigger.' );
    }

    public function smshub_delete_trigger() {
        $this->check();
        $id = (int) ( $_POST['id'] ?? 0 );
        Triggers::delete_rule( $id ) ? wp_send_json_success() : wp_send_json_error( 'Failed.' );
    }

    public function smshub_toggle_trigger() {
        $this->check();
        global $wpdb;
        $id     = (int) ( $_POST['id'] ?? 0 );
        $active = (int) ( $_POST['active'] ?? 0 );
        $wpdb->update( "{$wpdb->prefix}smshub_triggers", [ 'active' => $active ], [ 'id' => $id ] );
        wp_send_json_success();
    }

    public function smshub_add_contact() {
        $this->check();
        $res = Contacts::add([
            'name'  => sanitize_text_field( $_POST['name']  ?? '' ),
            'phone' => sanitize_text_field( $_POST['phone'] ?? '' ),
            'group' => sanitize_text_field( $_POST['group'] ?? 'Default' ),
        ]);
        $res ? wp_send_json_success( [ 'id' => $res ] ) : wp_send_json_error( 'Duplicate phone or error.' );
    }

    public function smshub_delete_contact() {
        $this->check();
        $id = (int) ( $_POST['id'] ?? 0 );
        Contacts::delete( $id ) ? wp_send_json_success() : wp_send_json_error( 'Failed.' );
    }

    public function smshub_import_contacts() {
        $this->check();
        if ( empty( $_FILES['csv_file']['tmp_name'] ) ) wp_send_json_error( 'No file uploaded.' );
        $result = Contacts::import_csv( $_FILES['csv_file']['tmp_name'] );
        wp_send_json_success( $result );
    }

    public function smshub_delete_log() {
        $this->check();
        $id = (int) ( $_POST['id'] ?? 0 );
        global $wpdb;
        $wpdb->delete( "{$wpdb->prefix}smshub_log", [ 'id' => $id ] );
        wp_send_json_success();
    }

    public function smshub_clear_log() {
        $this->check();
        global $wpdb;
        $wpdb->query( "TRUNCATE {$wpdb->prefix}smshub_log" );
        wp_send_json_success();
    }

    // ── Schedule SMS ────────────────────────────────────────────────────
    public function smshub_schedule_sms() {
        $this->check();
        $to      = sanitize_text_field( $_POST['to'] ?? '' );
        $message = sanitize_textarea_field( $_POST['message'] ?? '' );
        $date    = sanitize_text_field( $_POST['scheduled_at'] ?? '' );
        $provider = sanitize_text_field( $_POST['provider'] ?? '' );
        $sender   = sanitize_text_field( $_POST['sender_id'] ?? '' );

        if ( ! $to || ! $message || ! $date ) wp_send_json_error( 'Recipient, message, and date required.' );

        $recipients = array_filter( array_map( 'trim', explode( ',', $to ) ) );
        $ids = [];
        foreach ( $recipients as $number ) {
            $ids[] = Queue::schedule( $number, $message, $date, [
                'provider'  => $provider ?: null,
                'sender_id' => $sender ?: null,
            ] );
        }
        wp_send_json_success( [ 'scheduled' => count( $ids ), 'ids' => $ids ] );
    }

    public function smshub_cancel_scheduled() {
        $this->check();
        $id = (int) ( $_POST['id'] ?? 0 );
        Queue::cancel( $id ) ? wp_send_json_success() : wp_send_json_error( 'Cannot cancel (already sent or not found).' );
    }

    // ── Templates ───────────────────────────────────────────────────────
    public function smshub_save_template() {
        $this->check();
        $id   = (int) ( $_POST['template_id'] ?? 0 );
        $data = [
            'name'     => sanitize_text_field( $_POST['name'] ?? '' ),
            'category' => sanitize_text_field( $_POST['category'] ?? 'General' ),
            'body'     => sanitize_textarea_field( $_POST['body'] ?? '' ),
        ];
        if ( ! $data['name'] || ! $data['body'] ) wp_send_json_error( 'Name and body required.' );
        if ( $id ) {
            $ok = Templates::update( $id, $data );
        } else {
            $ok = Templates::create( $data );
        }
        $ok ? wp_send_json_success( [ 'id' => $ok ] ) : wp_send_json_error( 'Failed to save template.' );
    }

    public function smshub_delete_template() {
        $this->check();
        $id = (int) ( $_POST['id'] ?? 0 );
        Templates::delete( $id ) ? wp_send_json_success() : wp_send_json_error( 'Failed.' );
    }

    // ── Contact edit & bulk ─────────────────────────────────────────────
    public function smshub_edit_contact() {
        $this->check();
        $id = (int) ( $_POST['id'] ?? 0 );
        if ( ! $id ) wp_send_json_error( 'Invalid contact.' );
        global $wpdb;
        $wpdb->update( $wpdb->prefix . 'smshub_contacts', [
            'name'       => sanitize_text_field( $_POST['name'] ?? '' ),
            'phone'      => sanitize_text_field( $_POST['phone'] ?? '' ),
            'group_name' => sanitize_text_field( $_POST['group'] ?? 'Default' ),
        ], [ 'id' => $id ] );
        wp_send_json_success();
    }

    public function smshub_bulk_delete_contacts() {
        $this->check();
        $ids = array_map( 'intval', (array) ( $_POST['ids'] ?? [] ) );
        if ( empty( $ids ) ) wp_send_json_error( 'No contacts selected.' );
        global $wpdb;
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}smshub_contacts WHERE id IN ({$placeholders})", ...$ids
        ) );
        wp_send_json_success( [ 'deleted' => $wpdb->rows_affected ] );
    }

    public function smshub_export_contacts() {
        $this->check();
        $group = sanitize_text_field( $_POST['group'] ?? '' );
        $data  = Contacts::get_list( [ 'per_page' => 99999, 'group' => $group ] );
        $rows  = [];
        $rows[] = [ 'name', 'phone', 'group' ];
        foreach ( $data['items'] as $c ) {
            $rows[] = [ $c['name'], $c['phone'], $c['group_name'] ];
        }
        wp_send_json_success( [ 'csv' => $rows, 'count' => count( $data['items'] ) ] );
    }

    // ── Analytics ───────────────────────────────────────────────────────
    public function smshub_get_analytics() {
        $this->check();
        global $wpdb;
        $table = $wpdb->prefix . 'smshub_log';
        $days  = (int) ( $_POST['days'] ?? 30 );
        $days  = max( 7, min( $days, 90 ) );

        // Messages per day for the last N days
        $daily = $wpdb->get_results( $wpdb->prepare(
            "SELECT DATE(created_at) as date,
                    SUM(CASE WHEN status='sent' OR status='delivered' THEN 1 ELSE 0 END) as sent,
                    SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END) as failed
             FROM {$table}
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
             GROUP BY DATE(created_at)
             ORDER BY date ASC",
            $days
        ), ARRAY_A );

        // Provider breakdown
        $by_provider = $wpdb->get_results(
            "SELECT provider, COUNT(*) as total
             FROM {$table}
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
             GROUP BY provider
             ORDER BY total DESC",
            ARRAY_A
        );

        // Cost per provider (last 30 days)
        $costs = $wpdb->get_results(
            "SELECT provider, SUM(cost) as total_cost
             FROM {$table}
             WHERE cost IS NOT NULL AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
             GROUP BY provider",
            ARRAY_A
        );

        wp_send_json_success( [
            'daily'       => $daily,
            'by_provider' => $by_provider,
            'costs'       => $costs,
        ] );
    }
}
