<?php
namespace WPSMSHub;

if ( ! defined( 'ABSPATH' ) ) exit;

class Log {
    private static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'smshub_log';
    }

    public static function add( array $data ): int {
        global $wpdb;
        $wpdb->insert( self::table(), [
            'provider'    => $data['provider']    ?? '',
            'direction'   => $data['direction']   ?? 'outbound',
            'recipient'   => $data['recipient']   ?? '',
            'sender_id'   => $data['sender_id']   ?? null,
            'message'     => $data['message']     ?? '',
            'status'      => $data['status']      ?? 'pending',
            'provider_id' => $data['provider_id'] ?? null,
            'cost'        => $data['cost']        ?? null,
            'trigger_src' => $data['trigger_src'] ?? null,
            'error_msg'   => $data['error_msg']   ?? null,
            'created_at'  => current_time( 'mysql' ),
        ] );
        return (int) $wpdb->insert_id;
    }

    public static function get_list( array $args = [] ): array {
        global $wpdb;
        $table   = self::table();
        $limit   = (int) ( $args['per_page'] ?? 50 );
        $offset  = (int) ( $args['offset']   ?? 0 );
        $where   = '1=1';
        $values  = [];

        if ( ! empty( $args['status'] ) ) {
            $where  .= ' AND status = %s';
            $values[] = $args['status'];
        }
        if ( ! empty( $args['provider'] ) ) {
            $where  .= ' AND provider = %s';
            $values[] = $args['provider'];
        }
        if ( ! empty( $args['search'] ) ) {
            $where  .= ' AND (recipient LIKE %s OR message LIKE %s)';
            $values[] = '%' . $wpdb->esc_like( $args['search'] ) . '%';
            $values[] = '%' . $wpdb->esc_like( $args['search'] ) . '%';
        }

        $values[] = $limit;
        $values[] = $offset;

        $sql = "SELECT * FROM {$table} WHERE {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d";
        if ( $values ) {
            $sql = $wpdb->prepare( $sql, $values );
        }

        return [
            'items' => $wpdb->get_results( $sql, ARRAY_A ),
            'total' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where}" ),
        ];
    }

    public static function get_stats(): array {
        global $wpdb;
        $table = self::table();
        return [
            'total'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ),
            'sent'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status='sent'" ),
            'failed'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status='failed'" ),
            'today'   => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE DATE(created_at)=%s", current_time('Y-m-d') ) ),
        ];
    }

    public static function delete_old( int $days = 90 ): int {
        global $wpdb;
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}smshub_log WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $days
        ) );
        return $wpdb->rows_affected;
    }

    public static function update_status( int $id, string $status ): bool {
        global $wpdb;
        return (bool) $wpdb->update( self::table(), [ 'status' => $status ], [ 'id' => $id ] );
    }

    public static function get_delivery_stats(): array {
        global $wpdb;
        $table = self::table();
        $total     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE direction='outbound'" );
        $delivered = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status='delivered'" );
        $sent      = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status='sent'" );
        $failed    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status='failed'" );
        return [
            'total'         => $total,
            'delivered'     => $delivered,
            'sent'          => $sent,
            'failed'        => $failed,
            'delivery_rate' => $total > 0 ? round( ( $delivered + $sent ) / $total * 100, 1 ) : 0,
        ];
    }
}
