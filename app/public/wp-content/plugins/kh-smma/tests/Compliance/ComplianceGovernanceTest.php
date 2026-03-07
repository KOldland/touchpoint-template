<?php
namespace KH_SMMA\Tests\Compliance;

use KH_SMMA\Compliance\ComplianceRulesStore;
use KH_SMMA\Services\AuditLogger;
use KH_SMMA\Sponsor\ApprovalPermissionService;
use PHPUnit\Framework\TestCase;

class ComplianceGovernanceTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        $GLOBALS['kh_test_options'] = array();
        $GLOBALS['kh_test_user_caps'] = array();
        $GLOBALS['kh_test_user_meta'] = array();
    }

    public function test_role_restrictions_for_rule_and_claim_editing(): void {
        $permissions = new ApprovalPermissionService();

        $GLOBALS['kh_test_user_caps'][100]['manage_compliance_rules'] = true;
        $GLOBALS['kh_test_user_caps'][200]['manage_sponsor_claims'] = true;
        $GLOBALS['kh_test_user_meta'][200]['kh_smma_sponsor_ids'] = array( '22' );

        $this->assertTrue( $permissions->can_manage_banned_phrases( 100 ) );
        $this->assertFalse( $permissions->can_manage_banned_phrases( 200 ) );

        $this->assertTrue( $permissions->can_manage_sponsor_claims( 22, 200 ) );
        $this->assertFalse( $permissions->can_manage_sponsor_claims( 77, 200 ) );
    }

    public function test_rule_versioning_increments_for_phrase_and_claim_updates(): void {
        $store = new ComplianceRulesStore();

        $store->add_or_update_phrase( 'guaranteed leads', 'FAIL', 'marketing_claim', 1 );
        $meta1 = $store->increment_rules_version( 1 );

        $store->update_sponsor_claims( 22, array( 'eco friendly', 'ISO certified' ), 2 );
        $meta2 = $store->increment_rules_version( 2 );

        $this->assertSame( (int) $meta1['compliance_rules_version'] + 1, (int) $meta2['compliance_rules_version'] );
        $this->assertSame( (int) $meta2['compliance_rules_version'], (int) $meta2['corpus_version'] );
    }

    public function test_rule_change_audit_payload_shape(): void {
        $fixture = $this->load_fixture();
        $logger = new FixtureAuditLogger();

        foreach ( $fixture as $case ) {
            $payload = array(
                'trace_id' => 'trace-rule-' . md5( (string) $case['rule_id'] ),
                'user_id' => 5,
                'rule_id' => $case['rule_id'],
                'previous_value' => $case['previous_value'],
                'new_value' => $case['new_value'],
                'timestamp' => current_time( 'mysql' ),
            );

            $logger->record_event( (string) $case['event'], $payload );
        }

        $this->assertCount( 3, $logger->events );
        $first = $logger->events[0];
        $this->assertArrayHasKey( 'trace_id', $first['payload'] );
        $this->assertArrayHasKey( 'user_id', $first['payload'] );
        $this->assertArrayHasKey( 'rule_id', $first['payload'] );
        $this->assertArrayHasKey( 'previous_value', $first['payload'] );
        $this->assertArrayHasKey( 'new_value', $first['payload'] );
        $this->assertArrayHasKey( 'timestamp', $first['payload'] );
    }

    private function load_fixture(): array {
        $path = __DIR__ . '/../fixtures/compliance/rule_change_cases.json';
        $raw = file_get_contents( $path );
        $decoded = json_decode( (string) $raw, true );
        return is_array( $decoded ) ? array_values( $decoded ) : array();
    }
}

class FixtureAuditLogger extends AuditLogger {
    public array $events = array();

    public function __construct() {
        parent::__construct( new \wpdb() );
    }

    public function record_event( string $event_name, array $payload = array() ) {
        $this->events[] = array(
            'event' => $event_name,
            'payload' => $payload,
        );
    }
}
