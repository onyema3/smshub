<?php
namespace WPSMSHub;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Smart Segmentation - Dynamic groups, tags, engagement scoring.
 */
class Segments {

    public function __construct() {
        add_action( 'wp_sms_hub_after_send', [ $this, 'update_engagement' ], 10, 3 );
    }

    // ── Tags ────────────────────────────────────────────────────────────
    public static function add_tag( int $contact_id, string $tag ): bool {
        global $wpdb;
        $tag = sanitize_text_field( strtolower( trim( $tag ) ) );
        if ( ! $tag ) return false;
        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}smshub_tags WHERE contact_id = %d AND tag = %s",
            $contact_id, $tag
        ) );
        if ( $exists ) return true;
        return (bool) $wpdb->insert( $wpdb->prefix . 'smshub_tags', [
            'contact_id' => $contact_id,
            'tag'        => $tag,
            'created_at' => current_time( 'mysql' ),
        ] );
    }

    public static function remove_tag( int $contact_id, string $tag ): bool {
        global $wpdb;
        return (bool) $wpdb->delete( $wpdb->prefix . 'smshub_tags', [
            'contact_id' => $contact_id,
            'tag'        => strtolower( trim( $tag ) ),
        ] );
    }

    public static function get_contact_tags( int $contact_id ): array {
        global $wpdb;
        return $wpdb->get_col( $wpdb->prepare(
            "SELECT tag FROM {$wpdb->prefix}smshub_tags WHERE contact_id = %d ORDER BY tag ASC",
            $contact_id
        ) );
    }

    public static function get_contacts_by_tag( string $tag ): array {
        global $wpdb;
        return $wpdb->get_col( $wpdb->prepare(
            "SELECT c.phone FROM {$wpdb->prefix}smshub_tags t
             JOIN {$wpdb->prefix}smshub_contacts c ON t.contact_id = c.id
             WHERE t.tag = %s",
            strtolower( trim( $tag ) )
        ) );
    }

    public static function get_all_tags(): array {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT tag, COUNT(*) as count FROM {$wpdb->prefix}smshub_tags GROUP BY tag ORDER BY count DESC",
            ARRAY_A
        );
    }

    // ── Dynamic Segments ────────────────────────────────────────────────
    public static function resolve_segment( array $rules ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'smshub_contacts';
        $where = [ '1=1' ];
        $values = [];

        foreach ( $rules as $rule ) {
            $field    = $rule['field'] ?? '';
            $operator = $rule['operator'] ?? 'equals';
            $value    = $rule['value'] ?? '';

            switch ( $field ) {
                case 'group':
                    $where[] = 'c.group_name = %s';
                    $values[] = $value;
                    break;
                case 'tag':
                    $where[] = "c.id IN (SELECT contact_id FROM {$wpdb->prefix}smshub_tags WHERE tag = %s)";
                    $values[] = strtolower( $value );
                    break;
                case 'last_sent_days':
                    $op = $operator === 'greater' ? '>' : '<';
                    $where[] = "c.phone IN (SELECT recipient FROM {$wpdb->prefix}smshub_log WHERE created_at " . ( $op === '>' ? '<' : '>' ) . " DATE_SUB(NOW(), INTERVAL %d DAY))";
                    $values[] = (int) $value;
                    break;
                case 'engagement_score':
                    $op = $operator === 'greater' ? '>=' : '<=';
                    $where[] = "c.id IN (SELECT contact_id FROM {$wpdb->prefix}smshub_tags WHERE tag LIKE 'score:%' AND CAST(SUBSTRING(tag, 7) AS UNSIGNED) {$op} %d)";
                    $values[] = (int) $value;
                    break;
                case 'created_after':
                    $where[] = 'c.created_at >= %s';
                    $values[] = $value;
                    break;
                case 'created_before':
                    $where[] = 'c.created_at <= %s';
                    $values[] = $value;
                    break;
            }
        }

        $sql = "SELECT c.phone FROM {$table} c WHERE " . implode( ' AND ', $where );
        if ( $values ) {
            $sql = $wpdb->prepare( $sql, ...$values );
        }
        return $wpdb->get_col( $sql );
    }

    // ── Engagement Scoring ──────────────────────────────────────────────
    public function update_engagement( array $results, string $message, array $args ) {
        global $wpdb;
        foreach ( $results as $r ) {
            if ( empty( $r['recipient'] ) ) continue;
            $contact = $wpdb->get_row( $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}smshub_contacts WHERE phone = %s",
                $r['recipient']
            ) );
            if ( ! $contact ) continue;

            // Increment engagement score
            $current_score = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT CAST(SUBSTRING(tag, 7) AS UNSIGNED) FROM {$wpdb->prefix}smshub_tags WHERE contact_id = %d AND tag LIKE 'score:%%'",
                $contact->id
            ) );
            $new_score = $current_score + ( $r['success'] ? 1 : 0 );

            // Remove old score tag, add new one
            $wpdb->delete( $wpdb->prefix . 'smshub_tags', [ 'contact_id' => $contact->id ], [ '%d' ] );
            $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$wpdb->prefix}smshub_tags WHERE contact_id = %d AND tag LIKE 'score:%%'",
                $contact->id
            ) );
            self::add_tag( $contact->id, 'score:' . $new_score );
        }
    }

    // ── Saved Segments CRUD ─────────────────────────────────────────────
    public static function save_segment( array $data ): int|false {
        global $wpdb;
        $res = $wpdb->insert( $wpdb->prefix . 'smshub_segments', [
            'name'       => sanitize_text_field( $data['name'] ?? '' ),
            'rules'      => wp_json_encode( $data['rules'] ?? [] ),
            'created_at' => current_time( 'mysql' ),
        ] );
        return $res ? (int) $wpdb->insert_id : false;
    }

    public static function get_saved_segments(): array {
        global $wpdb;
        $rows = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}smshub_segments ORDER BY name ASC", ARRAY_A );
        foreach ( $rows as &$row ) {
            $row['rules'] = json_decode( $row['rules'], true ) ?: [];
        }
        return $rows;
    }

    public static function delete_segment( int $id ): bool {
        global $wpdb;
        return (bool) $wpdb->delete( $wpdb->prefix . 'smshub_segments', [ 'id' => $id ], [ '%d' ] );
    }
}
