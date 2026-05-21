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
            'smshub_create_sub_account',
            'smshub_delete_sub_account',
            'smshub_toggle_sub_account',
            'smshub_save_campaign',
            'smshub_start_campaign',
            'smshub_pause_campaign',
            'smshub_delete_campaign',
            'smshub_get_campaign_stats',
            'smshub_ai_suggest',
            'smshub_save_workflow',
            'smshub_delete_workflow',
            'smshub_toggle_workflow',
            'smshub_get_audit_log',
            'smshub_export_personal_data',
            'smshub_erase_personal_data',
            'smshub_get_live_feed',
            'smshub_get_provider_health',
            'smshub_get_reports',
            'smshub_save_outbound_webhook',
            'smshub_delete_outbound_webhook',
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

        $ip_whitelist = sanitize_textarea_field( $_POST['ip_whitelist'] ?? '' );
        update_option( 'wpsmshub_ip_whitelist', $ip_whitelist );
        $auto_purge_days = (int) ( $_POST['auto_purge_days'] ?? 0 );
        update_option( 'wpsmshub_auto_purge_days', max( 0, $auto_purge_days ) );

        $weekly_digest = sanitize_text_field( $_POST['weekly_digest'] ?? 'no' );
        update_option( 'wpsmshub_weekly_digest_enabled', $weekly_digest === 'yes' ? 'yes' : 'no' );
        $digest_email = sanitize_email( $_POST['digest_email'] ?? '' );
        if ( $digest_email ) update_option( 'wpsmshub_digest_email', $digest_email );

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

    // ── Sub-Accounts ────────────────────────────────────────────────────
    public function smshub_create_sub_account() {
        $this->check();
        $name          = sanitize_text_field( $_POST['name'] ?? '' );
        $daily_limit   = (int) ( $_POST['daily_limit'] ?? 100 );
        $monthly_limit = (int) ( $_POST['monthly_limit'] ?? 3000 );

        if ( ! $name ) wp_send_json_error( 'Name is required.' );

        $id = Sub_Accounts::create([
            'name'          => $name,
            'daily_limit'   => $daily_limit,
            'monthly_limit' => $monthly_limit,
        ]);

        if ( $id ) {
            $account = Sub_Accounts::get( $id );
            wp_send_json_success( $account );
        }
        wp_send_json_error( 'Failed to create sub-account.' );
    }

    public function smshub_delete_sub_account() {
        $this->check();
        $id = (int) ( $_POST['id'] ?? 0 );
        Sub_Accounts::delete( $id ) ? wp_send_json_success() : wp_send_json_error( 'Failed.' );
    }

    public function smshub_toggle_sub_account() {
        $this->check();
        $id     = (int) ( $_POST['id'] ?? 0 );
        $active = (int) ( $_POST['active'] ?? 0 );
        $status = $active ? 'active' : 'suspended';
        Sub_Accounts::update( $id, [ 'status' => $status ] ) ? wp_send_json_success() : wp_send_json_error( 'Failed.' );
    }

    // ── Campaigns ───────────────────────────────────────────────────────
    public function smshub_save_campaign() {
        $this->check();
        $id   = (int) ( $_POST['campaign_id'] ?? 0 );
        $data = [
            'name'           => sanitize_text_field( $_POST['name'] ?? '' ),
            'message'        => sanitize_textarea_field( $_POST['message'] ?? '' ),
            'audience_type'  => sanitize_text_field( $_POST['audience_type'] ?? 'numbers' ),
            'audience_value' => sanitize_textarea_field( $_POST['audience_value'] ?? '' ),
            'provider'       => sanitize_text_field( $_POST['provider'] ?? '' ),
            'sender_id'      => sanitize_text_field( $_POST['sender_id'] ?? '' ),
            'scheduled_at'   => sanitize_text_field( $_POST['scheduled_at'] ?? '' ),
        ];

        if ( ! $data['name'] || ! $data['message'] ) wp_send_json_error( 'Name and message required.' );

        if ( $id ) {
            $ok = Campaigns::update( $id, $data );
        } else {
            $ok = Campaigns::create( $data );
        }
        $ok ? wp_send_json_success( [ 'id' => $ok ] ) : wp_send_json_error( 'Failed to save campaign.' );
    }

    public function smshub_start_campaign() {
        $this->check();
        $id     = (int) ( $_POST['id'] ?? 0 );
        $result = Campaigns::start( $id );
        if ( $result['success'] ) {
            wp_send_json_success( $result );
        }
        wp_send_json_error( $result['error'] ?? 'Failed to start campaign.' );
    }

    public function smshub_pause_campaign() {
        $this->check();
        $id = (int) ( $_POST['id'] ?? 0 );
        Campaigns::pause( $id ) ? wp_send_json_success() : wp_send_json_error( 'Failed.' );
    }

    public function smshub_delete_campaign() {
        $this->check();
        $id = (int) ( $_POST['id'] ?? 0 );
        Campaigns::delete( $id ) ? wp_send_json_success() : wp_send_json_error( 'Failed.' );
    }

    public function smshub_get_campaign_stats() {
        $this->check();
        $id    = (int) ( $_POST['id'] ?? 0 );
        $stats = Campaigns::get_stats( $id );
        wp_send_json_success( $stats );
    }

    // ── AI Message Suggestions ──────────────────────────────────────────
    public function smshub_ai_suggest() {
        $this->check();
        $context = sanitize_text_field( $_POST['context'] ?? '' );
        $tone    = sanitize_text_field( $_POST['tone'] ?? 'professional' );
        if ( ! $context ) wp_send_json_error( 'Context required.' );
        $suggestions = AI_Messages::suggest( $context, $tone );
        $tags = AI_Messages::suggest_tags( $context );
        wp_send_json_success( [ 'suggestions' => $suggestions, 'tags' => $tags ] );
    }

    // ── Workflows ───────────────────────────────────────────────────────
    public function smshub_save_workflow() {
        $this->check();
        $id = (int) ( $_POST['workflow_id'] ?? 0 );
        $data = [
            'name'          => sanitize_text_field( $_POST['name'] ?? '' ),
            'trigger_event' => sanitize_text_field( $_POST['trigger_event'] ?? '' ),
            'steps'         => json_decode( stripslashes( $_POST['steps'] ?? '[]' ), true ),
            'active'        => (int) ( $_POST['active'] ?? 1 ),
        ];
        if ( ! $data['name'] || ! $data['trigger_event'] ) wp_send_json_error( 'Name and trigger required.' );
        if ( $id ) {
            $ok = Workflows::update( $id, $data );
        } else {
            $ok = Workflows::create( $data );
        }
        $ok ? wp_send_json_success() : wp_send_json_error( 'Failed to save workflow.' );
    }

    public function smshub_delete_workflow() {
        $this->check();
        $id = (int) ( $_POST['id'] ?? 0 );
        Workflows::delete( $id ) ? wp_send_json_success() : wp_send_json_error( 'Failed.' );
    }

    public function smshub_toggle_workflow() {
        $this->check();
        global $wpdb;
        $id     = (int) ( $_POST['id'] ?? 0 );
        $active = (int) ( $_POST['active'] ?? 0 );
        $wpdb->update( $wpdb->prefix . 'smshub_workflows', [ 'active' => $active ], [ 'id' => $id ] );
        wp_send_json_success();
    }

    // ── Audit & Privacy ─────────────────────────────────────────────────
    public function smshub_get_audit_log() {
        $this->check();
        $data = Audit::get_log( [
            'per_page' => (int) ( $_POST['per_page'] ?? 50 ),
            'offset'   => (int) ( $_POST['offset'] ?? 0 ),
            'action'   => sanitize_text_field( $_POST['action_filter'] ?? '' ),
        ] );
        wp_send_json_success( $data );
    }

    public function smshub_export_personal_data() {
        $this->check();
        $phone = sanitize_text_field( $_POST['phone'] ?? '' );
        if ( ! $phone ) wp_send_json_error( 'Phone number required.' );
        $data = Privacy::export_personal_data( $phone );
        wp_send_json_success( $data );
    }

    public function smshub_erase_personal_data() {
        $this->check();
        $phone = sanitize_text_field( $_POST['phone'] ?? '' );
        if ( ! $phone ) wp_send_json_error( 'Phone number required.' );
        if ( ! isset( $_POST['confirm'] ) || $_POST['confirm'] !== 'yes' ) {
            wp_send_json_error( 'Confirmation required.' );
        }
        $erased = Privacy::erase_personal_data( $phone );
        wp_send_json_success( [ 'erased' => $erased ] );
    }

    // ── Reports & Real-time ─────────────────────────────────────────────
    public function smshub_get_live_feed() {
        $this->check();
        global $wpdb;
        $table = $wpdb->prefix . 'smshub_log';
        $since = sanitize_text_field( $_POST['since'] ?? '' );
        $where = $since ? $wpdb->prepare( "WHERE created_at > %s", $since ) : "WHERE 1=1";
        $items = $wpdb->get_results(
            "SELECT id, provider, recipient, LEFT(message, 50) as message_preview, status, trigger_src, created_at
             FROM {$table} {$where} ORDER BY created_at DESC LIMIT 20",
            ARRAY_A
        );
        wp_send_json_success( [ 'items' => $items, 'timestamp' => current_time( 'mysql' ) ] );
    }

    public function smshub_get_provider_health() {
        $this->check();
        global $wpdb;
        $table = $wpdb->prefix . 'smshub_log';
        // Last 1 hour stats per provider
        $health = $wpdb->get_results(
            "SELECT provider,
                    COUNT(*) as total,
                    SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END) as failed,
                    MAX(created_at) as last_activity
             FROM {$table}
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
             GROUP BY provider",
            ARRAY_A
        );
        // Calculate health status
        foreach ( $health as &$h ) {
            $fail_rate = $h['total'] > 0 ? ( $h['failed'] / $h['total'] ) * 100 : 0;
            $h['fail_rate'] = round( $fail_rate, 1 );
            $h['status'] = $fail_rate > 50 ? 'critical' : ( $fail_rate > 20 ? 'warning' : 'healthy' );
        }
        wp_send_json_success( $health );
    }

    public function smshub_get_reports() {
        $this->check();
        $type = sanitize_text_field( $_POST['report_type'] ?? '' );
        $days = (int) ( $_POST['days'] ?? 30 );

        switch ( $type ) {
            case 'forecast':
                wp_send_json_success( Reports::get_cost_forecast() );
                break;
            case 'providers':
                wp_send_json_success( Reports::get_provider_performance( $days ) );
                break;
            case 'peak_hours':
                wp_send_json_success( Reports::get_peak_hours( $days ) );
                break;
            case 'monthly':
                wp_send_json_success( Reports::get_monthly_trends( 6 ) );
                break;
            case 'weekly_summary':
                wp_send_json_success( Reports::get_weekly_summary() );
                break;
            default:
                wp_send_json_error( 'Invalid report type.' );
        }
    }

    // ── Outbound Webhooks ───────────────────────────────────────────────
    public function smshub_save_outbound_webhook() {
        $this->check();
        $url    = esc_url_raw( $_POST['webhook_url'] ?? '' );
        $name   = sanitize_text_field( $_POST['webhook_name'] ?? 'Webhook' );
        $events = array_map( 'sanitize_text_field', (array) ( $_POST['webhook_events'] ?? [ 'all' ] ) );
        if ( ! $url ) wp_send_json_error( 'URL required.' );
        Outbound_Webhooks::add_webhook( $url, $name, $events ) ? wp_send_json_success() : wp_send_json_error( 'Failed.' );
    }

    public function smshub_delete_outbound_webhook() {
        $this->check();
        $index = (int) ( $_POST['index'] ?? -1 );
        Outbound_Webhooks::remove_webhook( $index ) ? wp_send_json_success() : wp_send_json_error( 'Failed.' );
    }
}
