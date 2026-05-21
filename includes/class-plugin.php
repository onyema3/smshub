<?php
namespace WPSMSHub;

if ( ! defined( 'ABSPATH' ) ) exit;

class Plugin {
    private static $instance = null;

    public static function instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
            self::$instance->init();
        }
        return self::$instance;
    }

    private function init() {
        require_once WPSMSHUB_PLUGIN_DIR . 'includes/class-installer.php';
        require_once WPSMSHUB_PLUGIN_DIR . 'includes/class-sms-manager.php';
        require_once WPSMSHUB_PLUGIN_DIR . 'includes/class-log.php';
        require_once WPSMSHUB_PLUGIN_DIR . 'includes/class-contacts.php';
        require_once WPSMSHUB_PLUGIN_DIR . 'includes/class-triggers.php';
        require_once WPSMSHUB_PLUGIN_DIR . 'includes/class-ajax.php';
        require_once WPSMSHUB_PLUGIN_DIR . 'includes/class-rest-api.php';
        require_once WPSMSHUB_PLUGIN_DIR . 'includes/class-queue.php';
        require_once WPSMSHUB_PLUGIN_DIR . 'includes/class-webhooks.php';
        require_once WPSMSHUB_PLUGIN_DIR . 'includes/class-templates.php';
        require_once WPSMSHUB_PLUGIN_DIR . 'includes/class-rate-limiter.php';
        require_once WPSMSHUB_PLUGIN_DIR . 'includes/class-woocommerce.php';
        require_once WPSMSHUB_PLUGIN_DIR . 'includes/class-sub-accounts.php';
        require_once WPSMSHUB_PLUGIN_DIR . 'includes/class-campaigns.php';
        require_once WPSMSHUB_PLUGIN_DIR . 'includes/class-link-tracker.php';
        require_once WPSMSHUB_PLUGIN_DIR . 'includes/class-ai-messages.php';
        require_once WPSMSHUB_PLUGIN_DIR . 'includes/class-workflows.php';
        require_once WPSMSHUB_PLUGIN_DIR . 'includes/class-audit.php';
        require_once WPSMSHUB_PLUGIN_DIR . 'includes/class-privacy.php';
        require_once WPSMSHUB_PLUGIN_DIR . 'includes/class-security.php';

        // Load all providers
        foreach ( glob( WPSMSHUB_PLUGIN_DIR . 'providers/class-*.php' ) as $file ) {
            require_once $file;
        }

        if ( is_admin() ) {
            require_once WPSMSHUB_PLUGIN_DIR . 'admin/class-admin.php';
            new Admin\Admin();
        }

        new SMS_Manager();
        new Triggers();
        new Ajax();
        new REST_API();
        new Queue();
        new Webhooks();
        new Rate_Limiter();
        new WooCommerce_Integration();
        new Campaigns();
        new Link_Tracker();
        new Workflows();
        new Audit();
        new Privacy();
        new Security();

        do_action( 'wp_sms_hub_loaded' );
    }
}
