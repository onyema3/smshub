<?php
namespace WPSMSHub\Providers;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WhatsApp Business API provider.
 * Supports sending via Termii WhatsApp channel, Twilio WhatsApp, or Meta Cloud API.
 */
class WhatsApp extends Provider_Base {
    public function get_key(): string   { return 'whatsapp'; }
    public function get_label(): string { return 'WhatsApp Business'; }

    public function get_settings_fields(): array {
        return [
            [ 'key' => 'gateway', 'label' => 'Gateway', 'type' => 'select', 'required' => true,
              'options' => [ 'meta' => 'Meta Cloud API', 'twilio' => 'Twilio WhatsApp', 'termii' => 'Termii WhatsApp' ] ],
            [ 'key' => 'api_token',      'label' => 'API Token / Access Token', 'type' => 'password', 'required' => true ],
            [ 'key' => 'phone_number_id','label' => 'Phone Number ID (Meta only)', 'type' => 'text', 'required' => false, 'placeholder' => 'e.g. 1234567890' ],
            [ 'key' => 'from_number',    'label' => 'From Number (Twilio)', 'type' => 'text', 'required' => false, 'placeholder' => 'whatsapp:+14155238886' ],
            [ 'key' => 'template_name',  'label' => 'Template Name (Meta)', 'type' => 'text', 'required' => false, 'placeholder' => 'e.g. hello_world' ],
            [ 'key' => 'language_code',  'label' => 'Language Code', 'type' => 'text', 'required' => false, 'placeholder' => 'en_US' ],
        ];
    }

    public function send( string $to, string $message, array $args = [] ): array {
        $s = $this->settings();
        $gateway = $s['gateway'] ?? 'meta';

        switch ( $gateway ) {
            case 'meta':
                return $this->send_via_meta( $to, $message, $s );
            case 'twilio':
                return $this->send_via_twilio( $to, $message, $s );
            case 'termii':
                return $this->send_via_termii( $to, $message, $s );
            default:
                return [ 'success' => false, 'message_id' => null, 'cost' => null, 'error' => 'Invalid gateway' ];
        }
    }

    private function send_via_meta( string $to, string $message, array $s ): array {
        $phone_id = $s['phone_number_id'] ?? '';
        $token    = $s['api_token'] ?? '';
        $template = $s['template_name'] ?? '';

        $body = [];
        if ( $template ) {
            // Template message
            $body = [
                'messaging_product' => 'whatsapp',
                'to'                => preg_replace( '/[^\d]/', '', $to ),
                'type'              => 'template',
                'template'          => [
                    'name'     => $template,
                    'language' => [ 'code' => $s['language_code'] ?? 'en_US' ],
                ],
            ];
        } else {
            // Text message
            $body = [
                'messaging_product' => 'whatsapp',
                'to'                => preg_replace( '/[^\d]/', '', $to ),
                'type'              => 'text',
                'text'              => [ 'body' => $message ],
            ];
        }

        $r = $this->http_post_json(
            "https://graph.facebook.com/v18.0/{$phone_id}/messages",
            $body,
            [ 'Authorization' => 'Bearer ' . $token ]
        );

        $ok  = $r['ok'] && ! empty( $r['body']['messages'][0]['id'] );
        $mid = $r['body']['messages'][0]['id'] ?? null;
        return [
            'success'    => $ok,
            'message_id' => $mid,
            'cost'       => null,
            'error'      => $ok ? null : ( $r['body']['error']['message'] ?? $r['error'] ?? 'Error' ),
        ];
    }

    private function send_via_twilio( string $to, string $message, array $s ): array {
        $token = $s['api_token'] ?? '';
        $from  = $s['from_number'] ?? '';
        // Parse account SID from token format or use a separate field
        $parts = explode( ':', $token );
        $sid   = $parts[0] ?? '';
        $auth  = $parts[1] ?? $token;

        $url = "https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json";
        $r = $this->http_post( $url, [
            'To'   => 'whatsapp:' . preg_replace( '/[^\d+]/', '', $to ),
            'From' => $from,
            'Body' => $message,
        ], [
            'Authorization' => 'Basic ' . base64_encode( $sid . ':' . $auth ),
        ] );

        $ok  = $r['ok'] && isset( $r['body']['sid'] );
        return [
            'success'    => $ok,
            'message_id' => $r['body']['sid'] ?? null,
            'cost'       => null,
            'error'      => $ok ? null : ( $r['body']['message'] ?? $r['error'] ?? 'Error' ),
        ];
    }

    private function send_via_termii( string $to, string $message, array $s ): array {
        $r = $this->http_post_json( 'https://v3.api.termii.com/api/sms/send', [
            'to'      => $to,
            'from'    => 'WhatsApp',
            'sms'     => $message,
            'type'    => 'plain',
            'channel' => 'WhatsApp',
            'api_key' => $s['api_token'] ?? '',
        ] );

        $ok  = $r['ok'] && isset( $r['body']['message_id'] );
        return [
            'success'    => $ok,
            'message_id' => $r['body']['message_id'] ?? null,
            'cost'       => $r['body']['balance'] ?? null,
            'error'      => $ok ? null : ( $r['body']['message'] ?? $r['error'] ?? 'Error' ),
        ];
    }
}
