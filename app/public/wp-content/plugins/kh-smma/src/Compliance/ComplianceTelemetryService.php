<?php
namespace KH_SMMA\Compliance;

use KH_SMMA\Observability\EventEmitter;
use KH_SMMA\Services\AuditLogger;

use function current_time;
use function get_option;
use function hash;
use function update_option;
use function wp_json_encode;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ComplianceTelemetryService {
    private AuditLogger $audit;
    private EventEmitter $events;

    public function __construct( AuditLogger $audit, ?EventEmitter $events = null ) {
        $this->audit  = $audit;
        $this->events = $events ?: new EventEmitter();
    }

    public function record_check( array $input ): array {
        $trace_id = (string) ( $input['trace_id'] ?? uniqid( 'comp-', true ) );
        $variant_id = (string) ( $input['variant_id'] ?? '' );
        $schedule_id = (int) ( $input['schedule_id'] ?? 0 );
        $status = strtoupper( (string) ( $input['status'] ?? 'OK' ) );
        $rules = array_values( array_filter( array_map( 'strval', (array) ( $input['rule_ids_triggered'] ?? array() ) ) ) );
        $latency_ms = (int) ( $input['latency_ms'] ?? 0 );
        $timestamp = (int) ( $input['timestamp'] ?? time() );
        $suggested = (array) ( $input['suggested_edits'] ?? array() );
        $input_hash = hash( 'sha256', (string) ( $input['input_text'] ?? '' ) );

        $event_payload = array(
            'trace_id'           => $trace_id,
            'variant_id'         => $variant_id,
            'schedule_id'        => $schedule_id ?: null,
            'status'             => $status,
            'rule_ids_triggered' => $rules,
            'latency_ms'         => $latency_ms,
            'timestamp'          => $timestamp,
        );

        $this->events->emit( 'compliance.check', $event_payload );
        $this->audit->record_event( 'compliance.check', array(
            'trace_id'        => $trace_id,
            'variant_id'      => $variant_id,
            'schedule_id'     => $schedule_id,
            'input_hash'      => $input_hash,
            'status'          => $status,
            'rules_triggered' => $rules,
            'suggested_edits' => $suggested,
            'timestamp'       => current_time( 'mysql' ),
        ) );

        $this->append_history( array(
            'trace_id'   => $trace_id,
            'variant_id' => $variant_id,
            'status'     => $status,
            'rule_ids'   => $rules,
            'latency_ms' => $latency_ms,
            'ts'         => $timestamp,
        ) );

        $alerts = $this->evaluate_alerts();

        if ( $latency_ms > 50 ) {
            $this->events->emit( 'alert.compliance_latency', array(
                'trace_id'   => $trace_id,
                'variant_id' => $variant_id,
                'latency_ms' => $latency_ms,
                'timestamp'  => $timestamp,
            ) );
            $alerts[] = 'alert.compliance_latency';
        }

        return array(
            'trace_id' => $trace_id,
            'alerts'   => $alerts,
        );
    }

    private function append_history( array $entry ): void {
        $history = get_option( 'kh_smma_compliance_history', array() );
        if ( ! is_array( $history ) ) {
            $history = array();
        }

        $history[] = $entry;
        if ( count( $history ) > 100 ) {
            $history = array_slice( $history, -100 );
        }

        update_option( 'kh_smma_compliance_history', $history );
    }

    private function evaluate_alerts(): array {
        $alerts = array();
        $history = get_option( 'kh_smma_compliance_history', array() );
        if ( ! is_array( $history ) || empty( $history ) ) {
            return $alerts;
        }

        $total = count( $history );
        $fails = array_values( array_filter( $history, static function ( array $row ): bool {
            return 'FAIL' === ( $row['status'] ?? '' );
        } ) );
        $warns = array_values( array_filter( $history, static function ( array $row ): bool {
            return 'WARN' === ( $row['status'] ?? '' );
        } ) );

        if ( $total >= 20 && count( $fails ) / $total > 0.2 ) {
            $this->events->emit( 'alert.compliance_fail_spike', array(
                'window'     => $total,
                'fail_count' => count( $fails ),
                'timestamp'  => time(),
            ) );
            $alerts[] = 'alert.compliance_fail_spike';
        }

        $warn_fail_ratio = count( $warns ) > 0 ? count( $fails ) / count( $warns ) : 0.0;
        if ( count( $warns ) >= 5 && $warn_fail_ratio > 1.25 ) {
            $this->events->emit( 'alert.compliance_warn_fail_ratio', array(
                'warn_count' => count( $warns ),
                'fail_count' => count( $fails ),
                'ratio'      => $warn_fail_ratio,
                'timestamp'  => time(),
            ) );
            $alerts[] = 'alert.compliance_warn_fail_ratio';
        }

        $rule_counts = array();
        foreach ( $fails as $row ) {
            foreach ( (array) ( $row['rule_ids'] ?? array() ) as $rule_id ) {
                $rule = (string) $rule_id;
                if ( '' === $rule ) {
                    continue;
                }
                $rule_counts[ $rule ] = ( $rule_counts[ $rule ] ?? 0 ) + 1;
            }
        }

        if ( ! empty( $rule_counts ) && count( $fails ) > 0 ) {
            arsort( $rule_counts );
            $top_rule = (string) key( $rule_counts );
            $top_count = (int) current( $rule_counts );
            if ( $top_count / count( $fails ) > 0.5 ) {
                $this->events->emit( 'alert.compliance_rule_anomaly', array(
                    'rule_id'    => $top_rule,
                    'fail_count' => count( $fails ),
                    'rule_count' => $top_count,
                    'timestamp'  => time(),
                ) );
                $alerts[] = 'alert.compliance_rule_anomaly';
            }
        }

        return $alerts;
    }
}
