<?php
namespace WPSMSHub\Providers;

if ( ! defined( 'ABSPATH' ) ) exit;

// ─── InfoBip ─────────────────────────────────────────────────────────────────
class InfoBip extends Provider_Base {
    public function get_key(): string   { return 'infobip'; }
    public function get_label(): string { return 'InfoBip'; }

    public function get_settings_fields(): array {
        return [
            [ 'key' => 'api_key',   'label' => 'API Key',      'type' => 'password', 'required' => true ],
            [ 'key' => 'base_url',  'label' => 'Base URL',     'type' => 'text',     'required' => true, 'placeholder' => 'xxxxx.api.infobip.com' ],
            [ 'key' => 'sender_id', 'label' => 'Sender Name',  'type' => 'text',     'required' => false ],
        ];
    }

    public function send( string $to, string $message, array $args = [] ): array {
        $s    = $this->settings();
        $host = rtrim( $s['base_url'] ?? '', '/' );
        $r    = $this->http_post_json( "https://{$host}/sms/2/text/advanced", [
            'messages' => [[
                'destinations' => [[ 'to' => $to ]],
                'from'         => $args['sender_id'] ?? $s['sender_id'] ?? 'InfoSMS',
                'text'         => $message,
            ]],
        ], [ 'Authorization' => 'App ' . ( $s['api_key'] ?? '' ) ] );

        $ok  = $r['ok'] && ! empty( $r['body']['messages'] );
        $mid = $r['body']['messages'][0]['messageId'] ?? null;
        return [ 'success' => $ok, 'message_id' => $mid, 'cost' => null, 'error' => $ok ? null : ( $r['error'] ?? 'Error' ) ];
    }
}

// ─── Vonage (Nexmo) ───────────────────────────────────────────────────────────
class Vonage extends Provider_Base {
    public function get_key(): string   { return 'vonage'; }
    public function get_label(): string { return 'Vonage (Nexmo)'; }

    public function get_settings_fields(): array {
        return [
            [ 'key' => 'api_key',    'label' => 'API Key',    'type' => 'text',     'required' => true ],
            [ 'key' => 'api_secret', 'label' => 'API Secret', 'type' => 'password', 'required' => true ],
            [ 'key' => 'sender_id',  'label' => 'Sender ID',  'type' => 'text',     'required' => false ],
        ];
    }

    public function send( string $to, string $message, array $args = [] ): array {
        $s = $this->settings();
        $r = $this->http_post_json( 'https://rest.nexmo.com/sms/json', [
            'api_key'    => $s['api_key']    ?? '',
            'api_secret' => $s['api_secret'] ?? '',
            'to'         => $to,
            'from'       => $args['sender_id'] ?? $s['sender_id'] ?? 'Vonage',
            'text'       => $message,
        ] );

        $ok  = $r['ok'] && ( $r['body']['messages'][0]['status'] ?? '1' ) === '0';
        $mid = $r['body']['messages'][0]['message-id'] ?? null;
        return [ 'success' => $ok, 'message_id' => $mid, 'cost' => $r['body']['messages'][0]['message-price'] ?? null, 'error' => $ok ? null : ( $r['body']['messages'][0]['error-text'] ?? 'Error' ) ];
    }
}

// ─── Africa's Talking ─────────────────────────────────────────────────────────
class AfricasTalking extends Provider_Base {
    public function get_key(): string   { return 'africastalking'; }
    public function get_label(): string { return "Africa's Talking"; }

    public function get_settings_fields(): array {
        return [
            [ 'key' => 'username',  'label' => 'Username',   'type' => 'text',     'required' => true ],
            [ 'key' => 'api_key',   'label' => 'API Key',    'type' => 'password', 'required' => true ],
            [ 'key' => 'sender_id', 'label' => 'Sender ID',  'type' => 'text',     'required' => false ],
            [ 'key' => 'sandbox',   'label' => 'Sandbox Mode','type' => 'checkbox', 'required' => false ],
        ];
    }

    public function send( string $to, string $message, array $args = [] ): array {
        $s      = $this->settings();
        $sandbox = ! empty( $s['sandbox'] );
        $url     = $sandbox ? 'https://api.sandbox.africastalking.com/version1/messaging' : 'https://api.africastalking.com/version1/messaging';

        $r = $this->http_post( $url, [
            'username' => $s['username'] ?? '',
            'to'       => $to,
            'message'  => $message,
            'from'     => $args['sender_id'] ?? $s['sender_id'] ?? null,
        ], [
            'apiKey' => $s['api_key'] ?? '',
            'Accept' => 'application/json',
        ] );

        $ok  = $r['ok'] && isset( $r['body']['SMSMessageData']['Recipients'][0] );
        $mid = $r['body']['SMSMessageData']['Recipients'][0]['messageId'] ?? null;
        return [ 'success' => $ok, 'message_id' => $mid, 'cost' => $r['body']['SMSMessageData']['Recipients'][0]['cost'] ?? null, 'error' => $ok ? null : ( $r['error'] ?? 'Error' ) ];
    }
}

// ─── BulkSMS ──────────────────────────────────────────────────────────────────
class BulkSMS extends Provider_Base {
    public function get_key(): string   { return 'bulksms'; }
    public function get_label(): string { return 'BulkSMS'; }

    public function get_settings_fields(): array {
        return [
            [ 'key' => 'token_id',     'label' => 'Token ID',     'type' => 'text',     'required' => true ],
            [ 'key' => 'token_secret', 'label' => 'Token Secret', 'type' => 'password', 'required' => true ],
            [ 'key' => 'sender_id',    'label' => 'Sender ID',    'type' => 'text',     'required' => false ],
        ];
    }

    public function send( string $to, string $message, array $args = [] ): array {
        $s = $this->settings();
        $r = $this->http_post_json( 'https://api.bulksms.com/v1/messages', [
            'to'   => $to,
            'body' => $message,
            'from' => $args['sender_id'] ?? $s['sender_id'] ?? null,
        ], [
            'Authorization' => 'Basic ' . base64_encode( ( $s['token_id'] ?? '' ) . ':' . ( $s['token_secret'] ?? '' ) ),
        ] );

        $ok  = $r['ok'] && ! empty( $r['body'] ) && isset( $r['body'][0]['id'] );
        $mid = $r['body'][0]['id'] ?? null;
        return [ 'success' => $ok, 'message_id' => $mid, 'cost' => $r['body'][0]['creditCost'] ?? null, 'error' => $ok ? null : ( $r['error'] ?? 'Error' ) ];
    }
}

// ─── Multitexter ─────────────────────────────────────────────────────────────
class Multitexter extends Provider_Base {
    public function get_key(): string   { return 'multitexter'; }
    public function get_label(): string { return 'Multitexter'; }

    public function get_settings_fields(): array {
        return [
            [ 'key' => 'api_key',   'label' => 'API Key',   'type' => 'password', 'required' => true ],
            [ 'key' => 'email',     'label' => 'Email',     'type' => 'email',    'required' => true ],
            [ 'key' => 'password',  'label' => 'Password',  'type' => 'password', 'required' => true ],
            [ 'key' => 'sender_id', 'label' => 'Sender ID', 'type' => 'text',     'required' => false ],
        ];
    }

    public function send( string $to, string $message, array $args = [] ): array {
        $s = $this->settings();
        $r = $this->http_post_json( 'https://app.multitexter.com/v2/app/sms', [
            'email'     => $s['email']    ?? '',
            'password'  => $s['password'] ?? '',
            'sender_name' => $args['sender_id'] ?? $s['sender_id'] ?? 'MultiSMS',
            'message'   => $message,
            'recipients' => $to,
        ] );

        $ok  = $r['ok'] && ( $r['body']['status'] ?? 0 ) === 1;
        return [ 'success' => $ok, 'message_id' => null, 'cost' => null, 'error' => $ok ? null : ( $r['body']['message'] ?? $r['error'] ?? 'Error' ) ];
    }
}

// ─── Smart SMS Solutions ──────────────────────────────────────────────────────
class SmartSMSSolutions extends Provider_Base {
    public function get_key(): string   { return 'smartsms'; }
    public function get_label(): string { return 'Smart SMS Solutions'; }

    public function get_settings_fields(): array {
        return [
            [ 'key' => 'token',     'label' => 'API Token',  'type' => 'password', 'required' => true ],
            [ 'key' => 'sender_id', 'label' => 'Sender ID',  'type' => 'text',     'required' => false ],
        ];
    }

    public function send( string $to, string $message, array $args = [] ): array {
        $s = $this->settings();
        $r = $this->http_post_json( 'https://smartsmssolutions.com/api/json.php', [
            'token'   => $s['token']    ?? '',
            'sender'  => $args['sender_id'] ?? $s['sender_id'] ?? 'SmartSMS',
            'to'      => $to,
            'message' => $message,
            'type'    => 0,
            'routing' => 3,
        ] );

        $ok  = $r['ok'] && ( $r['body']['code'] ?? '' ) === '1000';
        $mid = $r['body']['messageid'] ?? null;
        return [ 'success' => $ok, 'message_id' => $mid, 'cost' => null, 'error' => $ok ? null : ( $r['body']['message'] ?? $r['error'] ?? 'Error' ) ];
    }
}
