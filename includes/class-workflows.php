<?php
namespace WPSMSHub;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Workflow Automations - Conditions, delays, branching, multi-step sequences.
 * Stored as JSON workflow definitions, executed by WP Cron.
 */
class Workflows {
    const CRON_HOOK = 'smshub_process_workflows';

    public function __construct() {
        add_action( self::CRON_HOOK, [ $this, 'process_pending_steps' ] );
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time(), 'smshub_every_minute', self::CRON_HOOK );
        }
    }

    private static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'smshub_workflows';
    }

    private static function executions_table(): string {
        global $wpdb;
        return $wpdb->prefix . 'smshub_workflow_executions';
    }

    // ── CRUD ────────────────────────────────────────────────────────────
    public static function create( array $data ): int|false {
        global $wpdb;
        $res = $wpdb->insert( self::table(), [
            'name'       => sanitize_text_field( $data['name'] ?? '' ),
            'trigger_event' => sanitize_text_field( $data['trigger_event'] ?? '' ),
            'steps'      => wp_json_encode( $data['steps'] ?? [] ),
            'active'     => (int) ( $data['active'] ?? 1 ),
            'created_at' => current_time( 'mysql' ),
        ] );
        return $res ? (int) $wpdb->insert_id : false;
    }

    public static function update( int $id, array $data ): bool {
        global $wpdb;
        return (bool) $wpdb->update( self::table(), [
            'name'          => sanitize_text_field( $data['name'] ?? '' ),
            'trigger_event' => sanitize_text_field( $data['trigger_event'] ?? '' ),
            'steps'         => wp_json_encode( $data['steps'] ?? [] ),
            'active'        => (int) ( $data['active'] ?? 1 ),
        ], [ 'id' => $id ] );
    }

    public static function delete( int $id ): bool {
        global $wpdb;
        return (bool) $wpdb->delete( self::table(), [ 'id' => $id ], [ '%d' ] );
    }

    public static function get( int $id ): ?array {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE id = %d", $id
        ), ARRAY_A );
        if ( $row ) $row['steps'] = json_decode( $row['steps'], true ) ?: [];
        return $row;
    }

    public static function get_all(): array {
        global $wpdb;
        $rows = $wpdb->get_results( "SELECT * FROM " . self::table() . " ORDER BY created_at DESC", ARRAY_A );
        foreach ( $rows as &$row ) {
            $row['steps'] = json_decode( $row['steps'], true ) ?: [];
        }
        return $rows;
    }

    // ── Trigger a workflow ───────────────────────────────────────────────
    public static function trigger( string $event, array $context = [] ) {
        global $wpdb;
        $workflows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE trigger_event = %s AND active = 1",
            $event
        ), ARRAY_A );

        foreach ( $workflows as $wf ) {
            $steps = json_decode( $wf['steps'], true ) ?: [];
            if ( empty( $steps ) ) continue;

            // Start execution at step 0
            $wpdb->insert( self::executions_table(), [
                'workflow_id'  => $wf['id'],
                'current_step' => 0,
                'context'      => wp_json_encode( $context ),
                'status'       => 'running',
                'next_run_at'  => current_time( 'mysql' ),
                'created_at'   => current_time( 'mysql' ),
            ] );
        }
    }

    // ── Process pending workflow steps ───────────────────────────────────
    public function process_pending_steps() {
        global $wpdb;
        $table = self::executions_table();
        $now   = current_time( 'mysql' );

        $pending = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE status = 'running' AND next_run_at <= %s LIMIT 50",
            $now
        ), ARRAY_A );

        foreach ( $pending as $exec ) {
            $workflow = self::get( (int) $exec['workflow_id'] );
            if ( ! $workflow ) {
                $wpdb->update( $table, [ 'status' => 'failed' ], [ 'id' => $exec['id'] ] );
                continue;
            }

            $steps   = $workflow['steps'];
            $step_idx = (int) $exec['current_step'];
            $context = json_decode( $exec['context'], true ) ?: [];

            if ( $step_idx >= count( $steps ) ) {
                $wpdb->update( $table, [ 'status' => 'completed' ], [ 'id' => $exec['id'] ] );
                continue;
            }

            $step = $steps[ $step_idx ];
            $result = $this->execute_step( $step, $context );

            if ( $result === 'skip' ) {
                // Condition not met - skip to next step or end
                $next = $step_idx + 1;
            } elseif ( is_int( $result ) && $result > 0 ) {
                // Delay - schedule next run
                $wpdb->update( $table, [
                    'next_run_at' => gmdate( 'Y-m-d H:i:s', time() + $result ),
                    'current_step' => $step_idx + 1,
                ], [ 'id' => $exec['id'] ] );
                continue;
            } else {
                $next = $step_idx + 1;
            }

            // Move to next step
            if ( $next >= count( $steps ) ) {
                $wpdb->update( $table, [ 'status' => 'completed', 'current_step' => $next ], [ 'id' => $exec['id'] ] );
            } else {
                $wpdb->update( $table, [ 'current_step' => $next, 'next_run_at' => $now ], [ 'id' => $exec['id'] ] );
            }
        }
    }

    /**
     * Execute a single workflow step.
     * Returns: null (success, continue), 'skip' (condition failed), int (delay in seconds)
     */
    private function execute_step( array $step, array $context ) {
        $type = $step['type'] ?? '';

        switch ( $type ) {
            case 'send_sms':
                $to = $this->resolve_recipient( $step['to'] ?? '', $context );
                $message = $this->interpolate( $step['message'] ?? '', $context );
                if ( $to && $message ) {
                    SMS_Manager::send( $to, $message, [
                        'provider'    => $step['provider'] ?? null,
                        'sender_id'   => $step['sender_id'] ?? null,
                        'trigger_src' => 'workflow',
                    ] );
                }
                return null;

            case 'delay':
                $seconds = (int) ( $step['seconds'] ?? 0 );
                $minutes = (int) ( $step['minutes'] ?? 0 );
                $hours   = (int) ( $step['hours'] ?? 0 );
                $days    = (int) ( $step['days'] ?? 0 );
                return $seconds + ( $minutes * 60 ) + ( $hours * 3600 ) + ( $days * 86400 );

            case 'condition':
                $field = $context[ $step['field'] ?? '' ] ?? '';
                $op    = $step['operator'] ?? 'equals';
                $val   = $step['value'] ?? '';
                $met   = false;
                switch ( $op ) {
                    case 'equals': $met = ( $field == $val ); break;
                    case 'not_equals': $met = ( $field != $val ); break;
                    case 'contains': $met = str_contains( (string) $field, $val ); break;
                    case 'greater': $met = ( (float) $field > (float) $val ); break;
                    case 'less': $met = ( (float) $field < (float) $val ); break;
                }
                return $met ? null : 'skip';

            case 'add_tag':
                $phone = $context['customer_phone'] ?? $context['user_phone'] ?? '';
                if ( $phone ) {
                    global $wpdb;
                    $contact = $wpdb->get_row( $wpdb->prepare(
                        "SELECT id FROM {$wpdb->prefix}smshub_contacts WHERE phone = %s", $phone
                    ) );
                    if ( $contact ) Segments::add_tag( $contact->id, $step['tag'] ?? '' );
                }
                return null;

            case 'add_to_group':
                $phone = $context['customer_phone'] ?? $context['user_phone'] ?? '';
                if ( $phone ) {
                    global $wpdb;
                    $wpdb->update( $wpdb->prefix . 'smshub_contacts',
                        [ 'group_name' => sanitize_text_field( $step['group'] ?? 'Default' ) ],
                        [ 'phone' => $phone ]
                    );
                }
                return null;

            default:
                return null;
        }
    }

    private function resolve_recipient( string $to, array $context ): string {
        if ( str_starts_with( $to, '{' ) && str_ends_with( $to, '}' ) ) {
            $key = trim( $to, '{}' );
            return $context[ $key ] ?? '';
        }
        return $to;
    }

    private function interpolate( string $tpl, array $context ): string {
        foreach ( $context as $k => $v ) {
            $tpl = str_replace( '{' . $k . '}', (string) $v, $tpl );
        }
        return $tpl;
    }
}
