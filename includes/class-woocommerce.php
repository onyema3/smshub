<?php
namespace WPSMSHub;

if ( ! defined( 'ABSPATH' ) ) exit;

class WooCommerce_Integration {
    public function __construct() {
        if ( ! class_exists( 'WooCommerce' ) ) return;

        // Add SMS opt-in to checkout
        add_action( 'woocommerce_after_checkout_billing_form', [ $this, 'add_checkout_optin' ] );
        add_action( 'woocommerce_checkout_update_order_meta', [ $this, 'save_checkout_optin' ] );

        // Order status notifications
        add_action( 'woocommerce_order_status_changed', [ $this, 'order_status_changed' ], 10, 4 );

        // Add extra merge tags
        add_filter( 'wp_sms_hub_trigger_context', [ $this, 'add_woo_context' ], 10, 3 );
    }

    public function add_checkout_optin( $checkout ) {
        $enabled = get_option( 'wpsmshub_woo_checkout_optin', 'yes' );
        if ( $enabled !== 'yes' ) return;

        woocommerce_form_field( 'smshub_sms_optin', [
            'type'    => 'checkbox',
            'class'   => [ 'form-row-wide' ],
            'label'   => get_option( 'wpsmshub_woo_optin_label', 'Send me order updates via SMS' ),
            'default' => 1,
        ], $checkout->get_value( 'smshub_sms_optin' ) );
    }

    public function save_checkout_optin( int $order_id ) {
        if ( isset( $_POST['smshub_sms_optin'] ) ) {
            update_post_meta( $order_id, '_smshub_sms_optin', 'yes' );
        } else {
            update_post_meta( $order_id, '_smshub_sms_optin', 'no' );
        }
    }

    public function order_status_changed( int $order_id, string $from, string $to, $order ) {
        // Check if customer opted in
        $optin = get_post_meta( $order_id, '_smshub_sms_optin', true );
        if ( $optin === 'no' ) return;

        $phone = $order->get_billing_phone();
        if ( ! $phone ) return;

        // Check if there's an auto-notification template for this status
        $template_key = 'wpsmshub_woo_tpl_' . $to;
        $template = get_option( $template_key, '' );
        if ( ! $template ) return;

        // Build context and interpolate
        $context = [
            'order_id'       => $order->get_id(),
            'order_total'    => $order->get_formatted_order_total(),
            'order_status'   => wc_get_order_status_name( $to ),
            'customer_name'  => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
            'customer_phone' => $phone,
            'customer_email' => $order->get_billing_email(),
            'site_name'      => get_bloginfo( 'name' ),
            'tracking_number' => get_post_meta( $order_id, '_tracking_number', true ) ?: '',
            'payment_method' => $order->get_payment_method_title(),
        ];

        // Get order items summary
        $items = [];
        foreach ( $order->get_items() as $item ) {
            $items[] = $item->get_name() . ' x' . $item->get_quantity();
        }
        $context['order_items'] = implode( ', ', array_slice( $items, 0, 3 ) );
        if ( count( $items ) > 3 ) $context['order_items'] .= '...';

        // Interpolate template
        $message = $template;
        foreach ( $context as $k => $v ) {
            $message = str_replace( '{' . $k . '}', (string) $v, $message );
        }

        SMS_Manager::send( $phone, $message, [ 'trigger_src' => 'woo:' . $to ] );
    }

    public function add_woo_context( array $ctx, string $event, array $args ): array {
        if ( ! str_starts_with( $event, 'woocommerce_' ) ) return $ctx;

        // Add tracking_number and order_items if available
        if ( ! empty( $ctx['order_id'] ) && function_exists( 'wc_get_order' ) ) {
            $order = wc_get_order( $ctx['order_id'] );
            if ( $order ) {
                $ctx['tracking_number'] = get_post_meta( $order->get_id(), '_tracking_number', true ) ?: '';
                $ctx['payment_method']  = $order->get_payment_method_title();
                $items = [];
                foreach ( $order->get_items() as $item ) {
                    $items[] = $item->get_name() . ' x' . $item->get_quantity();
                }
                $ctx['order_items'] = implode( ', ', array_slice( $items, 0, 3 ) );
            }
        }
        return $ctx;
    }
}
