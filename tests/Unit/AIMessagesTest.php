<?php
namespace WPSMSHub\Tests\Unit;

use PHPUnit\Framework\TestCase;

class AIMessagesTest extends TestCase {

    public function setUp(): void {
        require_once WPSMSHUB_PLUGIN_DIR . 'includes/class-ai-messages.php';
    }

    public function test_suggest_returns_array() {
        $result = \WPSMSHub\AI_Messages::suggest( 'order_confirmation' );
        $this->assertIsArray( $result );
        $this->assertNotEmpty( $result );
    }

    public function test_suggest_returns_3_templates() {
        $result = \WPSMSHub\AI_Messages::suggest( 'welcome' );
        $this->assertCount( 3, $result );
    }

    public function test_tone_casual_transforms() {
        $result = \WPSMSHub\AI_Messages::suggest( 'welcome', 'casual' );
        $has_hey = false;
        foreach ( $result as $msg ) {
            if ( str_contains( $msg, 'Hey' ) ) $has_hey = true;
        }
        $this->assertTrue( $has_hey );
    }

    public function test_suggest_tags_for_order_context() {
        $tags = \WPSMSHub\AI_Messages::suggest_tags( 'order_confirmation' );
        $this->assertContains( '{order_id}', $tags );
        $this->assertContains( '{order_total}', $tags );
        $this->assertContains( '{site_name}', $tags );
    }

    public function test_categories_returns_expected() {
        $cats = \WPSMSHub\AI_Messages::get_categories();
        $this->assertArrayHasKey( 'order_confirmation', $cats );
        $this->assertArrayHasKey( 'otp', $cats );
        $this->assertArrayHasKey( 'promotion', $cats );
    }

    public function test_unknown_context_returns_generic() {
        $result = \WPSMSHub\AI_Messages::suggest( 'totally_random_unknown_thing' );
        $this->assertCount( 3, $result );
    }
}
