<?php
namespace WPSMSHub;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Advanced Reporting - Email digests, cost forecasting, provider comparison, peak hours.
 */
class Reports {

    public function __construct() {
        add_action( 'smshub_weekly_digest', [ $this, 'send_weekly_digest' ] );
        if ( ! wp_next_scheduled( 'smshub_weekly_digest' ) ) {
            wp_schedule_event( strtotime( 'next monday 9:00' ), 'weekly', 'smshub_weekly_digest' );
        }
    }

    // ── Weekly Email Digest ─────────────────────────────────────────────
    public function send_weekly_digest() {
        $enabled = get_option( 'wpsmshub_weekly_digest_enabled', 'yes' );
        if ( $enabled !== 'yes' ) return;

        $email = get_option( 'wpsmshub_digest_email', get_option( 'admin_email' ) );
        $stats = self::get_weekly_summary();

        $subject = sprintf( '[%s] SMS Hub Weekly Report - %s', get_bloginfo( 'name' ), date( 'M d, Y' ) );
        $body = self::build_digest_email( $stats );

        wp_mail( $email, $subject, $body, [ 'Content-Type: text/html; charset=UTF-8' ] );
    }

    public static function get_weekly_summary(): array {
        global $wpdb;
        $table = $wpdb->prefix . 'smshub_log';
        $week_ago = gmdate( 'Y-m-d H:i:s', strtotime( '-7 days' ) );

        return [
            'total'     => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE created_at >= %s", $week_ago ) ),
            'sent'      => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status IN ('sent','delivered') AND created_at >= %s", $week_ago ) ),
            'failed'    => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status='failed' AND created_at >= %s", $week_ago ) ),
            'cost'      => (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(cost),0) FROM {$table} WHERE created_at >= %s AND cost IS NOT NULL", $week_ago ) ),
            'providers' => $wpdb->get_results( $wpdb->prepare(
                "SELECT provider, COUNT(*) as total, SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END) as failed FROM {$table} WHERE created_at >= %s GROUP BY provider", $week_ago
            ), ARRAY_A ),
        ];
    }

    private static function build_digest_email( array $stats ): string {
        $success_rate = $stats['total'] > 0 ? round( $stats['sent'] / $stats['total'] * 100, 1 ) : 0;
        $html = '<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;padding:20px;">';
        $html .= '<h2 style="color:#7c6aff;">SMS Hub Weekly Report</h2>';
        $html .= '<p>Here is your SMS activity for the past 7 days:</p>';
        $html .= '<table style="width:100%;border-collapse:collapse;margin:20px 0;">';
        $html .= '<tr><td style="padding:8px;border-bottom:1px solid #eee;"><strong>Total Messages</strong></td><td style="padding:8px;border-bottom:1px solid #eee;">' . number_format( $stats['total'] ) . '</td></tr>';
        $html .= '<tr><td style="padding:8px;border-bottom:1px solid #eee;"><strong>Delivered</strong></td><td style="padding:8px;border-bottom:1px solid #eee;color:#00e4b8;">' . number_format( $stats['sent'] ) . '</td></tr>';
        $html .= '<tr><td style="padding:8px;border-bottom:1px solid #eee;"><strong>Failed</strong></td><td style="padding:8px;border-bottom:1px solid #eee;color:#ff5277;">' . number_format( $stats['failed'] ) . '</td></tr>';
        $html .= '<tr><td style="padding:8px;border-bottom:1px solid #eee;"><strong>Success Rate</strong></td><td style="padding:8px;border-bottom:1px solid #eee;">' . $success_rate . '%</td></tr>';
        $html .= '<tr><td style="padding:8px;"><strong>Total Cost</strong></td><td style="padding:8px;">' . number_format( $stats['cost'], 2 ) . '</td></tr>';
        $html .= '</table>';

        if ( ! empty( $stats['providers'] ) ) {
            $html .= '<h3 style="color:#7c6aff;">Provider Breakdown</h3><table style="width:100%;border-collapse:collapse;">';
            $html .= '<tr style="background:#f5f7fa;"><th style="padding:8px;text-align:left;">Provider</th><th style="padding:8px;">Sent</th><th style="padding:8px;">Failed</th><th style="padding:8px;">Rate</th></tr>';
            foreach ( $stats['providers'] as $p ) {
                $rate = $p['total'] > 0 ? round( ( $p['total'] - $p['failed'] ) / $p['total'] * 100, 1 ) : 0;
                $html .= '<tr><td style="padding:8px;border-bottom:1px solid #eee;">' . esc_html( $p['provider'] ) . '</td>';
                $html .= '<td style="padding:8px;border-bottom:1px solid #eee;text-align:center;">' . $p['total'] . '</td>';
                $html .= '<td style="padding:8px;border-bottom:1px solid #eee;text-align:center;color:#ff5277;">' . $p['failed'] . '</td>';
                $html .= '<td style="padding:8px;border-bottom:1px solid #eee;text-align:center;">' . $rate . '%</td></tr>';
            }
            $html .= '</table>';
        }

        $html .= '<p style="margin-top:20px;"><a href="' . admin_url( 'admin.php?page=wp-sms-hub' ) . '" style="background:#7c6aff;color:#fff;padding:10px 20px;text-decoration:none;border-radius:6px;">View Dashboard</a></p>';
        $html .= '</div>';
        return $html;
    }

    // ── Cost Forecasting ────────────────────────────────────────────────
    public static function get_cost_forecast(): array {
        global $wpdb;
        $table = $wpdb->prefix . 'smshub_log';

        $days_elapsed = (int) date( 'j' );
        $days_in_month = (int) date( 't' );
        $month_cost = (float) $wpdb->get_var(
            "SELECT COALESCE(SUM(cost),0) FROM {$table} WHERE YEAR(created_at)=YEAR(NOW()) AND MONTH(created_at)=MONTH(NOW()) AND cost IS NOT NULL"
        );

        $daily_avg = $days_elapsed > 0 ? $month_cost / $days_elapsed : 0;
        $projected = $daily_avg * $days_in_month;

        return [
            'spent_so_far'   => round( $month_cost, 2 ),
            'daily_average'  => round( $daily_avg, 2 ),
            'projected_month' => round( $projected, 2 ),
            'days_elapsed'   => $days_elapsed,
            'days_remaining' => $days_in_month - $days_elapsed,
        ];
    }

    // ── Provider Performance Comparison ─────────────────────────────────
    public static function get_provider_performance( int $days = 30 ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'smshub_log';
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT provider,
                    COUNT(*) as total,
                    SUM(CASE WHEN status IN ('sent','delivered') THEN 1 ELSE 0 END) as delivered,
                    SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END) as failed,
                    COALESCE(SUM(cost),0) as total_cost,
                    ROUND(AVG(CASE WHEN status IN ('sent','delivered') THEN 1 ELSE 0 END) * 100, 1) as success_rate
             FROM {$table}
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
             GROUP BY provider
             ORDER BY total DESC",
            $days
        ), ARRAY_A );
    }

    // ── Peak Hours Heatmap ──────────────────────────────────────────────
    public static function get_peak_hours( int $days = 30 ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'smshub_log';
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT DAYOFWEEK(created_at) as dow, HOUR(created_at) as hour, COUNT(*) as count
             FROM {$table}
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
             GROUP BY DAYOFWEEK(created_at), HOUR(created_at)
             ORDER BY dow, hour",
            $days
        ), ARRAY_A );

        // Format as 7x24 grid (dow 1=Sun, 7=Sat)
        $heatmap = array_fill( 1, 7, array_fill( 0, 24, 0 ) );
        foreach ( $rows as $r ) {
            $heatmap[ (int) $r['dow'] ][ (int) $r['hour'] ] = (int) $r['count'];
        }
        return $heatmap;
    }

    // ── Monthly Trends ──────────────────────────────────────────────────
    public static function get_monthly_trends( int $months = 6 ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'smshub_log';
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT DATE_FORMAT(created_at, '%%Y-%%m') as month,
                    COUNT(*) as total,
                    SUM(CASE WHEN status IN ('sent','delivered') THEN 1 ELSE 0 END) as sent,
                    SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END) as failed,
                    COALESCE(SUM(cost),0) as cost
             FROM {$table}
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL %d MONTH)
             GROUP BY DATE_FORMAT(created_at, '%%Y-%%m')
             ORDER BY month ASC",
            $months
        ), ARRAY_A );
    }
}
