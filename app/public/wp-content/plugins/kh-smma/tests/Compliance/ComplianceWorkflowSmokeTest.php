<?php
namespace KH_SMMA\Tests\Compliance;

use KH_SMMA\API\RestController;
use KH_SMMA\Services\AuditLogger;
use KH_SMMA\Services\FeatureFlags;
use KH_SMMA\Services\SmmaGenerator;
use PHPUnit\Framework\TestCase;

class ComplianceWorkflowSmokeTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        $GLOBALS['kh_test_options'] = array();
        $GLOBALS['kh_test_post_meta'] = array();
        $GLOBALS['kh_test_actions'] = array();
    }

    public function test_generate_edit_schedule_compliance_workflow(): void {
        $controller = new RestController(
            new FeatureFlags(),
            new WorkflowSmokeGenerator(),
            new AuditLogger( new \wpdb() )
        );

        $generated = $controller->handle_generate( new \WP_REST_Request( array(
            'post_id' => 100,
            'num_variants' => 1,
        ) ) );

        $this->assertIsArray( $generated );
        $variant = $generated['variants'][0] ?? array();
        $this->assertSame( 'WARN', $variant['compliance_status'] ?? '' );

        $approval = $controller->handle_variant_request_approval( new \WP_REST_Request( array(
            'variant_id' => (string) ( $variant['variant_id'] ?? 'var-smoke' ),
            'compliance_status' => 'WARN',
            'rationale' => 'Absolute claim detected',
        ) ) );
        $this->assertSame( 'pending_approval', $approval['status'] ?? '' );

        $blocked_warn = $controller->handle_schedule( new \WP_REST_Request( array(
            'post_id' => 100,
            'schedule' => array(
                array(
                    'variant_id' => (string) ( $variant['variant_id'] ?? 'var-smoke' ),
                    'scheduled_at' => time() + 1800,
                    'text' => (string) ( $variant['text'] ?? '' ),
                    'compliance_status' => 'WARN',
                ),
            ),
        ) ) );
        $this->assertTrue( \is_wp_error( $blocked_warn ) );

        $blocked_fail = $controller->handle_schedule( new \WP_REST_Request( array(
            'post_id' => 100,
            'schedule' => array(
                array(
                    'variant_id' => 'var-fail-smoke',
                    'scheduled_at' => time() + 2400,
                    'text' => 'guaranteed results',
                    'compliance_status' => 'FAIL',
                ),
            ),
        ) ) );
        $this->assertTrue( \is_wp_error( $blocked_fail ) );

        $edited = $controller->handle_variant_edit( new \WP_REST_Request( array(
            'variant_id' => (string) ( $variant['variant_id'] ?? 'var-smoke' ),
            'updated_text' => 'Designed to help teams improve outcomes over time',
            'current_text' => (string) ( $variant['text'] ?? '' ),
        ) ) );

        $this->assertIsArray( $edited );
        $this->assertSame( 'OK', $edited['compliance']['status'] ?? '' );

        $scheduled_ok = $controller->handle_schedule( new \WP_REST_Request( array(
            'post_id' => 100,
            'schedule' => array(
                array(
                    'variant_id' => (string) ( $variant['variant_id'] ?? 'var-smoke' ),
                    'scheduled_at' => time() + 3600,
                    'text' => (string) ( $edited['updated_text'] ?? 'safe text' ),
                    'compliance_status' => 'OK',
                ),
            ),
        ) ) );

        $this->assertIsArray( $scheduled_ok );
        $this->assertNotEmpty( $scheduled_ok['created'] ?? array() );
    }
}

class WorkflowSmokeGenerator extends SmmaGenerator {
    public function generate( array $input ) {
        $variants = array(
            array(
                'variant_id' => 'var-smoke-001',
                'text' => 'This is one of the best options for teams.',
                'phase_tag' => 'Attention',
                'compliance_notes' => 'WARN: absolute claim detected',
            ),
        );

        return array(
            'variants' => $variants,
            'linkedin_variants' => $variants,
            'google_ad_draft' => array(),
            'model' => 'smoke-fixture',
        );
    }
}
