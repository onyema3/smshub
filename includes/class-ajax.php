<?php
namespace WPSMSHub;

if ( ! defined( 'ABSPATH' ) ) exit;

class Ajax {
    public function __construct() {
        $actions = [
            'smshub_send_sms',
            'smshub_resend_sms',
            'smshub_save_settings',
            'smshub_test_provider',
            'smshub_get_balance',
            'smshub_get_queue_stats',
            'smshub_save_trigger',
            'smshub_delete_trigger',
            'smshub_toggle_trigger',
            'smshub_add_contact',
            'smshub_delete_contact',
            'smshub_import_contacts',
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
}
