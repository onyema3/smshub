<?php
namespace WPSMSHub;

if ( ! defined( 'ABSPATH' ) ) exit;

class Rate_Limiter {
    public function __construct() {
        add_filter( 'wp_sms_hub_before_send', [ $this, 'check_limits' ], 10, 3 );
        add_action( 'wp_sms_hub_after_send', [ $this, 'track_usage' ], 10, 3 );
    }

    public function check_limits( $allow, string $to, array $args ): bool|string {
        // Check daily limit
        $daily_limit = (int) get_option( 'wpsmshub_daily_limit', 0 );
        if ( $daily_limit > 0 ) {
            $today_count = self::get_today_count();
            if ( $today_count >= $daily_limit ) {
                return 'Daily SMS limit reached (' . $daily_limit . ')';
            }
        }

        // Check monthly limit
        $monthly_limit = (int) get_option( 'wpsmshub_monthly_limit', 0 );
        if ( $monthly_limit > 0 ) {
            $month_count = self::get_month_count();
            if ( $month_count >= $monthly_limit ) {
                return 'Monthly SMS limit reached (' . $monthly_limit . ')';
            }
        }

        // Check cost alert threshold
        $cost_alert = (float) get_option( 'wpsmshub_cost_alert_threshold', 0 );
        if ( $cost_alert > 0 ) {
            $month_cost = self::get_month_cost();
            if ( $month_cost >= $cost_alert ) {
                // Send alert email to admin (once per day)
                self::maybe_send_cost_alert( $month_cost, $cost_alert );
            }
        }

        return $allow;
    }

    public function track_usage( array $results, string $message, array $args ) {
        // Increment daily counter
        $key = 'smshub_sent_' . date( 'Y-m-d' );
        $count = (int) get_transient( $key );
        set_transient( $key, $count + count( $results ), DAY_IN_SECONDS );
    }

    public static function get_today_count(): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}smshub_log WHERE DATE(created_at) = %s",
            current_time( 'Y-m-d' )
        ) );
    }

    public static function get_month_count(): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}smshub_log WHERE YEAR(created_at) = %d AND MONTH(created_at) = %d",
            date( 'Y' ), date( 'n' )
        ) );
    }

    public static function get_month_cost(): float {
        global $wpdb;
        return (float) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(cost), 0) FROM {$wpdb->prefix}smshub_log WHERE YEAR(created_at) = %d AND MONTH(created_at) = %d AND cost IS NOT NULL",
            date( 'Y' ), date( 'n' )
        ) );
    }

    private static function maybe_send_cost_alert( float $current, float $threshold ) {
        $last_alert = get_transient( 'smshub_cost_alert_sent' );
        if ( $last_alert ) return;

        $admin_email = get_option( 'admin_email' );
        $subject = '[WP SMS Hub] Cost Alert - Monthly spend reached ' . number_format( $current, 2 );
        $body = sprintf(
            "Your SMS spending this month has reached %s (threshold: %s).\n\nManage your limits at: %s",
            number_format( $current, 2 ),
            number_format( $threshold, 2 ),
            admin_url( 'admin.php?page=smshub-settings' )
        );
        wp_mail( $admin_email, $subject, $body );
        set_transient( 'smshub_cost_alert_sent', 1, DAY_IN_SECONDS );
    }

    public static function get_usage_stats(): array {
        return [
            'today'       => self::get_today_count(),
            'this_month'  => self::get_month_count(),
            'month_cost'  => self::get_month_cost(),
            'daily_limit' => (int) get_option( 'wpsmshub_daily_limit', 0 ),
            'monthly_limit' => (int) get_option( 'wpsmshub_monthly_limit', 0 ),
            'cost_alert'  => (float) get_option( 'wpsmshub_cost_alert_threshold', 0 ),
        ];
    }
}
