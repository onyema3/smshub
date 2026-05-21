<?php
namespace WPSMSHub;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Plugin Integrations - FluentCRM, WPForms, Gravity Forms, MemberPress, EDD.
 */
class Plugin_Integrations {

    public function __construct() {
        // WPForms
        add_action( 'wpforms_process_complete', [ $this, 'wpforms_submission' ], 10, 4 );

        // Gravity Forms
        add_action( 'gform_after_submission', [ $this, 'gravity_forms_submission' ], 10, 2 );

        // FluentCRM
        add_action( 'fluent_crm/subscriber_created', [ $this, 'fluentcrm_subscriber_created' ] );
        add_action( 'fluent_crm/subscriber_status_changed', [ $this, 'fluentcrm_status_changed' ], 10, 2 );

        // MemberPress
        add_action( 'mepr-event-transaction-completed', [ $this, 'memberpress_signup' ] );
        add_action( 'mepr-event-transaction-expired', [ $this, 'memberpress_expired' ] );

        // Easy Digital Downloads
        add_action( 'edd_complete_purchase', [ $this, 'edd_purchase_complete' ] );
    }

    // ── WPForms ─────────────────────────────────────────────────────────
    public function wpforms_submission( $fields, $entry, $form_data, $entry_id ) {
        $enabled = get_option( 'wpsmshub_wpforms_enabled', 'no' );
        if ( $enabled !== 'yes' ) return;

        $phone = '';
        $name  = '';
        foreach ( $fields as $field ) {
            if ( $field['type'] === 'phone' ) $phone = $field['value'];
            if ( $field['type'] === 'name' ) $name = $field['value'];
        }

        if ( ! $phone ) return;

        $template = get_option( 'wpsmshub_wpforms_template', 'Thank you for your submission, {name}! We will get back to you soon. - {site_name}' );
        $message = str_replace(
            [ '{name}', '{site_name}', '{form_name}' ],
            [ $name, get_bloginfo( 'name' ), $form_data['settings']['form_title'] ?? 'Form' ],
            $template
        );

        SMS_Manager::send( $phone, $message, [ 'trigger_src' => 'wpforms:' . ( $form_data['id'] ?? '' ) ] );
    }

    // ── Gravity Forms ───────────────────────────────────────────────────
    public function gravity_forms_submission( $entry, $form ) {
        $enabled = get_option( 'wpsmshub_gforms_enabled', 'no' );
        if ( $enabled !== 'yes' ) return;

        // Find phone field
        $phone = '';
        $name  = '';
        foreach ( $form['fields'] as $field ) {
            if ( $field->type === 'phone' ) $phone = rgar( $entry, $field->id );
            if ( $field->type === 'name' ) $name = rgar( $entry, $field->id );
        }

        if ( ! $phone ) return;

        $template = get_option( 'wpsmshub_gforms_template', 'Thanks {name}! Your form submission was received. - {site_name}' );
        $message = str_replace(
            [ '{name}', '{site_name}', '{form_name}' ],
            [ $name, get_bloginfo( 'name' ), $form['title'] ?? 'Form' ],
            $template
        );

        SMS_Manager::send( $phone, $message, [ 'trigger_src' => 'gforms:' . $form['id'] ] );
    }

    // ── FluentCRM ───────────────────────────────────────────────────────
    public function fluentcrm_subscriber_created( $subscriber ) {
        $enabled = get_option( 'wpsmshub_fluentcrm_enabled', 'no' );
        if ( $enabled !== 'yes' ) return;

        $phone = $subscriber->phone ?? '';
        if ( ! $phone ) return;

        $template = get_option( 'wpsmshub_fluentcrm_welcome', 'Welcome {name}! You have been added to our mailing list. - {site_name}' );
        $message = str_replace(
            [ '{name}', '{email}', '{site_name}' ],
            [ $subscriber->full_name ?? '', $subscriber->email ?? '', get_bloginfo( 'name' ) ],
            $template
        );

        SMS_Manager::send( $phone, $message, [ 'trigger_src' => 'fluentcrm:new_subscriber' ] );
    }

    public function fluentcrm_status_changed( $subscriber, $old_status ) {
        $enabled = get_option( 'wpsmshub_fluentcrm_enabled', 'no' );
        if ( $enabled !== 'yes' ) return;

        $phone = $subscriber->phone ?? '';
        if ( ! $phone || $subscriber->status === $old_status ) return;

        // Only notify on unsubscribe
        if ( $subscriber->status === 'unsubscribed' ) {
            $message = 'You have been unsubscribed from ' . get_bloginfo( 'name' ) . '. Reply REJOIN to re-subscribe.';
            SMS_Manager::send( $phone, $message, [ 'trigger_src' => 'fluentcrm:unsubscribed' ] );
        }
    }

    // ── MemberPress ─────────────────────────────────────────────────────
    public function memberpress_signup( $event ) {
        $enabled = get_option( 'wpsmshub_memberpress_enabled', 'no' );
        if ( $enabled !== 'yes' ) return;

        $txn  = $event->get_data();
        $user = get_userdata( $txn->user_id );
        if ( ! $user ) return;

        $phone = get_user_meta( $user->ID, 'billing_phone', true ) ?: get_user_meta( $user->ID, 'phone', true );
        if ( ! $phone ) return;

        $membership = get_the_title( $txn->product_id );
        $template = get_option( 'wpsmshub_memberpress_signup_tpl', 'Welcome {name}! Your {membership} membership is now active. - {site_name}' );
        $message = str_replace(
            [ '{name}', '{membership}', '{site_name}' ],
            [ $user->display_name, $membership, get_bloginfo( 'name' ) ],
            $template
        );

        SMS_Manager::send( $phone, $message, [ 'trigger_src' => 'memberpress:signup' ] );
    }

    public function memberpress_expired( $event ) {
        $enabled = get_option( 'wpsmshub_memberpress_enabled', 'no' );
        if ( $enabled !== 'yes' ) return;

        $txn  = $event->get_data();
        $user = get_userdata( $txn->user_id );
        if ( ! $user ) return;

        $phone = get_user_meta( $user->ID, 'billing_phone', true ) ?: get_user_meta( $user->ID, 'phone', true );
        if ( ! $phone ) return;

        $membership = get_the_title( $txn->product_id );
        $message = "{$user->display_name}, your {$membership} membership has expired. Renew at " . get_bloginfo( 'url' ) . " to keep access.";

        SMS_Manager::send( $phone, $message, [ 'trigger_src' => 'memberpress:expired' ] );
    }

    // ── Easy Digital Downloads ──────────────────────────────────────────
    public function edd_purchase_complete( $payment_id ) {
        $enabled = get_option( 'wpsmshub_edd_enabled', 'no' );
        if ( $enabled !== 'yes' ) return;

        $payment = edd_get_payment( $payment_id );
        if ( ! $payment ) return;

        $phone = $payment->phone ?? get_user_meta( $payment->user_id, 'billing_phone', true );
        if ( ! $phone ) return;

        $template = get_option( 'wpsmshub_edd_template', 'Hi {name}! Your purchase of {amount} is complete. Download your files at {site_url}. - {site_name}' );
        $message = str_replace(
            [ '{name}', '{amount}', '{site_url}', '{site_name}' ],
            [ $payment->first_name, edd_currency_filter( edd_format_amount( $payment->total ) ), get_bloginfo( 'url' ), get_bloginfo( 'name' ) ],
            $template
        );

        SMS_Manager::send( $phone, $message, [ 'trigger_src' => 'edd:purchase' ] );
    }
}
