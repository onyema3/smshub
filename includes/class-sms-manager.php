<?php
namespace WPSMSHub;

if ( ! defined( 'ABSPATH' ) ) exit;

class SMS_Manager {
    private static $providers = [];

    /** Default retry settings */
    const MAX_RETRIES     = 3;
    const RETRY_DELAYS    = [ 5, 30, 120 ]; // seconds: 5s, 30s, 2min

    public function __construct() {
        add_action( 'wp_sms_hub_loaded', [ $this, 'register_built_in_providers' ], 5 );
    }

    public function register_built_in_providers() {
        $classes = [
            'KudiSMS'        => Providers\KudiSMS::class,
            'SMSNigeria'     => Providers\SMSNigeria::class,
            'Termii'         => Providers\Termii::class,
            'Twilio'         => Providers\Twilio::class,
            'InfoBip'        => Providers\InfoBip::class,
            'Vonage'         => Providers\Vonage::class,
            'AfricasTalking' => Providers\AfricasTalking::class,
            'BulkSMSNigeria' => Providers\BulkSMSNigeria::class,
            'Multitexter'    => Providers\Multitexter::class,
            'SmartSMSSolutions' => Providers\SmartSMSSolutions::class,
        ];
        foreach ( $classes as $key => $class ) {
            self::register_provider( $key, new $class() );
        }
        do_action( 'wp_sms_hub_register_providers' );
    }

    public static function register_provider( string $key, Providers\Provider_Base $provider ) {
        self::$providers[ $key ] = $provider;
    }

    public static function get_providers(): array {
        return self::$providers;
    }

    public static function get_provider( ?string $key = null ): ?Providers\Provider_Base {
        if ( ! $key ) {
            $key = get_option( 'wpsmshub_active_provider', '' );
        }
        return self::$providers[ $key ] ?? null;
    }

    /**
     * Send SMS to one or more recipients.
     * For bulk (>5 recipients), auto-queues for background processing.
     *
     * @param string|array $to       Phone number(s)
     * @param string       $message  Message body
     * @param array        $args     Optional: provider, sender_id, trigger_src, use_queue
     * @return array { success, results[] }
     */
    public static function send( $to, string $message, array $args = [] ): array {
        $recipients = is_array( $to ) ? $to : array_filter( array_map( 'trim', explode( ',', $to ) ) );
        $use_queue  = $args['use_queue'] ?? ( count( $recipients ) > 5 );

        // For bulk sends, use the queue
        if ( $use_queue && count( $recipients ) > 1 ) {
            $ids = Queue::enqueue_bulk( $recipients, $message, $args );
            return [
                'success'  => true,
                'queued'   => true,
                'queue_ids' => $ids,
                'message'  => sprintf( '%d messages queued for background delivery.', count( $ids ) ),
            ];
        }

        // Synchronous send for small batches
        $provider_key = $args['provider'] ?? get_option( 'wpsmshub_active_provider', '' );
        $provider     = self::get_provider( $provider_key );

        if ( ! $provider ) {
            return [ 'success' => false, 'error' => 'No SMS provider configured.' ];
        }

        $results = [];
        foreach ( $recipients as $number ) {
            $number  = self::normalize_phone( $number );
            $result  = self::send_with_retry( $provider, $provider_key, $number, $message, $args );
            $results[] = array_merge( $result, [ 'recipient' => $number ] );
        }

        $all_ok = ! in_array( false, array_column( $results, 'success' ) );
        do_action( 'wp_sms_hub_after_send', $results, $message, $args );

        return [ 'success' => $all_ok, 'results' => $results ];
    }

    /**
     * Send a single message (used by Queue processor).
     * Includes retry and failover logic.
     */
    public static function send_single( string $to, string $message, array $args = [] ): array {
        $provider_key = $args['provider'] ?? get_option( 'wpsmshub_active_provider', '' );
        $provider     = self::get_provider( $provider_key );

        if ( ! $provider ) {
            return [ 'success' => false, 'error' => 'No SMS provider configured.' ];
        }

        return self::send_with_retry( $provider, $provider_key, $to, $message, $args );
    }

    /**
     * Core send logic with retry + failover.
     */
    private static function send_with_retry(
        Providers\Provider_Base $provider,
        string $provider_key,
        string $to,
        string $message,
        array $args
    ): array {
        $max_retries   = (int) get_option( 'wpsmshub_max_retries', self::MAX_RETRIES );
        $failover_key  = get_option( 'wpsmshub_failover_provider', '' );
        $result        = null;
        $last_error    = '';

        // Attempt with primary provider (with retries)
        for ( $attempt = 0; $attempt <= $max_retries; $attempt++ ) {
            if ( $attempt > 0 ) {
                // Exponential backoff between retries
                $delay = self::RETRY_DELAYS[ min( $attempt - 1, count( self::RETRY_DELAYS ) - 1 ) ];
                sleep( min( $delay, 10 ) ); // Cap at 10s for sync sends
            }

            $result = $provider->send( $to, $message, $args );

            if ( $result['success'] ) {
                // Log success
                Log::add( [
                    'provider'    => $provider_key,
                    'recipient'   => $to,
                    'sender_id'   => $args['sender_id'] ?? $provider->get_setting( 'sender_id' ),
                    'message'     => $message,
                    'status'      => 'sent',
                    'provider_id' => $result['message_id'] ?? null,
                    'cost'        => $result['cost'] ?? null,
                    'trigger_src' => $args['trigger_src'] ?? null,
                    'error_msg'   => $attempt > 0 ? "Succeeded on retry #{$attempt}" : null,
                ] );
                return $result;
            }

            $last_error = $result['error'] ?? 'Unknown error';

            // Don't retry on certain errors (invalid number, auth failure)
            if ( self::is_permanent_error( $last_error ) ) {
                break;
            }
        }

        // Failover: try backup provider if configured and different from primary
        if ( $failover_key && $failover_key !== $provider_key ) {
            $failover = self::get_provider( $failover_key );
            if ( $failover ) {
                $fo_result = $failover->send( $to, $message, $args );
                if ( $fo_result['success'] ) {
                    Log::add( [
                        'provider'    => $failover_key,
                        'recipient'   => $to,
                        'sender_id'   => $args['sender_id'] ?? $failover->get_setting( 'sender_id' ),
                        'message'     => $message,
                        'status'      => 'sent',
                        'provider_id' => $fo_result['message_id'] ?? null,
                        'cost'        => $fo_result['cost'] ?? null,
                        'trigger_src' => $args['trigger_src'] ?? null,
                        'error_msg'   => "Failover from {$provider_key}: {$last_error}",
                    ] );
                    return $fo_result;
                }
                $last_error = $fo_result['error'] ?? $last_error;
            }
        }

        // All attempts and failover failed - log the failure
        Log::add( [
            'provider'    => $provider_key,
            'recipient'   => $to,
            'sender_id'   => $args['sender_id'] ?? $provider->get_setting( 'sender_id' ),
            'message'     => $message,
            'status'      => 'failed',
            'provider_id' => $result['message_id'] ?? null,
            'cost'        => null,
            'trigger_src' => $args['trigger_src'] ?? null,
            'error_msg'   => $last_error . ( $failover_key ? " (failover to {$failover_key} also failed)" : '' ),
        ] );

        return $result ?? [ 'success' => false, 'error' => $last_error ];
    }

    /**
     * Check if error is permanent (no point retrying).
     */
    private static function is_permanent_error( string $error ): bool {
        $permanent_patterns = [
            'invalid.*number', 'invalid.*phone', 'blacklisted',
            'unauthorized', 'invalid.*key', 'invalid.*token',
            'authentication', 'forbidden', 'insufficient.*balance',
            'insufficient.*credit', 'no.*funds',
        ];
        $error_lower = strtolower( $error );
        foreach ( $permanent_patterns as $pattern ) {
            if ( preg_match( "/{$pattern}/i", $error_lower ) ) return true;
        }
        return false;
    }

    public static function normalize_phone( string $phone ): string {
        $phone = preg_replace( '/[^\d+]/', '', $phone );
        // Nigerian local numbers: 080... -> +23480...
        if ( preg_match( '/^0[7-9][01]\d{8}$/', $phone ) ) {
            $phone = '+234' . ltrim( $phone, '0' );
        }
        return $phone;
    }
}
