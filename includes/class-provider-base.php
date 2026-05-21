<?php
namespace WPSMSHub\Providers;

if ( ! defined( 'ABSPATH' ) ) exit;

abstract class Provider_Base {
    /** Unique slug, e.g. 'kudisms' */
    abstract public function get_key(): string;

    /** Human-readable label */
    abstract public function get_label(): string;

    /** Fields to show in settings UI */
    abstract public function get_settings_fields(): array;

    /**
     * Send message to a single normalized recipient.
     * Must return: [ 'success' => bool, 'message_id' => string|null, 'cost' => float|null, 'error' => string|null ]
     */
    abstract public function send( string $to, string $message, array $args = [] ): array;

    /** Optional: check balance */
    public function get_balance(): array {
        return [ 'supported' => false ];
    }

    /** Get a saved provider setting */
    public function get_setting( string $key, $default = '' ) {
        $all = get_option( 'wpsmshub_provider_' . $this->get_key(), [] );
        return $all[ $key ] ?? $default;
    }

    /** Convenience: get all settings */
    protected function settings(): array {
        return get_option( 'wpsmshub_provider_' . $this->get_key(), [] );
    }

    /** Standard HTTP helper */
    protected function http_post( string $url, array $body, array $headers = [] ): array {
        $response = wp_remote_post( $url, [
            'timeout' => 20,
            'headers' => array_merge( [ 'Accept' => 'application/json' ], $headers ),
            'body'    => $body,
        ] );
        return $this->parse_response( $response );
    }

    protected function http_post_json( string $url, array $body, array $headers = [] ): array {
        $response = wp_remote_post( $url, [
            'timeout' => 20,
            'headers' => array_merge([
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ], $headers ),
            'body'    => wp_json_encode( $body ),
        ] );
        return $this->parse_response( $response );
    }

    protected function http_get( string $url, array $query = [], array $headers = [] ): array {
        if ( $query ) $url = add_query_arg( $query, $url );
        $response = wp_remote_get( $url, [
            'timeout' => 20,
            'headers' => array_merge( [ 'Accept' => 'application/json' ], $headers ),
        ] );
        return $this->parse_response( $response );
    }

    private function parse_response( $response ): array {
        if ( is_wp_error( $response ) ) {
            return [ 'ok' => false, 'error' => $response->get_error_message(), 'body' => null, 'code' => 0 ];
        }
        $code = wp_remote_retrieve_response_code( $response );
        $raw  = wp_remote_retrieve_body( $response );
        $body = json_decode( $raw, true );
        return [ 'ok' => ( $code >= 200 && $code < 300 ), 'code' => $code, 'body' => $body ?? $raw, 'raw' => $raw ];
    }
}
