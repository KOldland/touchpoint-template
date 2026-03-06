<?php
namespace KH_SMMA\Tests\Compliance;

use KH_SMMA\Admin\VariantGridComplianceRenderer;
use PHPUnit\Framework\TestCase;

class VariantComplianceUITest extends TestCase {
    public function test_badge_and_actions_for_warn_and_fail(): void {
        $renderer = new VariantGridComplianceRenderer();

        $warn = $renderer->build_payload( array(
            'compliance_status' => 'WARN',
            'rationale' => 'Absolute claim detected.',
            'rules_triggered' => array( 'banned_phrase_guarantee' ),
        ) );
        $fail = $renderer->build_payload( array(
            'compliance_status' => 'FAIL',
            'rationale' => 'Unverified performance claim.',
            'rules_triggered' => array( 'unverified_performance' ),
        ) );

        $this->assertSame( 'WARN', $warn['status'] );
        $this->assertTrue( $warn['actions']['can_request_approval'] );
        $this->assertTrue( $warn['actions']['can_edit_resubmit'] );

        $this->assertSame( 'FAIL', $fail['status'] );
        $this->assertFalse( $fail['actions']['can_schedule'] );
        $this->assertNotEmpty( $fail['actions']['blocked_reason'] );
    }
}
