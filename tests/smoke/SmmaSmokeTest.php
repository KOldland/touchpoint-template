<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../scripts/smoke_harness.php';

class SmmaSmokeTest extends TestCase {
	public function test_smoke_harness_happy_path(): void {
		$output_dir = $this->make_temp_dir('cic-smoke-happy-');

		putenv('KH_SMMA_TEST_MODE=ci');
		putenv('KH_SMMA_GOLDEN_FIXTURE=generate_awareness_ok.json');
		putenv('OPENAI_API_KEY');
		putenv('OPENAI_KEY');
		putenv('ANTHROPIC_API_KEY');
		putenv('ANTHROPIC_KEY');
		putenv('DUAL_GPT_API_KEY');
		putenv('LLM_API_KEY');

		$result = cic_smoke_run_harness(array(
			'fixture' => 'generate_awareness_ok.json',
			'output' => $output_dir,
		));

		$this->assertSame('success', $result['result']);
		$this->assertSame(0, $result['exit_code']);
		$this->assertFileExists($output_dir . '/smoke-summary.json');
		$this->assertFileExists($output_dir . '/smoke-telemetry.json');
		$this->assertFileExists($output_dir . '/smoke-log.txt');
		$this->assertFileExists($output_dir . '/smoke-diffs.zip');

		$telemetry = json_decode((string) file_get_contents($output_dir . '/smoke-telemetry.json'), true);
		$this->assertIsArray($telemetry);
		$this->assertSame(
			array(
				'generate.request',
				'generate.response',
				'compliance.check',
				'variant.edit',
				'schedule.create',
				'paid_adapter.dry_run',
			),
			array_values(array_map(static fn(array $event): string => (string)($event['event'] ?? ''), $telemetry))
		);

		$this->remove_dir($output_dir);
	}

	public function test_smoke_harness_is_deterministic(): void {
		$output_a = $this->make_temp_dir('cic-smoke-a-');
		$output_b = $this->make_temp_dir('cic-smoke-b-');

		putenv('KH_SMMA_TEST_MODE=ci');
		putenv('KH_SMMA_GOLDEN_FIXTURE=generate_awareness_ok.json');
		putenv('OPENAI_API_KEY');
		putenv('OPENAI_KEY');
		putenv('ANTHROPIC_API_KEY');
		putenv('ANTHROPIC_KEY');
		putenv('DUAL_GPT_API_KEY');
		putenv('LLM_API_KEY');

		$result_a = cic_smoke_run_harness(array(
			'fixture' => 'generate_awareness_ok.json',
			'output' => $output_a,
		));
		$result_b = cic_smoke_run_harness(array(
			'fixture' => 'generate_awareness_ok.json',
			'output' => $output_b,
		));

		$this->assertSame('success', $result_a['result']);
		$this->assertSame('success', $result_b['result']);

		$summary_a = json_decode((string) file_get_contents($output_a . '/smoke-summary.json'), true);
		$summary_b = json_decode((string) file_get_contents($output_b . '/smoke-summary.json'), true);
		$this->assertIsArray($summary_a);
		$this->assertIsArray($summary_b);
		$this->assertSame($summary_a['stage_checksums'], $summary_b['stage_checksums']);

		$telemetry_hash_a = hash_file('sha256', $output_a . '/smoke-telemetry.json');
		$telemetry_hash_b = hash_file('sha256', $output_b . '/smoke-telemetry.json');
		$this->assertSame($telemetry_hash_a, $telemetry_hash_b);

		$this->remove_dir($output_a);
		$this->remove_dir($output_b);
	}

	private function make_temp_dir(string $prefix): string {
		$dir = sys_get_temp_dir() . '/' . $prefix . uniqid('', true);
		if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
			throw new RuntimeException('Unable to create temp directory: ' . $dir);
		}
		return $dir;
	}

	private function remove_dir(string $dir): void {
		if (!is_dir($dir)) {
			return;
		}
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ($iterator as $item) {
			if ($item->isDir()) {
				rmdir($item->getPathname());
			} else {
				unlink($item->getPathname());
			}
		}
		rmdir($dir);
	}
}
