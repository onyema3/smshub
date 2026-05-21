<?php
namespace WPSMSHub\Tests\Unit;

use PHPUnit\Framework\TestCase;

class SMSManagerTest extends TestCase {

    public function test_normalize_phone_nigerian_local() {
        require_once WPSMSHUB_PLUGIN_DIR . 'includes/class-sms-manager.php';
        $result = \WPSMSHub\SMS_Manager::normalize_phone( '08012345678' );
        $this->assertEquals( '+2348012345678', $result );
    }

    public function test_normalize_phone_already_international() {
        require_once WPSMSHUB_PLUGIN_DIR . 'includes/class-sms-manager.php';
        $result = \WPSMSHub\SMS_Manager::normalize_phone( '+2348012345678' );
        $this->assertEquals( '+2348012345678', $result );
    }

    public function test_normalize_phone_strips_spaces() {
        require_once WPSMSHUB_PLUGIN_DIR . 'includes/class-sms-manager.php';
        $result = \WPSMSHub\SMS_Manager::normalize_phone( '080 1234 5678' );
        $this->assertEquals( '+2348012345678', $result );
    }

    public function test_normalize_non_nigerian() {
        require_once WPSMSHUB_PLUGIN_DIR . 'includes/class-sms-manager.php';
        $result = \WPSMSHub\SMS_Manager::normalize_phone( '+14155551234' );
        $this->assertEquals( '+14155551234', $result );
    }
}
