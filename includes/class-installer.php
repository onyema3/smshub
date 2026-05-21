<?php
namespace WPSMSHub;

if ( ! defined( 'ABSPATH' ) ) exit;

class Installer {
    public static function activate() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();

        // SMS Log table
        $sql1 = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}smshub_log (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            provider    VARCHAR(50)     NOT NULL,
            direction   ENUM('outbound','inbound') NOT NULL DEFAULT 'outbound',
            recipient   VARCHAR(20)     NOT NULL,
            sender_id   VARCHAR(20)     DEFAULT NULL,
            message     TEXT            NOT NULL,
            status      VARCHAR(20)     NOT NULL DEFAULT 'pending',
            provider_id VARCHAR(100)    DEFAULT NULL,
            cost        DECIMAL(10,4)   DEFAULT NULL,
            trigger_src VARCHAR(100)    DEFAULT NULL,
            error_msg   TEXT            DEFAULT NULL,
            created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_status (status),
            KEY idx_created (created_at),
            KEY idx_recipient (recipient(15))
        ) $charset;";

        // Contacts table
        $sql2 = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}smshub_contacts (
            id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name       VARCHAR(100)    NOT NULL,
            phone      VARCHAR(20)     NOT NULL,
            group_name VARCHAR(100)    DEFAULT 'Default',
            meta       LONGTEXT        DEFAULT NULL,
            created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_phone (phone),
            KEY idx_group (group_name)
        ) $charset;";

        // Trigger rules table
        $sql3 = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}smshub_triggers (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name        VARCHAR(200)    NOT NULL,
            event       VARCHAR(100)    NOT NULL,
            provider    VARCHAR(50)     DEFAULT NULL,
            recipients  TEXT            NOT NULL,
            sender_id   VARCHAR(20)     DEFAULT NULL,
            message_tpl TEXT            NOT NULL,
            active      TINYINT(1)      NOT NULL DEFAULT 1,
            created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_event (event),
            KEY idx_active (active)
        ) $charset;";

        // Message Queue table
        $sql4 = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}smshub_queue (
            id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            recipient    VARCHAR(20)     NOT NULL,
            message      TEXT            NOT NULL,
            provider     VARCHAR(50)     DEFAULT '',
            sender_id    VARCHAR(20)     DEFAULT '',
            trigger_src  VARCHAR(100)    DEFAULT '',
            status       VARCHAR(20)     NOT NULL DEFAULT 'queued',
            attempts     TINYINT         NOT NULL DEFAULT 0,
            max_attempts TINYINT         NOT NULL DEFAULT 3,
            last_error   TEXT            DEFAULT NULL,
            scheduled_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            sent_at      DATETIME        DEFAULT NULL,
            created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_status_scheduled (status, scheduled_at),
            KEY idx_trigger (trigger_src)
        ) $charset;";

        // SMS Templates table
        $sql5 = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}smshub_templates (
            id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name       VARCHAR(200)    NOT NULL,
            category   VARCHAR(100)    DEFAULT 'General',
            body       TEXT            NOT NULL,
            created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_category (category)
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql1 );
        dbDelta( $sql2 );
        dbDelta( $sql3 );
        dbDelta( $sql4 );
        dbDelta( $sql5 );

        add_option( 'wpsmshub_version', WPSMSHUB_VERSION );
        add_option( 'wpsmshub_active_provider', '' );
        add_option( 'wpsmshub_failover_provider', '' );
        add_option( 'wpsmshub_max_retries', 3 );
        add_option( 'wpsmshub_providers', [] );
    }

    public static function deactivate() {
        // Keep data on deactivate; only remove on uninstall
    }
}
