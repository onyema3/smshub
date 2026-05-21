<?php
namespace WPSMSHub;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AI Message Generation - Template suggestions, tone adjustment, personalization.
 */
class AI_Messages {

    /**
     * Generate message suggestions based on context.
     * Uses simple rule-based generation (no external API required).
     */
    public static function suggest( string $context, string $tone = 'professional' ): array {
        $templates = self::get_base_templates( $context );
        return array_map( function( $tpl ) use ( $tone ) {
            return self::apply_tone( $tpl, $tone );
        }, $templates );
    }

    /**
     * Get base templates by context/category.
     */
    private static function get_base_templates( string $context ): array {
        $library = [
            'order_confirmation' => [
                'Hi {customer_name}, your order #{order_id} has been confirmed. Total: {order_total}. Thank you for shopping with {site_name}!',
                'Order #{order_id} confirmed! We are preparing your items. Expected delivery: 2-3 business days. - {site_name}',
                '{customer_name}, thank you! Order #{order_id} ({order_total}) is being processed. We will notify you when it ships.',
            ],
            'order_shipped' => [
                'Great news {customer_name}! Order #{order_id} has been shipped. Track: {tracking_number}. - {site_name}',
                'Your order #{order_id} is on its way! Tracking: {tracking_number}. Estimated arrival: 1-2 days.',
                '{customer_name}, we shipped your order! Track at {tracking_number}. Contact us if you need help.',
            ],
            'payment_reminder' => [
                'Hi {customer_name}, this is a friendly reminder that payment of {order_total} for order #{order_id} is pending. Please complete payment to avoid cancellation.',
                'Payment reminder: Order #{order_id} ({order_total}) awaits payment. Complete it at {site_url} to secure your items.',
                '{customer_name}, your order #{order_id} needs payment. Please pay {order_total} within 24hrs to avoid cancellation.',
            ],
            'welcome' => [
                'Welcome to {site_name}, {customer_name}! We are glad to have you. Explore our latest offers at {site_url}.',
                'Hi {customer_name}! Thanks for joining {site_name}. Use code WELCOME10 for 10% off your first order!',
                '{customer_name}, welcome aboard! You are now part of the {site_name} family. Happy shopping!',
            ],
            'otp' => [
                'Your verification code is {otp_code}. Valid for 10 minutes. Do not share this code.',
                '{otp_code} is your {site_name} verification code. Expires in 5 minutes.',
                'OTP: {otp_code} - Use this to verify your {site_name} account. Valid 10 min.',
            ],
            'promotion' => [
                'Flash Sale! Get up to 30% off everything at {site_name}. Shop now: {site_url}. Offer ends midnight!',
                '{customer_name}, exclusive deal just for you! 20% off with code SAVE20 at {site_name}. Limited time.',
                'Big savings await! Visit {site_url} for our biggest sale of the season. Up to 50% off selected items.',
            ],
            'appointment' => [
                'Reminder: You have an appointment on {date} at {time}. Reply CONFIRM to confirm or CANCEL to reschedule.',
                'Hi {customer_name}, just a reminder about your upcoming appointment on {date}. See you then! - {site_name}',
                'Your appointment is tomorrow at {time}. Please arrive 10 minutes early. - {site_name}',
            ],
            'delivery' => [
                '{customer_name}, your order #{order_id} has been delivered! We hope you love it. Rate your experience at {site_url}.',
                'Delivered! Order #{order_id} arrived at your address. Any issues? Contact us within 48hrs.',
                'Your package is here! Order #{order_id} delivered successfully. Thank you for choosing {site_name}.',
            ],
        ];

        $context = strtolower( trim( $context ) );
        if ( isset( $library[ $context ] ) ) {
            return $library[ $context ];
        }

        // Fuzzy match
        foreach ( $library as $key => $templates ) {
            if ( str_contains( $context, $key ) || str_contains( $key, $context ) ) {
                return $templates;
            }
        }

        // Generic fallback
        return [
            'Hi {customer_name}, {site_name} here. We have an update for you. Visit {site_url} for details.',
            '{customer_name}, thank you for being a valued customer of {site_name}. Check out what is new!',
            'Hello from {site_name}! We wanted to reach out with important information. Visit {site_url}.',
        ];
    }

    /**
     * Apply tone adjustment to a message.
     */
    private static function apply_tone( string $message, string $tone ): string {
        switch ( $tone ) {
            case 'casual':
                $message = str_replace( 'Hi ', 'Hey ', $message );
                $message = str_replace( 'Hello', 'Hey there', $message );
                $message = str_replace( 'We are glad', 'Super happy', $message );
                $message = str_replace( 'Thank you', 'Thanks', $message );
                $message = str_replace( 'Please', 'Pls', $message );
                break;
            case 'urgent':
                $message = strtoupper( substr( $message, 0, 1 ) ) . substr( $message, 1 );
                if ( ! str_contains( $message, '!' ) ) $message = rtrim( $message, '.' ) . '!';
                $message = str_replace( 'friendly reminder', 'URGENT reminder', $message );
                $message = str_replace( 'Reminder:', 'URGENT:', $message );
                break;
            case 'formal':
                $message = str_replace( 'Hi ', 'Dear ', $message );
                $message = str_replace( 'Hey ', 'Dear ', $message );
                $message = str_replace( 'Thanks', 'Thank you', $message );
                $message = str_replace( 'we shipped', 'your order has been dispatched', $message );
                break;
            // 'professional' is the default - no changes
        }
        return $message;
    }

    /**
     * Suggest merge tags based on context.
     */
    public static function suggest_tags( string $context ): array {
        $common = [ '{site_name}', '{site_url}', '{date}', '{time}' ];

        $contextual = [
            'order'    => [ '{order_id}', '{order_total}', '{order_status}', '{customer_name}', '{customer_phone}', '{tracking_number}', '{payment_method}', '{order_items}' ],
            'user'     => [ '{user_name}', '{user_email}', '{user_phone}' ],
            'customer' => [ '{customer_name}', '{customer_phone}', '{customer_email}' ],
            'otp'      => [ '{otp_code}' ],
        ];

        $tags = $common;
        foreach ( $contextual as $key => $extra_tags ) {
            if ( str_contains( strtolower( $context ), $key ) ) {
                $tags = array_merge( $tags, $extra_tags );
            }
        }
        return array_unique( $tags );
    }

    /**
     * Get all available template categories.
     */
    public static function get_categories(): array {
        return [
            'order_confirmation' => 'Order Confirmation',
            'order_shipped'      => 'Order Shipped',
            'payment_reminder'   => 'Payment Reminder',
            'welcome'            => 'Welcome Message',
            'otp'                => 'OTP / Verification',
            'promotion'          => 'Promotion / Sale',
            'appointment'        => 'Appointment Reminder',
            'delivery'           => 'Delivery Notification',
        ];
    }
}
