<?php
namespace WPSMSHub;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Multi-Tenant Sub-Accounts management.
 * Each sub-account gets its own API key with usage limits.
 */
class Sub_Accounts {

    private static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'smshub_sub_accounts';
    }

    /**
     * Create a new sub-account.
     */
    public static function create( array $data ): int|false {
        global $wpdb;
        $api_key = self::generate_api_key();
        $res = $wpdb->insert( self::table(), [
            'name'          => sanitize_text_field( $data['name'] ?? '' ),
            'api_key'       => $api_key,
            'daily_limit'   => (int) ( $data['daily_limit'] ?? 100 ),
            'monthly_limit' => (int) ( $data['monthly_limit'] ?? 3000 ),
            'total_sent'    => 0,
            'status'        => 'active',
            'created_at'    => current_time( 'mysql' ),
        ] );
        return $res ? (int) $wpdb->insert_id : false;
    }

    /**
     * Get a sub-account by ID.
     */
    public static function get( int $id ): ?array {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE id = %d", $id
        ), ARRAY_A );
        return $row ?: null;
    }

    /**
     * Get all sub-accounts.
     */
    public static function get_all(): array {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT * FROM " . self::table() . " ORDER BY created_at DESC",
            ARRAY_A
        );
    }

    /**
     * Update a sub-account.
     */
    public static function update( int $id, array $data ): bool {
        global $wpdb;
        $allowed = [ 'name', 'daily_limit', 'monthly_limit', 'status' ];
        $update  = [];
        foreach ( $allowed as $key ) {
            if ( isset( $data[ $key ] ) ) {
                $update[ $key ] = $data[ $key ];
            }
        }
        if ( empty( $update ) ) return false;
        return (bool) $wpdb->update( self::table(), $update, [ 'id' => $id ] );
    }

    /**
     * Delete a sub-account.
     */
    public static function delete( int $id ): bool {
        global $wpdb;
        return (bool) $wpdb->delete( self::table(), [ 'id' => $id ], [ '%d' ] );
    }

    /**
     * Get a sub-account by API key.
     */
    public static function get_by_api_key( string $api_key ): ?array {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE api_key = %s AND status = 'active'",
            $api_key
        ), ARRAY_A );
        return $row ?: null;
    }

    /**
     * Increment the usage counter for a sub-account.
     */
    public static function increment_usage( int $id ): bool {
        global $wpdb;
        return (bool) $wpdb->query( $wpdb->prepare(
            "UPDATE " . self::table() . " SET total_sent = total_sent + 1 WHERE id = %d",
            $id
        ) );
    }

    /**
     * Get usage statistics for a sub-account.
     * Returns daily and monthly send counts from the log table.
     */
    public static function get_usage( int $id ): array {
        global $wpdb;
        $account = self::get( $id );
        if ( ! $account ) return [ 'daily' => 0, 'monthly' => 0, 'total' => 0 ];

        $log_table = $wpdb->prefix . 'smshub_log';

        $daily = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$log_table} WHERE trigger_src LIKE %s AND DATE(created_at) = CURDATE()",
            'subaccount:' . $id . ':%'
        ) );

        $monthly = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$log_table} WHERE trigger_src LIKE %s AND MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())",
            'subaccount:' . $id . ':%'
        ) );

        return [
            'daily'   => $daily,
            'monthly' => $monthly,
            'total'   => (int) $account['total_sent'],
        ];
    }

    /**
     * Check if a sub-account has exceeded its rate limits.
     */
    public static function check_limits( int $id ): array {
        $account = self::get( $id );
        if ( ! $account ) return [ 'allowed' => false, 'reason' => 'Account not found' ];
        if ( $account['status'] !== 'active' ) return [ 'allowed' => false, 'reason' => 'Account suspended' ];

        $usage = self::get_usage( $id );

        if ( $account['daily_limit'] > 0 && $usage['daily'] >= $account['daily_limit'] ) {
            return [ 'allowed' => false, 'reason' => 'Daily limit reached' ];
        }
        if ( $account['monthly_limit'] > 0 && $usage['monthly'] >= $account['monthly_limit'] ) {
            return [ 'allowed' => false, 'reason' => 'Monthly limit reached' ];
        }

        return [ 'allowed' => true, 'reason' => '' ];
    }

    /**
     * Generate a unique 32-character hex API key.
     */
    private static function generate_api_key(): string {
        return bin2hex( random_bytes( 16 ) );
    }
}
