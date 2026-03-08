<?php

declare(strict_types=1);

$options = rg_parse_options($argv);
$env = (string)($options['env'] ?? 'staging');
$artifact_dir = (string)($options['artifact-dir'] ?? ('artifacts/release/gate-' . $env));
$base = (string)($options['base'] ?? (getenv('GITHUB_BASE_REF') ? 'origin/' . getenv('GITHUB_BASE_REF') : 'origin/main'));
$head = (string)($options['head'] ?? 'HEAD');
$run_golden = !isset($options['run-golden']) || rg_to_bool((string)$options['run-golden']);
$run_smoke = !isset($options['run-smoke']) || rg_to_bool((string)$options['run-smoke']);
$allow_missing_smoke = isset($options['allow-missing-smoke']) && rg_to_bool((string)$options['allow-missing-smoke']);
$simulate_failure = isset($options['simulate-failure']) && rg_to_bool((string)$options['simulate-failure']);
$max_golden_duration_ms = (int)($options['max-golden-duration-ms'] ?? 600000);
$max_golden_mismatch = (int)($options['max-golden-mismatch'] ?? 0);

rg_prepare_dir($artifact_dir);

$checks = array();
$result = 'success';

$golden_summary_path = $artifact_dir . '/golden-summary.json';
$golden_metrics_path = $artifact_dir . '/golden-metrics.json';
$golden_telemetry_path = $artifact_dir . '/golden-telemetry.json';
$smoke_output_dir = $artifact_dir . '/smoke-output';
$smoke_summary_path = $smoke_output_dir . '/smoke-summary.json';
$smoke_telemetry_path = $smoke_output_dir . '/smoke-telemetry.json';

if ($run_golden) {
	$cmd = sprintf(
		'php scripts/golden_check.php --fixtures %s --skip-label-check --base %s --head %s --output %s --diff-dir %s --zip %s --telemetry-out %s --metrics-out %s',
		escapeshellarg('generate_awareness_ok.json,compliance_ok.json'),
		escapeshellarg($base),
		escapeshellarg($head),
		escapeshellarg($golden_summary_path),
		escapeshellarg($artifact_dir . '/golden-diffs'),
		escapeshellarg($artifact_dir . '/golden-diff.zip'),
		escapeshellarg($golden_telemetry_path),
		escapeshellarg($golden_metrics_path)
	);
	$exit = rg_exec($cmd, $output);
	$summary = rg_read_json($golden_summary_path);
	$metrics = rg_read_json($golden_metrics_path);
	$mismatch_count = is_array($summary['mismatches'] ?? null) ? count($summary['mismatches']) : 0;
	$duration = (int)($metrics['golden_check_duration_ms'] ?? ($summary['duration_ms'] ?? 0));
	$ok = $exit === 0 && $mismatch_count <= $max_golden_mismatch && $duration <= $max_golden_duration_ms;
	$checks[] = array(
		'check' => 'golden_check_fast',
		'result' => $ok ? 'pass' : 'fail',
		'exit_code' => $exit,
		'mismatch_count' => $mismatch_count,
		'duration_ms' => $duration,
		'output_tail' => array_slice($output, -20),
	);
	if (!$ok) {
		$result = 'failure';
	}
} else {
	$checks[] = array('check' => 'golden_check_fast', 'result' => 'skipped');
}

if ($run_smoke) {
	rg_prepare_dir($smoke_output_dir);
	if (is_file('scripts/smoke_harness.php')) {
		$cmd = sprintf('php scripts/smoke_harness.php --output %s', escapeshellarg($smoke_output_dir));
		$exit = rg_exec($cmd, $output);
		$ok = $exit === 0;
		$checks[] = array(
			'check' => 'smoke_harness',
			'result' => $ok ? 'pass' : 'fail',
			'exit_code' => $exit,
			'output_tail' => array_slice($output, -20),
		);
		if (!$ok) {
			$result = 'failure';
		}
	} else {
		$smoke_stub = array(
			'result' => 'skipped',
			'reason' => 'smoke_harness_missing',
			'env' => $env,
			'timestamp' => gmdate('c'),
		);
		rg_write_json($smoke_summary_path, $smoke_stub);
		rg_write_json($smoke_telemetry_path, array(array(
			'event' => 'smoke_harness.skipped',
			'env' => $env,
			'reason' => 'smoke_harness_missing',
			'timestamp' => gmdate('c'),
		)));
		$checks[] = array(
			'check' => 'smoke_harness',
			'result' => $allow_missing_smoke ? 'skipped' : 'fail',
			'reason' => 'smoke_harness_missing',
		);
		if (!$allow_missing_smoke) {
			$result = 'failure';
		}
	}
} else {
	$checks[] = array('check' => 'smoke_harness', 'result' => 'skipped');
}

if ($simulate_failure) {
	$checks[] = array('check' => 'synthetic_failure', 'result' => 'fail', 'reason' => 'simulate-failure enabled');
	$result = 'failure';
}

$summary = array(
	'run_id' => getenv('GITHUB_RUN_ID') ?: 'local',
	'env' => $env,
	'base' => $base,
	'head' => $head,
	'allow_missing_smoke' => $allow_missing_smoke,
	'result' => $result,
	'checks' => $checks,
	'golden_summary' => $golden_summary_path,
	'golden_metrics' => $golden_metrics_path,
	'smoke_summary' => $smoke_summary_path,
	'smoke_telemetry' => $smoke_telemetry_path,
	'timestamp' => gmdate('c'),
);

$summary_path = $artifact_dir . '/gate-summary.json';
rg_write_json($summary_path, $summary);
fwrite(STDOUT, "release gate summary: {$summary_path}\n");

if ($result !== 'success') {
	exit(1);
}

exit(0);

/**
 * @return array<string,string>
 */
function rg_parse_options(array $argv): array {
	$options = array();
	for ($i = 1; $i < count($argv); $i++) {
		$arg = (string)$argv[$i];
		if (!str_starts_with($arg, '--')) {
			continue;
		}
		$parts = explode('=', substr($arg, 2), 2);
		$key = $parts[0];
		if (count($parts) === 2) {
			$options[$key] = $parts[1];
			continue;
		}
		$next = $argv[$i + 1] ?? '';
		if (is_string($next) && !str_starts_with($next, '--')) {
			$options[$key] = $next;
			$i++;
		} else {
			$options[$key] = '1';
		}
	}
	return $options;
}

function rg_to_bool(string $value): bool {
	return in_array(strtolower(trim($value)), array('1', 'true', 'yes', 'on'), true);
}

function rg_prepare_dir(string $path): void {
	if ($path === '' || $path === '.') {
		return;
	}
	if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
		throw new RuntimeException('Unable to create directory: ' . $path);
	}
}

/**
 * @return list<string>
 */
function rg_exec(string $cmd, ?array &$output = null): int {
	$buffer = array();
	$exit = 0;
	exec($cmd . ' 2>&1', $buffer, $exit);
	$output = $buffer;
	return $exit;
}

/**
 * @return array<string,mixed>
 */
function rg_read_json(string $path): array {
	if (!is_file($path)) {
		return array();
	}
	$raw = file_get_contents($path);
	if (!is_string($raw) || $raw === '') {
		return array();
	}
	$decoded = json_decode($raw, true);
	return is_array($decoded) ? $decoded : array();
}

/**
 * @param array<string,mixed>|list<array<string,mixed>> $payload
 */
function rg_write_json(string $path, array $payload): void {
	$json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
	if ($json === false) {
		throw new RuntimeException('Unable to encode JSON: ' . $path);
	}
	file_put_contents($path, $json . PHP_EOL);
}
