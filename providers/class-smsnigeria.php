<?php
namespace WPSMSHub\Providers;

if ( ! defined( 'ABSPATH' ) ) exit;

class SMSNigeria extends Provider_Base {
    public function get_key(): string   { return 'smsnigeria'; }
    public function get_label(): string { return 'NigeriaSMS'; }

    public function get_settings_fields(): array {
        return [
            [ 'key' => 'api_token', 'label' => 'API Token', 'type' => 'password', 'required' => true ],
            [ 'key' => 'sender_id', 'label' => 'Sender ID', 'type' => 'text',     'required' => false, 'placeholder' => 'e.g. MyBrand' ],
        ];
    }

    public function send( string $to, string $message, array $args = [] ): array {
        $s = $this->settings();

        // Try the current NigeriaSMS.net API (v2, Bearer auth)
        $r = $this->http_post_json( 'https://nigeriasms.net/api/v2/sms/send', [
            'to'   => $to,
            'from' => $args['sender_id'] ?? $s['sender_id'] ?? 'NigeriaSMS',
            'body' => $message,
        ], [
            'Authorization' => 'Bearer ' . ( $s['api_token'] ?? '' ),
        ] );

        // Fallback to legacy endpoint if the new domain returns 404 or connection error
        if ( ! $r['ok'] && ( ( $r['code'] ?? 0 ) === 404 || ( $r['code'] ?? 0 ) === 0 ) ) {
            $r = $this->http_post_json( 'https://www.smsnigeria.net/api/v1/sms/send', [
                'api_token' => $s['api_token'] ?? '',
                'from'      => $args['sender_id'] ?? $s['sender_id'] ?? 'SMSNigeria',
                'to'        => $to,
                'body'      => $message,
            ] );
        }

        $ok  = $r['ok'] && (
            ( isset( $r['body']['status'] ) && $r['body']['status'] === 'success' ) ||
            ( isset( $r['body']['code'] ) && $r['body']['code'] === '0k' ) ||
            ! empty( $r['body']['data']['message_id'] )
        );
        $mid = $r['body']['data']['message_id'] ?? $r['body']['uid'] ?? null;
        return [
            'success'    => $ok,
            'message_id' => $mid,
            'cost'       => $r['body']['data']['cost'] ?? null,
            'error'      => $ok ? null : ( $r['body']['message'] ?? $r['body']['error'] ?? $r['error'] ?? 'Error' ),
        ];
    }
}
