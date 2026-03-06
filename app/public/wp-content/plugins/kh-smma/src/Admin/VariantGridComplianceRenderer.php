<?php
namespace KH_SMMA\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class VariantGridComplianceRenderer {
    public function normalize_status( string $status ): string {
        $value = strtoupper( trim( $status ) );
        if ( in_array( $value, array( 'OK', 'WARN', 'FAIL' ), true ) ) {
            return $value;
        }

        return 'OK';
    }

    public function badge_class( string $status ): string {
        $normalized = $this->normalize_status( $status );
        if ( 'FAIL' === $normalized ) {
            return 'khm-badge-fail';
        }
        if ( 'WARN' === $normalized ) {
            return 'khm-badge-warn';
        }

        return 'khm-badge-ok';
    }

    public function resolve_actions( string $status ): array {
        $normalized = $this->normalize_status( $status );
        return array(
            'can_schedule'      => 'FAIL' !== $normalized,
            'can_request_approval' => 'WARN' === $normalized,
            'can_edit_resubmit' => in_array( $normalized, array( 'WARN', 'FAIL' ), true ),
            'blocked_reason'    => 'FAIL' === $normalized ? 'Scheduling blocked due to compliance violation.' : '',
        );
    }

    public function build_payload( array $variant ): array {
        $status = $this->normalize_status( (string) ( $variant['compliance_status'] ?? 'OK' ) );
        $actions = $this->resolve_actions( $status );

        return array(
            'status'    => $status,
            'badge'     => array(
                'label' => $status,
                'class' => $this->badge_class( $status ),
            ),
            'rationale' => array(
                'rule_id'  => is_array( $variant['rules_triggered'] ?? null ) ? implode( ',', $variant['rules_triggered'] ) : '',
                'reason'   => (string) ( $variant['rationale'] ?? '' ),
                'severity' => $status,
            ),
            'actions'   => $actions,
        );
    }
}
