<?php
namespace KH_SMMA\Tests;

use KH_SMMA\API\RestController;
use KH_SMMA\Compliance\SuggestedEditService;
use KH_SMMA\Services\AuditLogger;
use KH_SMMA\Services\FeatureFlags;
use KH_SMMA\Services\SmmaGenerator;
use PHPUnit\Framework\TestCase;

class SuggestedEditWorkflowTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        $GLOBALS['kh_test_post_meta'] = array();
        $GLOBALS['kh_test_options'] = array();
    }

    public function test_warn_or_fail_response_includes_suggested_edits(): void {
        $controller = $this->make_controller();
        $schedule_id = 501;
        $GLOBALS['kh_test_post_meta'][ $schedule_id ]['_kh_smma_payload'] = array(
            'text' => 'Guaranteed results for everyone.',
            'variant_id' => 'var-501',
            'channel' => 'linkedin',
            'phase_tag' => 'Attention',
        );

        $request = new \WP_REST_Request(
            array(
                'schedule_id' => $schedule_id,
                'updated_text' => 'Guaranteed results for everyone.',
            )
        );

        $result = $controller->handle_variant_edit( $request );

        $this->assertTrue( \is_wp_error( $result ) );
        $data = $result->get_error_data( 'kh_smma_compliance_failed' );
        $this->assertIsArray( $data );
        $this->assertNotEmpty( $data['compliance']['suggested_edits'] ?? array() );
    }

    public function test_apply_all_suggested_fixes_rechecks_compliance(): void {
        $controller = $this->make_controller();
        $schedule_id = 777;
        $GLOBALS['kh_test_post_meta'][ $schedule_id ]['_kh_smma_payload'] = array(
            'text' => 'Guaranteed results for everyone.',
            'variant_id' => 'var-777',
            'channel' => 'linkedin',
            'phase_tag' => 'Attention',
        );

        $request = new \WP_REST_Request(
            array(
                'schedule_id' => $schedule_id,
                'updated_text' => 'Guaranteed results for everyone.',
                'apply_all_suggested_fixes' => true,
                'source' => 'suggested_edit',
                'rule_ids' => array( 'banned_phrase_guarantee' ),
            )
        );

        $result = $controller->handle_variant_edit( $request );

        $this->assertIsArray( $result );
        $this->assertSame( 'updated', $result['status'] ?? '' );
        $this->assertTrue( (bool) ( $result['compliance']['passed'] ?? false ) );

        $saved = $GLOBALS['kh_test_post_meta'][ $schedule_id ]['_kh_smma_payload']['text'] ?? '';
        $this->assertStringNotContainsString( 'guaranteed results', strtolower( $saved ) );
    }

    private function make_controller(): RestController {
        $flags = new FeatureFlags();
        $generator = new SmmaGenerator();
        $logger = new AuditLogger( new \wpdb() );
        $suggested = new SuggestedEditService();

        return new RestController( $flags, $generator, $logger, null, null, $suggested );
    }
}
