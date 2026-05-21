<?php
/**
 * PHPUnit bootstrap file for WP SMS Hub tests.
 * Loads WordPress test environment if available, otherwise mocks essential functions.
 */

// Try loading WP test suite
$wp_tests_dir = getenv( 'WP_TESTS_DIR' ) ?: '/tmp/wordpress-tests-lib';
if ( file_exists( $wp_tests_dir . '/includes/functions.php' ) ) {
    require_once $wp_tests_dir . '/includes/functions.php';
    function _manually_load_plugin() {
        require dirname( __DIR__ ) . '/wp-sms-hub.php';
    }
    tests_add_filter( 'muplugins_loaded', '_manually_load_plugin' );
    require $wp_tests_dir . '/includes/bootstrap.php';
} else {
    // Standalone mode - define WP stubs
    define( 'ABSPATH', dirname( __DIR__ ) . '/' );
    define( 'WPSMSHUB_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
    define( 'WPSMSHUB_PLUGIN_URL', 'http://localhost/' );
    define( 'WPSMSHUB_PLUGIN_FILE', dirname( __DIR__ ) . '/wp-sms-hub.php' );
    define( 'WPSMSHUB_VERSION', '1.0.0' );
}
