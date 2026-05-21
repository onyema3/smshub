<?php
namespace WPSMSHub;

if ( ! defined( 'ABSPATH' ) ) exit;

class SMS_Manager {
    private static $providers = [];

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
            'BulkSMS'        => Providers\BulkSMS::class,
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
     * Send an SMS message.
     *
     * @param string|array $to       Phone number(s)
     * @param string       $message  Message body
     * @param array        $args     Optional: provider, sender_id, trigger_src
     * @return array { success, results[] }
     */
    public static function send( $to, string $message, array $args = [] ): array {
        $provider_key = $args['provider'] ?? get_option( 'wpsmshub_active_provider', '' );
        $provider     = self::get_provider( $provider_key );

        if ( ! $provider ) {
            return [ 'success' => false, 'error' => 'No SMS provider configured.' ];
        }

        $recipients = is_array( $to ) ? $to : [ $to ];
        $results    = [];

        foreach ( $recipients as $number ) {
            $number = self::normalize_phone( $number );
            $result = $provider->send( $number, $message, $args );

            // Log it
            Log::add( [
                'provider'    => $provider_key,
                'recipient'   => $number,
                'sender_id'   => $args['sender_id'] ?? $provider->get_setting( 'sender_id' ),
                'message'     => $message,
                'status'      => $result['success'] ? 'sent' : 'failed',
                'provider_id' => $result['message_id'] ?? null,
                'cost'        => $result['cost'] ?? null,
                'trigger_src' => $args['trigger_src'] ?? null,
                'error_msg'   => $result['error'] ?? null,
            ] );

            $results[] = array_merge( $result, [ 'recipient' => $number ] );
        }

        $all_ok = ! in_array( false, array_column( $results, 'success' ) );
        do_action( 'wp_sms_hub_after_send', $results, $message, $args );

        return [ 'success' => $all_ok, 'results' => $results ];
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
