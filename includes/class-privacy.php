<?php
namespace WPSMSHub;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * NDPR / Data Privacy - Consent management, data export, auto-purge.
 */
class Privacy {

    public function __construct() {
        // WordPress privacy tools integration
        add_filter( 'wp_privacy_personal_data_exporters', [ $this, 'register_exporter' ] );
        add_filter( 'wp_privacy_personal_data_erasers', [ $this, 'register_eraser' ] );

        // Auto-purge cron
        add_action( 'smshub_privacy_purge', [ $this, 'auto_purge' ] );
        if ( ! wp_next_scheduled( 'smshub_privacy_purge' ) ) {
            wp_schedule_event( time(), 'daily', 'smshub_privacy_purge' );
        }
    }

    // ── Consent Tracking ────────────────────────────────────────────────
    public static function record_consent( string $phone, string $source = 'manual', string $ip = '' ): bool {
        global $wpdb;
        return (bool) $wpdb->insert( $wpdb->prefix . 'smshub_consent', [
            'phone'        => $phone,
            'consent_type' => 'sms_optin',
            'source'       => $source,
            'ip_address'   => $ip ?: ( $_SERVER['REMOTE_ADDR'] ?? '' ),
            'consented_at' => current_time( 'mysql' ),
        ] );
    }

    public static function revoke_consent( string $phone ): bool {
        global $wpdb;
        return (bool) $wpdb->update(
            $wpdb->prefix . 'smshub_consent',
            [ 'revoked_at' => current_time( 'mysql' ) ],
            [ 'phone' => $phone, 'revoked_at' => null ]
        );
    }

    public static function has_consent( string $phone ): bool {
        global $wpdb;
        return (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}smshub_consent WHERE phone = %s AND revoked_at IS NULL ORDER BY consented_at DESC LIMIT 1",
            $phone
        ) );
    }

    public static function get_consent_history( string $phone ): array {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}smshub_consent WHERE phone = %s ORDER BY consented_at DESC",
            $phone
        ), ARRAY_A );
    }

    // ── Data Export (GDPR/NDPR) ─────────────────────────────────────────
    public static function export_personal_data( string $phone ): array {
        global $wpdb;
        $data = [];

        // Contact info
        $contact = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}smshub_contacts WHERE phone = %s", $phone
        ), ARRAY_A );
        if ( $contact ) $data['contact'] = $contact;

        // SMS history
        $messages = $wpdb->get_results( $wpdb->prepare(
            "SELECT provider, recipient, message, status, created_at FROM {$wpdb->prefix}smshub_log WHERE recipient = %s ORDER BY created_at DESC LIMIT 500",
            $phone
        ), ARRAY_A );
        if ( $messages ) $data['messages'] = $messages;

        // Consent records
        $data['consent'] = self::get_consent_history( $phone );

        // Tags
        if ( $contact ) {
            $data['tags'] = Segments::get_contact_tags( (int) $contact['id'] );
        }

        return $data;
    }

    public static function erase_personal_data( string $phone ): array {
        global $wpdb;
        $erased = [];

        // Delete contact
        $wpdb->delete( $wpdb->prefix . 'smshub_contacts', [ 'phone' => $phone ] );
        $erased[] = 'contact';

        // Delete SMS log entries
        $wpdb->delete( $wpdb->prefix . 'smshub_log', [ 'recipient' => $phone ] );
        $erased[] = 'sms_log';

        // Delete consent records
        $wpdb->delete( $wpdb->prefix . 'smshub_consent', [ 'phone' => $phone ] );
        $erased[] = 'consent';

        // Delete tags
        $contact_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}smshub_contacts WHERE phone = %s", $phone
        ) );
        if ( $contact_id ) {
            $wpdb->delete( $wpdb->prefix . 'smshub_tags', [ 'contact_id' => $contact_id ] );
            $erased[] = 'tags';
        }

        // Delete queue entries
        $wpdb->delete( $wpdb->prefix . 'smshub_queue', [ 'recipient' => $phone ] );
        $erased[] = 'queue';

        Audit::log( 'personal_data_erased', 'Phone: ' . $phone );

        return $erased;
    }

    // ── Auto-Purge ──────────────────────────────────────────────────────
    public function auto_purge() {
        $days = (int) get_option( 'wpsmshub_auto_purge_days', 0 );
        if ( $days <= 0 ) return;

        global $wpdb;

        // Purge contacts with no activity for X days
        $cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

        $inactive = $wpdb->get_col( $wpdb->prepare(
            "SELECT c.phone FROM {$wpdb->prefix}smshub_contacts c
             WHERE c.created_at < %s
             AND c.phone NOT IN (
                SELECT recipient FROM {$wpdb->prefix}smshub_log WHERE created_at > %s
             )",
            $cutoff, $cutoff
        ) );

        foreach ( $inactive as $phone ) {
            self::erase_personal_data( $phone );
        }

        if ( count( $inactive ) > 0 ) {
            Audit::log( 'auto_purge_executed', 'Purged ' . count( $inactive ) . ' inactive contacts (>' . $days . ' days)' );
        }
    }

    // ── WordPress Privacy Tools Integration ─────────────────────────────
    public function register_exporter( array $exporters ): array {
        $exporters['wp-sms-hub'] = [
            'exporter_friendly_name' => 'WP SMS Hub',
            'callback'               => [ $this, 'wp_exporter_callback' ],
        ];
        return $exporters;
    }

    public function register_eraser( array $erasers ): array {
        $erasers['wp-sms-hub'] = [
            'eraser_friendly_name' => 'WP SMS Hub',
            'callback'             => [ $this, 'wp_eraser_callback' ],
        ];
        return $erasers;
    }

    public function wp_exporter_callback( string $email, int $page = 1 ): array {
        // Try to find phone by user email
        $user = get_user_by( 'email', $email );
        $phone = $user ? get_user_meta( $user->ID, 'billing_phone', true ) : '';
        if ( ! $phone ) return [ 'data' => [], 'done' => true ];

        $data = self::export_personal_data( $phone );
        $export_items = [];

        if ( ! empty( $data['contact'] ) ) {
            $export_items[] = [
                'group_id'    => 'smshub-contact',
                'group_label' => 'SMS Hub Contact',
                'item_id'     => 'contact-' . $data['contact']['id'],
                'data'        => [
                    [ 'name' => 'Name', 'value' => $data['contact']['name'] ],
                    [ 'name' => 'Phone', 'value' => $data['contact']['phone'] ],
                    [ 'name' => 'Group', 'value' => $data['contact']['group_name'] ],
                ],
            ];
        }

        return [ 'data' => $export_items, 'done' => true ];
    }

    public function wp_eraser_callback( string $email, int $page = 1 ): array {
        $user = get_user_by( 'email', $email );
        $phone = $user ? get_user_meta( $user->ID, 'billing_phone', true ) : '';
        if ( ! $phone ) return [ 'items_removed' => 0, 'items_retained' => 0, 'messages' => [], 'done' => true ];

        $erased = self::erase_personal_data( $phone );
        return [
            'items_removed'  => count( $erased ),
            'items_retained' => 0,
            'messages'       => [],
            'done'           => true,
        ];
    }
}
