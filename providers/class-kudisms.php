<?php
namespace WPSMSHub\Providers;

if ( ! defined( 'ABSPATH' ) ) exit;

class KudiSMS extends Provider_Base {
    public function get_key(): string   { return 'kudisms'; }
    public function get_label(): string { return 'KudiSMS'; }

    public function get_settings_fields(): array {
        return [
            [ 'key' => 'api_key',   'label' => 'API Key',    'type' => 'password', 'required' => true ],
            [ 'key' => 'username',  'label' => 'Username',   'type' => 'text',     'required' => true ],
            [ 'key' => 'sender_id', 'label' => 'Sender ID',  'type' => 'text',     'required' => false, 'placeholder' => 'e.g. MyBrand' ],
        ];
    }

    public function send( string $to, string $message, array $args = [] ): array {
        $s = $this->settings();
        $r = $this->http_get( 'https://app.kudisms.net/api/corporate', [
            'username'  => $s['username'] ?? '',
            'password'  => $s['api_key']  ?? '',
            'message'   => $message,
            'gateway'   => 'direct-refund',
            'mobile'    => $to,
            'sender'    => $args['sender_id'] ?? $s['sender_id'] ?? 'SMS',
        ] );

        $ok = $r['ok'] && ( is_string($r['body']) ? str_contains($r['body'], '1701') : false );
        return [
            'success'    => $ok,
            'message_id' => null,
            'cost'       => null,
            'error'      => $ok ? null : ( $r['raw'] ?? $r['error'] ?? 'Unknown error' ),
        ];
    }

    public function get_balance(): array {
        $s = $this->settings();
        $r = $this->http_get( 'https://app.kudisms.net/api/balance', [
            'username' => $s['username'] ?? '',
            'password' => $s['api_key']  ?? '',
        ] );
        return [ 'supported' => true, 'balance' => $r['body'] ?? null, 'raw' => $r['raw'] ?? null ];
    }
}
