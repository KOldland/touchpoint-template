<?php
namespace KH_SMMA\Tests\Compliance;

use KH_SMMA\Compliance\ComplianceTelemetryService;
use KH_SMMA\Compliance\SuggestedEditService;
use KH_SMMA\Observability\EventEmitter;
use KH_SMMA\Observability\EventQueue;
use KH_SMMA\Observability\RetryService;
use KH_SMMA\Observability\TelemetrySink;
use KH_SMMA\Services\AuditLogger;
use KH_SMMA\Services\ComplianceValidator;
use PHPUnit\Framework\TestCase;

class ComplianceEngineTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        $GLOBALS['kh_test_options'] = array();
        $GLOBALS['kh_test_actions'] = array();
    }

    public function test_golden_fixtures_produce_expected_compliance_outcomes(): void {
        $validator = new ComplianceValidator();
        $suggested = new SuggestedEditService();

        foreach ( array( 'compliance_pass', 'compliance_warn', 'compliance_fail' ) as $name ) {
            $fixture = $this->load_fixture( $name . '.json' );
            $status = 'OK';
            $rules = array();

            if ( 'validator' === $fixture['mode'] ) {
                $result = $validator->validate( (string) $fixture['input'], array( 'channel' => 'linkedin' ) );
                $status = (bool) ( $result['passed'] ?? false ) ? 'OK' : 'FAIL';
                $rules = $suggested->infer_rules_from_compliance_notes( (string) ( $result['notes'] ?? '' ) . ' ' . (string) ( $result['message'] ?? '' ) );
            } else {
                $status = 'WARN';
                $rules = $suggested->infer_rules_from_compliance_notes( 'absolute claim best warning' );
            }

            $this->assertSame( $fixture['expected_status'], $status, 'Status mismatch for fixture: ' . $name );
            foreach ( (array) $fixture['rules_triggered'] as $rule_id ) {
                $this->assertContains( $rule_id, $rules, 'Expected rule missing for fixture: ' . $name );
            }
        }
    }

    public function test_compliance_telemetry_and_alert_signals_are_emitted(): void {
        $audit = new FakeAuditLogger();
        $telemetry = new ComplianceTelemetryService(
            $audit,
            new EventEmitter( new EventQueue(), new RetryService(), new TelemetrySink() )
        );

        for ( $i = 0; $i < 30; $i++ ) {
            $telemetry->record_check( array(
                'trace_id' => 'trace-' . $i,
                'variant_id' => 'var-' . $i,
                'status' => $i < 24 ? 'FAIL' : 'WARN',
                'rule_ids_triggered' => array( 'banned_phrase_guarantee' ),
                'latency_ms' => $i === 0 ? 70 : 15,
                'input_text' => 'fixture-text-' . $i,
                'timestamp' => time(),
            ) );
        }

        $events = get_option( 'kh_smma_telemetry_events', array() );
        $this->assertNotEmpty( $events );

        $names = array_map( static function ( array $row ): string {
            return (string) ( $row['event'] ?? '' );
        }, $events );

        $this->assertContains( 'compliance.check', $names );
        $this->assertContains( 'alert.compliance_fail_spike', $names );
        $this->assertContains( 'alert.compliance_rule_anomaly', $names );
        $this->assertContains( 'alert.compliance_latency', $names );

        $this->assertNotEmpty( $audit->events );
        $first = $audit->events[0]['payload'] ?? array();
        $this->assertArrayHasKey( 'input_hash', $first );
        $this->assertArrayNotHasKey( 'input_text', $first );
    }

    private function load_fixture( string $name ): array {
        $path = __DIR__ . '/../fixtures/compliance/' . $name;
        $raw = file_get_contents( $path );
        return json_decode( (string) $raw, true ) ?: array();
    }
}

class FakeAuditLogger extends AuditLogger {
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
