<?php

use PHPUnit\Framework\TestCase;

class DevGoldenCheckParityTest extends TestCase {
	public function test_dev_wrapper_matches_engine_summary_for_fixture(): void {
		$root = dirname(__DIR__, 6);
		$out_base = sys_get_temp_dir() . '/dev-golden-parity-' . uniqid('', true);
		$out_engine = $out_base . '/engine';
		$out_wrapper = $out_base . '/wrapper';

		$env = 'KH_SMMA_TEST_MODE=ci OPENAI_API_KEY= OPENAI_KEY= ANTHROPIC_API_KEY= ANTHROPIC_KEY= DUAL_GPT_API_KEY= LLM_API_KEY=';

		$engine_cmd = sprintf(
			'cd %s && %s php scripts/golden_check.php --fixtures generate_awareness_ok.json --skip-label-check --base origin/main --head HEAD --output %s/golden-summary.json --diff-dir %s/diffs --zip %s/diff.zip',
			escapeshellarg($root),
			$env,
			escapeshellarg($out_engine),
			escapeshellarg($out_engine),
			escapeshellarg($out_engine)
		);
		$wrapper_cmd = sprintf(
			'cd %s && %s php scripts/dev_golden_check.php --fixture generate_awareness_ok.json --base origin/main --head HEAD --output %s',
			escapeshellarg($root),
			$env,
			escapeshellarg($out_wrapper)
		);

		exec('mkdir -p ' . escapeshellarg($out_engine) . ' ' . escapeshellarg($out_wrapper));
		exec($engine_cmd . ' 2>&1', $engine_output, $engine_exit);
		exec($wrapper_cmd . ' 2>&1', $wrapper_output, $wrapper_exit);

		$this->assertSame(0, $engine_exit, implode("\n", $engine_output));
		$this->assertSame(0, $wrapper_exit, implode("\n", $wrapper_output));

		$engine_summary = json_decode((string) file_get_contents($out_engine . '/golden-summary.json'), true);
		$wrapper_summary = json_decode((string) file_get_contents($out_wrapper . '/golden-summary.json'), true);

		$this->assertIsArray($engine_summary);
		$this->assertIsArray($wrapper_summary);
		$this->assertSame($engine_summary['result'], $wrapper_summary['result']);
		$this->assertSame($engine_summary['checked_fixtures'], $wrapper_summary['checked_fixtures']);
		$this->assertSame($engine_summary['mismatches'], $wrapper_summary['mismatches']);
	}
}
