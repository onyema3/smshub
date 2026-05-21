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

        do_action( 'wp_sms_hub_loaded' );
    }
}
