<?php
namespace WPSMSHub;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * SMS Templates - Save and reuse message templates.
 */
class Templates {
    private static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'smshub_templates';
    }

    public static function create( array $data ): int|false {
        global $wpdb;
        $res = $wpdb->insert( self::table(), [
            'name'       => sanitize_text_field( $data['name'] ?? '' ),
            'category'   => sanitize_text_field( $data['category'] ?? 'General' ),
            'body'       => sanitize_textarea_field( $data['body'] ?? '' ),
            'created_at' => current_time( 'mysql' ),
        ] );
        return $res ? (int) $wpdb->insert_id : false;
    }

    public static function update( int $id, array $data ): bool {
        global $wpdb;
        return (bool) $wpdb->update( self::table(), [
            'name'     => sanitize_text_field( $data['name'] ?? '' ),
            'category' => sanitize_text_field( $data['category'] ?? 'General' ),
            'body'     => sanitize_textarea_field( $data['body'] ?? '' ),
        ], [ 'id' => $id ] );
    }

    public static function delete( int $id ): bool {
        global $wpdb;
        return (bool) $wpdb->delete( self::table(), [ 'id' => $id ], [ '%d' ] );
    }

    public static function get( int $id ): ?array {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE id = %d", $id
        ), ARRAY_A ) ?: null;
    }

    public static function get_all( ?string $category = null ): array {
        global $wpdb;
        $table = self::table();
        if ( $category ) {
            return $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM {$table} WHERE category = %s ORDER BY name ASC", $category
            ), ARRAY_A );
        }
        return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY category ASC, name ASC", ARRAY_A );
    }

    public static function get_categories(): array {
        global $wpdb;
        return $wpdb->get_col( "SELECT DISTINCT category FROM " . self::table() . " ORDER BY category ASC" );
    }
}
