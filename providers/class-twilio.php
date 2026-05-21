<?php
namespace WPSMSHub\Providers;

if ( ! defined( 'ABSPATH' ) ) exit;

class Twilio extends Provider_Base {
    public function get_key(): string   { return 'twilio'; }
    public function get_label(): string { return 'Twilio'; }

    public function get_settings_fields(): array {
        return [
            [ 'key' => 'account_sid',  'label' => 'Account SID',   'type' => 'text',     'required' => true ],
            [ 'key' => 'auth_token',   'label' => 'Auth Token',     'type' => 'password', 'required' => true ],
            [ 'key' => 'from_number',  'label' => 'From Number',    'type' => 'text',     'required' => true, 'placeholder' => '+1234567890' ],
            [ 'key' => 'sender_id',    'label' => 'Messaging Service SID (optional)', 'type' => 'text', 'required' => false ],
        ];
    }

    public function send( string $to, string $message, array $args = [] ): array {
        $s   = $this->settings();
        $sid = $s['account_sid'] ?? '';
        $url = "https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json";

        $body = [
            'To'   => $to,
            'Body' => $message,
        ];
        if ( ! empty( $s['sender_id'] ) ) {
            $body['MessagingServiceSid'] = $s['sender_id'];
        } else {
            $body['From'] = $s['from_number'] ?? '';
        }

        $r = $this->http_post( $url, $body, [
            'Authorization' => 'Basic ' . base64_encode( $sid . ':' . ( $s['auth_token'] ?? '' ) ),
        ] );

        $ok  = $r['ok'] && isset( $r['body']['sid'] );
        $mid = $r['body']['sid'] ?? null;
        return [
            'success'    => $ok,
            'message_id' => $mid,
            'cost'       => null,
            'error'      => $ok ? null : ( $r['body']['message'] ?? $r['error'] ?? 'Error' ),
        ];
    }
}
