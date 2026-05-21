<?php
namespace WPSMSHub\Providers;

if ( ! defined( 'ABSPATH' ) ) exit;

class Termii extends Provider_Base {
    public function get_key(): string   { return 'termii'; }
    public function get_label(): string { return 'Termii'; }

    public function get_settings_fields(): array {
        return [
            [ 'key' => 'api_key',   'label' => 'API Key',    'type' => 'password', 'required' => true ],
            [ 'key' => 'sender_id', 'label' => 'Sender ID',  'type' => 'text',     'required' => false, 'placeholder' => 'e.g. CompanyName' ],
            [ 'key' => 'channel',   'label' => 'Channel',    'type' => 'select',   'required' => false,
              'options' => [ 'generic' => 'Generic', 'dnd' => 'DND', 'WhatsApp' => 'WhatsApp' ] ],
        ];
    }

    public function send( string $to, string $message, array $args = [] ): array {
        $s = $this->settings();
        $r = $this->http_post_json( 'https://v3.api.termii.com/api/sms/send', [
            'to'      => $to,
            'from'    => $args['sender_id'] ?? $s['sender_id'] ?? 'N-Alert',
            'sms'     => $message,
            'type'    => 'plain',
            'channel' => $s['channel'] ?? 'generic',
            'api_key' => $s['api_key'] ?? '',
        ] );

        $ok  = $r['ok'] && isset( $r['body']['message_id'] );
        $mid = $r['body']['message_id'] ?? null;
        return [
            'success'    => $ok,
            'message_id' => $mid,
            'cost'       => $r['body']['balance'] ?? null,
            'error'      => $ok ? null : ( $r['body']['message'] ?? $r['error'] ?? 'Error' ),
        ];
    }

    public function get_balance(): array {
        $s = $this->settings();
        $r = $this->http_get( 'https://v3.api.termii.com/api/get-balance', [ 'api_key' => $s['api_key'] ?? '' ] );
        return [ 'supported' => true, 'balance' => $r['body']['balance'] ?? null, 'currency' => $r['body']['currency'] ?? null ];
    }
}
