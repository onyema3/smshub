<?php
namespace WPSMSHub\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

use WPSMSHub\SMS_Manager;
use WPSMSHub\Log;
use WPSMSHub\Contacts;
use WPSMSHub\Triggers;
use WPSMSHub\Templates;
use WPSMSHub\Sub_Accounts;
use WPSMSHub\Campaigns;

class Admin {
    public function __construct() {
        add_action( 'admin_menu',            [ $this, 'register_menus' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
    }

    public function register_menus() {
        add_menu_page(
            'WP SMS Hub',
            'SMS Hub',
            'manage_options',
            'wp-sms-hub',
            [ $this, 'page_dashboard' ],
            'dashicons-smartphone',
            56
        );
        add_submenu_page( 'wp-sms-hub', 'Send SMS',      'Send SMS',      'manage_options', 'wp-sms-hub',            [ $this, 'page_dashboard' ] );
        add_submenu_page( 'wp-sms-hub', 'Templates',     'Templates',     'manage_options', 'smshub-templates',      [ $this, 'page_templates' ] );
        add_submenu_page( 'wp-sms-hub', 'Triggers',      'Triggers',      'manage_options', 'smshub-triggers',       [ $this, 'page_triggers'  ] );
        add_submenu_page( 'wp-sms-hub', 'Contacts',      'Contacts',      'manage_options', 'smshub-contacts',       [ $this, 'page_contacts'  ] );
        add_submenu_page( 'wp-sms-hub', 'Campaigns',     'Campaigns',     'manage_options', 'smshub-campaigns',      [ $this, 'page_campaigns' ] );
        add_submenu_page( 'wp-sms-hub', 'Sub-Accounts',  'Sub-Accounts',  'manage_options', 'smshub-sub-accounts',   [ $this, 'page_sub_accounts' ] );
        add_submenu_page( 'wp-sms-hub', 'SMS Log',       'SMS Log',       'manage_options', 'smshub-log',            [ $this, 'page_log'       ] );
        add_submenu_page( 'wp-sms-hub', 'Settings',      'Settings',      'manage_options', 'smshub-settings',       [ $this, 'page_settings'  ] );
    }

    public function enqueue_assets( $hook ) {
        if ( strpos( $hook, 'sms-hub' ) === false && strpos( $hook, 'smshub' ) === false ) return;

        wp_enqueue_style( 'wp-sms-hub', \WPSMSHUB_PLUGIN_URL . 'assets/css/admin.css', [], \WPSMSHUB_VERSION );
        wp_enqueue_script( 'wp-sms-hub', \WPSMSHUB_PLUGIN_URL . 'assets/js/admin.js', [ 'jquery' ], \WPSMSHUB_VERSION, true );

        // Load Chart.js on dashboard page
        if ( strpos( $hook, 'wp-sms-hub' ) !== false && strpos( $hook, 'smshub-' ) === false ) {
            wp_enqueue_script( 'chartjs', 'https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js', [], '4.4', true );
        }

        wp_localize_script( 'wp-sms-hub', 'SMSHUB', [
            'ajax_url'  => admin_url( 'admin-ajax.php' ),
            'nonce'     => wp_create_nonce( 'wp_sms_hub_nonce' ),
            'providers' => array_keys( SMS_Manager::get_providers() ),
            'events'    => Triggers::available_events(),
        ] );
    }

    public function page_dashboard() {
        $stats     = Log::get_stats();
        $providers = SMS_Manager::get_providers();
        $active    = get_option( 'wpsmshub_active_provider', '' );
        include \WPSMSHUB_PLUGIN_DIR . 'templates/dashboard.php';
    }

    public function page_triggers() {
        $triggers = Triggers::get_all();
        $events   = Triggers::available_events();
        $providers = SMS_Manager::get_providers();
        $groups    = Contacts::get_groups();
        include \WPSMSHUB_PLUGIN_DIR . 'templates/triggers.php';
    }

    public function page_contacts() {
        $data   = Contacts::get_list( [
            'per_page' => 50,
            'offset'   => (int) ( $_GET['offset'] ?? 0 ),
            'search'   => sanitize_text_field( $_GET['s'] ?? '' ),
            'group'    => sanitize_text_field( $_GET['group'] ?? '' ),
        ] );
        $groups = Contacts::get_groups();
        include \WPSMSHUB_PLUGIN_DIR . 'templates/contacts.php';
    }

    public function page_log() {
        $data = Log::get_list( [
            'per_page' => 50,
            'offset'   => (int) ( $_GET['offset'] ?? 0 ),
            'status'   => sanitize_text_field( $_GET['status'] ?? '' ),
            'provider' => sanitize_text_field( $_GET['provider'] ?? '' ),
            'search'   => sanitize_text_field( $_GET['s'] ?? '' ),
        ] );
        include \WPSMSHUB_PLUGIN_DIR . 'templates/log.php';
    }

    public function page_settings() {
        $providers   = SMS_Manager::get_providers();
        $active      = get_option( 'wpsmshub_active_provider', '' );
        $admin_phone = get_option( 'wpsmshub_admin_phone', '' );
        $api_key     = get_option( 'wpsmshub_rest_api_key', '' );
        include \WPSMSHUB_PLUGIN_DIR . 'templates/settings.php';
    }

    public function page_templates() {
        $templates  = Templates::get_all();
        $categories = Templates::get_categories();
        include \WPSMSHUB_PLUGIN_DIR . 'templates/sms-templates.php';
    }

    public function page_campaigns() {
        $campaigns = Campaigns::get_all();
        $groups    = Contacts::get_groups();
        $providers = SMS_Manager::get_providers();
        include \WPSMSHUB_PLUGIN_DIR . 'templates/campaigns.php';
    }

    public function page_sub_accounts() {
        $accounts = Sub_Accounts::get_all();
        include \WPSMSHUB_PLUGIN_DIR . 'templates/sub-accounts.php';
    }
}
