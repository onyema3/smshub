<?php
namespace WPSMSHub\Providers;

if ( ! defined( 'ABSPATH' ) ) exit;

class SMSNigeria extends Provider_Base {
    public function get_key(): string   { return 'smsnigeria'; }
    public function get_label(): string { return 'SMS Nigeria'; }

    public function get_settings_fields(): array {
        return [
            [ 'key' => 'api_token', 'label' => 'API Token', 'type' => 'password', 'required' => true ],
            [ 'key' => 'sender_id', 'label' => 'Sender ID', 'type' => 'text',     'required' => false ],
        ];
    }

    public function send( string $to, string $message, array $args = [] ): array {
        $s = $this->settings();
        $r = $this->http_post_json( 'https://www.smsnigeria.net/api/v1/sms/send', [
            'api_token' => $s['api_token'] ?? '',
            'from'      => $args['sender_id'] ?? $s['sender_id'] ?? 'SMSNigeria',
            'to'        => $to,
            'body'      => $message,
        ] );

        $ok  = $r['ok'] && isset( $r['body']['code'] ) && $r['body']['code'] === '0k';
        $mid = $r['body']['uid'] ?? null;
        return [
            'success'    => $ok,
            'message_id' => $mid,
            'cost'       => null,
            'error'      => $ok ? null : ( $r['body']['message'] ?? $r['error'] ?? 'Error' ),
        ];
    }
}
