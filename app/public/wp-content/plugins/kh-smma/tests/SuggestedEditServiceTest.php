<?php
namespace KH_SMMA\Tests;

use KH_SMMA\Compliance\SuggestedEditService;
use PHPUnit\Framework\TestCase;

class SuggestedEditServiceTest extends TestCase {
    public function test_rule_trigger_returns_expected_suggestion(): void {
        $service = new SuggestedEditService();
        $suggestions = $service->generate_for_text(
            'Our product delivers guaranteed results for teams.',
            array( 'banned_phrase_guarantee' )
        );

        $this->assertNotEmpty( $suggestions );
        $this->assertSame( 'banned_phrase_guarantee', $suggestions[0]['rule_id'] );
        $this->assertSame( 'designed to help achieve results', $suggestions[0]['suggested_phrase'] );
    }

    public function test_multiple_suggestions_returned_when_multiple_rules_match(): void {
        $service = new SuggestedEditService();
        $text = 'Guaranteed results from the best platform with instant results.';
        $suggestions = $service->generate_for_text(
            $text,
            array( 'banned_phrase_guarantee', 'absolute_claim_best', 'unverified_performance' )
        );

        $this->assertGreaterThanOrEqual( 3, count( $suggestions ) );
    }

    public function test_unknown_rule_returns_safe_generic_suggestion(): void {
        $service = new SuggestedEditService();
        $suggestions = $service->generate_for_text( 'Text requiring adjustment', array( 'unknown_rule_id' ) );

        $this->assertCount( 1, $suggestions );
        $this->assertSame( 'unknown_rule_id', $suggestions[0]['rule_id'] );
        $this->assertNotEmpty( $suggestions[0]['reason'] );
    }
}
