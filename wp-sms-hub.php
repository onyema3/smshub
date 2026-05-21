<?php
/**
 * Plugin Name: WP SMS Hub
 * Plugin URI:  https://github.com/your-repo/wp-sms-hub
 * Description: Multi-provider SMS gateway plugin for WordPress. Supports KudiSMS, SMSNigeria, Termii, Twilio, InfoBip, Vonage, Africa's Talking, BulkSMS, and more.
 * Version:     1.0.0
 * Author:      WP SMS Hub
 * License:     GPL-2.0+
 * Text Domain: wp-sms-hub
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'WPSMSHUB_VERSION',     '1.0.0' );
define( 'WPSMSHUB_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'WPSMSHUB_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );
define( 'WPSMSHUB_PLUGIN_FILE', __FILE__ );

// Autoload classes (WordPress-style filenames: class-foo-bar.php)
spl_autoload_register( function( $class ) {
    $prefix = 'WPSMSHub\\';
    if ( strpos( $class, $prefix ) !== 0 ) return;

    $relative = substr( $class, strlen( $prefix ) );      // e.g. "Providers\KudiSMS" or "Installer"
    $parts    = explode( '\\', $relative );
    $name     = array_pop( $parts );

    // SMS_Manager -> class-sms-manager.php, REST_API -> class-rest-api.php
    $filename = 'class-' . strtolower( str_replace( '_', '-', $name ) ) . '.php';
    $subdir   = $parts ? strtolower( implode( '/', $parts ) ) . '/' : '';

    // Try includes/<subdir>/file, then <subdir>/file at plugin root
    // (admin/, providers/ live at the root, not under includes/)
    $candidates = [
        WPSMSHUB_PLUGIN_DIR . 'includes/' . $subdir . $filename,
        WPSMSHUB_PLUGIN_DIR . $subdir . $filename,
    ];
    foreach ( $candidates as $file ) {
        if ( file_exists( $file ) ) {
            require_once $file;
            return;
        }
    }
});

require_once WPSMSHUB_PLUGIN_DIR . 'includes/class-plugin.php';

function wp_sms_hub() {
    return \WPSMSHub\Plugin::instance();
}

add_action( 'plugins_loaded', 'wp_sms_hub' );

register_activation_hook( __FILE__,   [ 'WPSMSHub\Installer', 'activate'   ] );
register_deactivation_hook( __FILE__, [ 'WPSMSHub\Installer', 'deactivate' ] );
