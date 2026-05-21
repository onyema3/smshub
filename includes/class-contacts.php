<?php
namespace WPSMSHub;

if ( ! defined( 'ABSPATH' ) ) exit;

class Contacts {
    private static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'smshub_contacts';
    }

    public static function add( array $data ): int|false {
        global $wpdb;
        $res = $wpdb->insert( self::table(), [
            'name'       => sanitize_text_field( $data['name'] ?? '' ),
            'phone'      => sanitize_text_field( $data['phone'] ?? '' ),
            'group_name' => sanitize_text_field( $data['group'] ?? 'Default' ),
            'meta'       => ! empty( $data['meta'] ) ? wp_json_encode( $data['meta'] ) : null,
        ] );
        return $res ? (int) $wpdb->insert_id : false;
    }

    public static function get_list( array $args = [] ): array {
        global $wpdb;
        $table  = self::table();
        $limit  = (int) ( $args['per_page'] ?? 50 );
        $offset = (int) ( $args['offset']   ?? 0 );
        $where  = '1=1';
        $values = [];

        if ( ! empty( $args['group'] ) ) {
            $where  .= ' AND group_name = %s';
            $values[] = $args['group'];
        }
        if ( ! empty( $args['search'] ) ) {
            $where  .= ' AND (name LIKE %s OR phone LIKE %s)';
            $values[] = '%' . $wpdb->esc_like( $args['search'] ) . '%';
            $values[] = '%' . $wpdb->esc_like( $args['search'] ) . '%';
        }
        $values[] = $limit;
        $values[] = $offset;

        $sql = "SELECT * FROM {$table} WHERE {$where} ORDER BY name ASC LIMIT %d OFFSET %d";
        return [
            'items' => $wpdb->get_results( $wpdb->prepare( $sql, $values ), ARRAY_A ),
            'total' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where}" ),
        ];
    }

    public static function get_groups(): array {
        global $wpdb;
        return $wpdb->get_col( "SELECT DISTINCT group_name FROM {$wpdb->prefix}smshub_contacts ORDER BY group_name ASC" );
    }

    public static function delete( int $id ): bool {
        global $wpdb;
        return (bool) $wpdb->delete( self::table(), [ 'id' => $id ], [ '%d' ] );
    }

    public static function delete_by_phone( string $phone ): bool {
        global $wpdb;
        return (bool) $wpdb->delete( $wpdb->prefix . 'smshub_contacts', [ 'phone' => $phone ], [ '%s' ] );
    }

    public static function get_phones_by_group( string $group ): array {
        global $wpdb;
        return $wpdb->get_col( $wpdb->prepare(
            "SELECT phone FROM {$wpdb->prefix}smshub_contacts WHERE group_name = %s",
            $group
        ) );
    }

    public static function import_csv( string $csv_path ): array {
        $handle = fopen( $csv_path, 'r' );
        if ( ! $handle ) return [ 'imported' => 0, 'errors' => [ 'Cannot open file' ] ];
        $headers = fgetcsv( $handle );
        $imported = 0;
        $errors   = [];
        while ( ( $row = fgetcsv( $handle ) ) !== false ) {
            $data = array_combine( array_map( 'strtolower', $headers ), $row );
            $res  = self::add([
                'name'  => $data['name']  ?? '',
                'phone' => $data['phone'] ?? $data['mobile'] ?? '',
                'group' => $data['group'] ?? 'Imported',
            ]);
            if ( $res ) $imported++;
            else $errors[] = 'Duplicate or invalid: ' . ( $data['phone'] ?? 'unknown' );
        }
        fclose( $handle );
        return [ 'imported' => $imported, 'errors' => $errors ];
    }
}
