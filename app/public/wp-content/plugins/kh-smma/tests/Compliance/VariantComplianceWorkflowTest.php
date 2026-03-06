<?php
namespace KH_SMMA\Tests\Compliance;

use KH_SMMA\API\RestController;
use KH_SMMA\Services\AuditLogger;
use KH_SMMA\Services\FeatureFlags;
use KH_SMMA\Services\SmmaGenerator;
use PHPUnit\Framework\TestCase;

class VariantComplianceWorkflowTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        $GLOBALS['kh_test_post_meta'] = array();
        $GLOBALS['kh_test_options'] = array();
    }

    public function test_warn_variant_triggers_approval_flow(): void {
        $controller = $this->make_controller();
        $request = new \WP_REST_Request( array(
            'variant_id' => 'var_warn_01',
            'compliance_status' => 'WARN',
            'rationale' => 'Absolute claim detected',
        ) );

        $response = $controller->handle_variant_request_approval( $request );
        $this->assertIsArray( $response );
        $this->assertSame( 'pending_approval', $response['status'] );

        $store = get_option( 'kh_smma_variant_approval_requests', array() );
        $this->assertSame( 'pending', $store['var_warn_01']['approval_status'] ?? '' );
    }

    public function test_fail_variant_blocks_scheduling(): void {
        $controller = $this->make_controller();
        $request = new \WP_REST_Request( array(
            'post_id' => 55,
            'schedule' => array(
                array(
                    'variant_id' => 'var_fail_01',
                    'scheduled_at' => time() + 3600,
                    'text' => 'guaranteed results for everyone',
                    'compliance_status' => 'FAIL',
                ),
            ),
        ) );

        $result = $controller->handle_schedule( $request );
        $this->assertTrue( \is_wp_error( $result ) );
        $this->assertSame( 'Scheduling blocked due to compliance violation.', $result->get_error_message( 'kh_smma_compliance_fail' ) );
    }

    public function test_edit_and_resubmit_rechecks_compliance(): void {
        $controller = $this->make_controller();

        $bad = $controller->handle_variant_edit( new \WP_REST_Request( array(
            'variant_id' => 'var_edit_01',
            'updated_text' => 'Guaranteed results with no risk',
        ) ) );
        $this->assertTrue( \is_wp_error( $bad ) );

        $good = $controller->handle_variant_edit( new \WP_REST_Request( array(
            'variant_id' => 'var_edit_01',
            'updated_text' => 'Designed to help teams improve outcomes over time',
        ) ) );
        $this->assertIsArray( $good );
        $this->assertSame( 'OK', $good['compliance']['status'] ?? '' );
    }

    private function make_controller(): RestController {
        return new RestController( new FeatureFlags(), new SmmaGenerator(), new AuditLogger( new \wpdb() ) );
    }
}
