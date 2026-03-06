<?php
namespace KH_SMMA\Compliance;

use function preg_quote;
use function preg_replace;
use function sanitize_text_field;
use function stripos;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SuggestedEditService {
    private array $rules = array(
        'banned_phrase_guarantee' => array(
            'phrases'     => array( 'guaranteed results', 'guarantee results', '100% guaranteed' ),
            'replacement' => 'designed to help achieve results',
            'reason'      => 'absolute guarantee claims are not permitted',
        ),
        'absolute_claim_best' => array(
            'phrases'     => array( 'best', 'number one', '#1' ),
            'replacement' => 'one of the leading solutions',
            'reason'      => 'absolute superiority claims require substantiation',
        ),
        'unverified_performance' => array(
            'phrases'     => array( 'instant results', 'unlimited growth', 'no risk', 'risk-free returns' ),
            'replacement' => 'reported to improve performance',
            'reason'      => 'performance claims should be qualified',
        ),
    );

    public function generate_for_text( string $text, array $rules_triggered = array() ): array {
        $suggestions = array();

        $rule_ids = $rules_triggered;
        if ( empty( $rule_ids ) ) {
            $rule_ids = array_keys( $this->rules );
        }

        foreach ( $rule_ids as $rule_id ) {
            if ( ! isset( $this->rules[ $rule_id ] ) ) {
                continue;
            }

            foreach ( $this->rules[ $rule_id ]['phrases'] as $phrase ) {
                if ( stripos( $text, $phrase ) === false ) {
                    continue;
                }

                $suggestions[] = array(
                    'rule_id'         => $rule_id,
                    'original_phrase' => $phrase,
                    'suggested_phrase'=> $this->rules[ $rule_id ]['replacement'],
                    'reason'          => $this->rules[ $rule_id ]['reason'],
                );
            }
        }

        if ( empty( $suggestions ) && ! empty( $rules_triggered ) ) {
            foreach ( $rules_triggered as $rule_id ) {
                $suggestions[] = array(
                    'rule_id'         => sanitize_text_field( (string) $rule_id ),
                    'original_phrase' => '',
                    'suggested_phrase'=> '',
                    'reason'          => 'Adjust wording to remove absolute or unverified claims.',
                );
            }
        }

        return $suggestions;
    }

    public function infer_rules_from_compliance_notes( string $notes ): array {
        $normalized = strtolower( $notes );
        $rules = array();

        if ( false !== strpos( $normalized, 'blocked phrase' ) || false !== strpos( $normalized, 'blacklist' ) || false !== strpos( $normalized, 'guarantee' ) ) {
            $rules[] = 'banned_phrase_guarantee';
        }
        if ( false !== strpos( $normalized, 'best' ) || false !== strpos( $normalized, 'absolute' ) ) {
            $rules[] = 'absolute_claim_best';
        }
        if ( false !== strpos( $normalized, 'performance' ) || false !== strpos( $normalized, 'risk-free' ) || false !== strpos( $normalized, 'instant' ) ) {
            $rules[] = 'unverified_performance';
        }

        return array_values( array_unique( $rules ) );
    }

    public function apply_suggestions( string $text, array $suggestions ): string {
        $updated = $text;
        foreach ( $suggestions as $suggestion ) {
            $original = (string) ( $suggestion['original_phrase'] ?? '' );
            $replacement = (string) ( $suggestion['suggested_phrase'] ?? '' );
            if ( '' === $original || '' === $replacement ) {
                continue;
            }

            $pattern = '/' . preg_quote( $original, '/' ) . '/i';
            $updated = (string) preg_replace( $pattern, $replacement, $updated );
        }

        return $updated;
    }

    public function get_known_rule_ids(): array {
        return array_keys( $this->rules );
    }
}
