<?php
namespace WPSMSHub\Providers;

if ( ! defined( 'ABSPATH' ) ) exit;

class KudiSMS extends Provider_Base {
    public function get_key(): string   { return 'kudisms'; }
    public function get_label(): string { return 'KudiSMS'; }

    public function get_settings_fields(): array {
        return [
            [ 'key' => 'api_key',   'label' => 'API Key',    'type' => 'password', 'required' => true ],
            [ 'key' => 'sender_id', 'label' => 'Sender ID',  'type' => 'text',     'required' => false, 'placeholder' => 'e.g. MyBrand (max 11 chars)' ],
        ];
    }

    public function send( string $to, string $message, array $args = [] ): array {
        $s = $this->settings();
        $r = $this->http_post_json( 'https://app.kudisms.net/api/v2/sms/send', [
            'sender'    => $args['sender_id'] ?? $s['sender_id'] ?? 'KudiSMS',
            'recipient' => $to,
            'message'   => $message,
        ], [
            'Authorization' => 'Bearer ' . ( $s['api_key'] ?? '' ),
        ] );

        // Fallback: try the legacy corporate endpoint if v2 returns 404
        if ( ! $r['ok'] && ( $r['code'] ?? 0 ) === 404 ) {
            $r = $this->http_get( 'https://app.kudisms.net/api/corporate', [
                'token'   => $s['api_key'] ?? '',
                'message' => $message,
                'gateway' => 'direct-refund',
                'mobile'  => $to,
                'sender'  => $args['sender_id'] ?? $s['sender_id'] ?? 'KudiSMS',
            ] );
            $ok = $r['ok'] && ( is_string( $r['body'] ) ? str_contains( $r['body'], '1701' ) : false );
            return [
                'success'    => $ok,
                'message_id' => null,
                'cost'       => null,
                'error'      => $ok ? null : ( $r['raw'] ?? $r['error'] ?? 'Unknown error' ),
            ];
        }

        $ok  = $r['ok'] && ( ( $r['body']['status'] ?? '' ) === 'success' || ! empty( $r['body']['data']['message_id'] ) );
        $mid = $r['body']['data']['message_id'] ?? $r['body']['message_id'] ?? null;
        return [
            'success'    => $ok,
            'message_id' => $mid,
            'cost'       => $r['body']['data']['cost'] ?? null,
            'error'      => $ok ? null : ( $r['body']['message'] ?? $r['raw'] ?? $r['error'] ?? 'Unknown error' ),
        ];
    }

    public function get_balance(): array {
        $s = $this->settings();
        $r = $this->http_get( 'https://app.kudisms.net/api/v2/balance', [], [
            'Authorization' => 'Bearer ' . ( $s['api_key'] ?? '' ),
        ] );
        if ( $r['ok'] ) {
            return [ 'supported' => true, 'balance' => $r['body']['data']['balance'] ?? $r['body']['balance'] ?? null, 'currency' => 'NGN' ];
        }
        // Fallback to legacy
        $r = $this->http_get( 'https://app.kudisms.net/api/balance', [ 'token' => $s['api_key'] ?? '' ] );
        return [ 'supported' => true, 'balance' => $r['body'] ?? null, 'raw' => $r['raw'] ?? null ];
    }
}
