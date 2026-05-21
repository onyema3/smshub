<?php
namespace WPSMSHub;

if ( ! defined( 'ABSPATH' ) ) exit;

class Triggers {
    /** All supported WordPress events */
    public static function available_events(): array {
        return apply_filters( 'wp_sms_hub_events', [
            // User events
            'user_register'             => __( 'New User Registration',          'wp-sms-hub' ),
            'wp_login'                  => __( 'User Login',                     'wp-sms-hub' ),
            'after_password_reset'      => __( 'Password Reset',                 'wp-sms-hub' ),
            // Post events
            'publish_post'              => __( 'Post Published',                  'wp-sms-hub' ),
            'publish_page'              => __( 'Page Published',                  'wp-sms-hub' ),
            'transition_post_status'    => __( 'Post Status Changed',             'wp-sms-hub' ),
            // WooCommerce
            'woocommerce_order_status_pending'    => __( 'WC: Order Pending',    'wp-sms-hub' ),
            'woocommerce_order_status_processing' => __( 'WC: Order Processing', 'wp-sms-hub' ),
            'woocommerce_order_status_completed'  => __( 'WC: Order Completed',  'wp-sms-hub' ),
            'woocommerce_order_status_cancelled'  => __( 'WC: Order Cancelled',  'wp-sms-hub' ),
            'woocommerce_order_status_refunded'   => __( 'WC: Order Refunded',   'wp-sms-hub' ),
            'woocommerce_new_order'               => __( 'WC: New Order',        'wp-sms-hub' ),
            // Comments
            'comment_post'              => __( 'New Comment',                    'wp-sms-hub' ),
            // Contact Form 7
            'wpcf7_mail_sent'           => __( 'CF7: Form Submitted',            'wp-sms-hub' ),
            // Gravity Forms
            'gform_after_submission'    => __( 'Gravity Forms: Submission',      'wp-sms-hub' ),
            // Custom / manual
            'wp_sms_hub_custom_trigger' => __( 'Custom / API Trigger',           'wp-sms-hub' ),
        ] );
    }

    public function __construct() {
        add_action( 'init', [ $this, 'bind_trigger_hooks' ] );
    }

    public function bind_trigger_hooks() {
        foreach ( array_keys( self::available_events() ) as $event ) {
            // Use a high priority to run after normal handlers
            add_action( $event, [ $this, 'handle_event' ], 99, 5 );
        }
    }

    public function handle_event() {
        $event = current_filter();
        $args  = func_get_args();
        $rules = $this->get_active_rules_for( $event );

        if ( ! empty( $rules ) ) {
            foreach ( $rules as $rule ) {
                $context    = $this->build_context( $event, $args );
                $message    = $this->interpolate( $rule['message_tpl'], $context );
                $recipients = $this->resolve_recipients( $rule['recipients'], $context );
                if ( empty( $recipients ) ) continue;

                SMS_Manager::send( $recipients, $message, [
                    'provider'    => $rule['provider'] ?: null,
                    'sender_id'   => $rule['sender_id'] ?: null,
                    'trigger_src' => 'trigger:' . $rule['id'] . ':' . $event,
                ] );
            }
        }

        // Trigger any workflows listening to this event
        $context = $this->build_context( $event, $args );
        Workflows::trigger( $event, $context );
    }

    private function get_active_rules_for( string $event ): array {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}smshub_triggers WHERE event = %s AND active = 1",
            $event
        ), ARRAY_A );
    }

    /**
     * Build a key=>value context from the WordPress hook arguments
     */
    private function build_context( string $event, array $args ): array {
        $ctx = [
            'site_name'  => get_bloginfo( 'name' ),
            'site_url'   => get_bloginfo( 'url' ),
            'event'      => $event,
            'date'       => date_i18n( get_option( 'date_format' ) ),
            'time'       => date_i18n( get_option( 'time_format' ) ),
        ];

        // WooCommerce order context
        if ( str_starts_with( $event, 'woocommerce_order_status' ) || $event === 'woocommerce_new_order' ) {
            $order_id = is_numeric( $args[0] ) ? (int) $args[0] : 0;
            if ( $order_id && function_exists( 'wc_get_order' ) ) {
                $order = wc_get_order( $order_id );
                if ( $order ) {
                    $ctx['order_id']       = $order->get_id();
                    $ctx['order_total']    = $order->get_formatted_order_total();
                    $ctx['order_status']   = wc_get_order_status_name( $order->get_status() );
                    $ctx['customer_name']  = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
                    $ctx['customer_phone'] = $order->get_billing_phone();
                    $ctx['customer_email'] = $order->get_billing_email();
                }
            }
        }

        // User registration / login context
        if ( in_array( $event, [ 'user_register', 'wp_login', 'after_password_reset' ] ) ) {
            $user_id = is_numeric( $args[0] ) ? (int) $args[0] : 0;
            if ( ! $user_id && isset( $args[1] ) && is_a( $args[1], 'WP_User' ) ) {
                $user_id = $args[1]->ID;
            }
            if ( $user_id ) {
                $user = get_userdata( $user_id );
                if ( $user ) {
                    $ctx['user_name']     = $user->display_name;
                    $ctx['user_email']    = $user->user_email;
                    $ctx['user_phone']    = get_user_meta( $user_id, 'billing_phone', true )
                                         ?: get_user_meta( $user_id, 'phone', true );
                }
            }
        }

        return apply_filters( 'wp_sms_hub_trigger_context', $ctx, $event, $args );
    }

    private function interpolate( string $tpl, array $ctx ): string {
        foreach ( $ctx as $k => $v ) {
            $tpl = str_replace( '{' . $k . '}', (string) $v, $tpl );
        }
        return $tpl;
    }

    private function resolve_recipients( string $recipients_raw, array $ctx ): array {
        $list = array_filter( array_map( 'trim', explode( ',', $recipients_raw ) ) );
        $out  = [];

        foreach ( $list as $item ) {
            if ( str_starts_with( $item, 'group:' ) ) {
                $group = substr( $item, 6 );
                $out   = array_merge( $out, Contacts::get_phones_by_group( $group ) );
            } elseif ( $item === '{customer_phone}' && ! empty( $ctx['customer_phone'] ) ) {
                $out[] = $ctx['customer_phone'];
            } elseif ( $item === '{user_phone}' && ! empty( $ctx['user_phone'] ) ) {
                $out[] = $ctx['user_phone'];
            } elseif ( $item === 'admin' ) {
                $admin_phone = get_option( 'wpsmshub_admin_phone', '' );
                if ( $admin_phone ) $out[] = $admin_phone;
            } else {
                $out[] = $item;
            }
        }

        return array_unique( array_filter( $out ) );
    }

    // ── CRUD ──────────────────────────────────────────────────────────────────
    public static function create( array $data ): int|false {
        global $wpdb;
        $res = $wpdb->insert( "{$wpdb->prefix}smshub_triggers", [
            'name'        => sanitize_text_field( $data['name'] ),
            'event'       => sanitize_text_field( $data['event'] ),
            'provider'    => sanitize_text_field( $data['provider'] ?? '' ),
            'recipients'  => sanitize_textarea_field( $data['recipients'] ),
            'sender_id'   => sanitize_text_field( $data['sender_id'] ?? '' ),
            'message_tpl' => sanitize_textarea_field( $data['message_tpl'] ),
            'active'      => (int) ( $data['active'] ?? 1 ),
        ] );
        return $res ? (int) $wpdb->insert_id : false;
    }

    public static function update( int $id, array $data ): bool {
        global $wpdb;
        return (bool) $wpdb->update( "{$wpdb->prefix}smshub_triggers", [
            'name'        => sanitize_text_field( $data['name'] ),
            'event'       => sanitize_text_field( $data['event'] ),
            'provider'    => sanitize_text_field( $data['provider'] ?? '' ),
            'recipients'  => sanitize_textarea_field( $data['recipients'] ),
            'sender_id'   => sanitize_text_field( $data['sender_id'] ?? '' ),
            'message_tpl' => sanitize_textarea_field( $data['message_tpl'] ),
            'active'      => (int) ( $data['active'] ?? 1 ),
        ], [ 'id' => $id ], null, [ '%d' ] );
    }

    public static function delete_rule( int $id ): bool {
        global $wpdb;
        return (bool) $wpdb->delete( "{$wpdb->prefix}smshub_triggers", [ 'id' => $id ], [ '%d' ] );
    }

    public static function get_all(): array {
        global $wpdb;
        return $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}smshub_triggers ORDER BY id DESC", ARRAY_A );
    }

    public static function get( int $id ): ?array {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}smshub_triggers WHERE id=%d", $id ), ARRAY_A ) ?: null;
    }
}
