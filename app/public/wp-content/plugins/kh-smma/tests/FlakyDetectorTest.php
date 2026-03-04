<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 6) . '/scripts/detect_flaky_tests.php';

class FlakyDetectorTest extends TestCase {
	public function test_classify_stable_pass(): void {
		$this->assertSame('stable_pass', flaky_classify(array(0, 0, 0)));
	}

	public function test_classify_stable_fail(): void {
		$this->assertSame('stable_fail', flaky_classify(array(1, 2, 1)));
	}

	public function test_classify_flaky(): void {
		$this->assertSame('flaky', flaky_classify(array(0, 1, 0, 1)));
	}
}
