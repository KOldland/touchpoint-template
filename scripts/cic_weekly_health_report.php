<?php

declare(strict_types=1);

$options = wh_parse_options($argv);
$input_path = (string)($options['input'] ?? 'artifacts/weekly-workflow-runs.json');
$output_json = (string)($options['output-json'] ?? 'artifacts/weekly-health-report.json');
$output_md = (string)($options['output-md'] ?? 'artifacts/weekly-health-report.md');
$window_days = max(1, (int)($options['window-days'] ?? 7));

$input = wh_read_json($input_path);
$workflows = is_array($input['workflows'] ?? null) ? $input['workflows'] : array();

$golden_runs = wh_cast_runs($workflows['golden-check'] ?? array());
$smoke_runs = wh_cast_runs($workflows['smoke-harness'] ?? array());
$flaky_runs = wh_cast_runs($workflows['flaky-detect'] ?? array());

$report = array(
	'generated_at' => gmdate('c'),
	'window_days' => $window_days,
	'metrics' => array(
		'golden_check' => wh_summarize_runs($golden_runs),
		'smoke_harness' => wh_summarize_runs($smoke_runs),
		'flaky_detect' => wh_summarize_runs($flaky_runs),
	),
	'alerts' => wh_compute_alerts($golden_runs, $smoke_runs, $flaky_runs),
);

wh_prepare_dir(dirname($output_json));
wh_prepare_dir(dirname($output_md));

$json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if ($json === false) {
	fwrite(STDERR, "Unable to encode weekly health report JSON.\n");
	exit(1);
}
file_put_contents($output_json, $json . PHP_EOL);
file_put_contents($output_md, wh_to_markdown($report));

$alert_count = is_array($report['alerts']) ? count($report['alerts']) : 0;
fwrite(STDOUT, "Weekly health report: {$output_json}\n");
fwrite(STDOUT, "Weekly health markdown: {$output_md}\n");
fwrite(STDOUT, "Alert count: {$alert_count}\n");
exit(0);

/**
 * @return array<string,string>
 */
function wh_parse_options(array $argv): array {
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

/**
 * @return array<string,mixed>
 */
function wh_read_json(string $path): array {
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
 * @param mixed $raw
 * @return list<array<string,mixed>>
 */
function wh_cast_runs($raw): array {
	if (!is_array($raw)) {
		return array();
	}
	$runs = array();
	foreach ($raw as $item) {
		if (is_array($item)) {
			$runs[] = $item;
		}
	}
	return $runs;
}

/**
 * @param list<array<string,mixed>> $runs
 * @return array<string,mixed>
 */
function wh_summarize_runs(array $runs): array {
	$total = count($runs);
	$success = 0;
	$failure = 0;
	$durations = array();

	foreach ($runs as $run) {
		$conclusion = (string)($run['conclusion'] ?? '');
		if ($conclusion === 'success') {
			$success++;
		} elseif ($conclusion !== '') {
			$failure++;
		}

		$duration_ms = wh_duration_ms($run);
		if ($duration_ms > 0) {
			$durations[] = $duration_ms;
		}
	}

	$pass_rate = $total > 0 ? round($success / $total, 4) : 0.0;
	$failure_rate = $total > 0 ? round($failure / $total, 4) : 0.0;

	return array(
		'total_runs' => $total,
		'success_runs' => $success,
		'failure_runs' => $failure,
		'pass_rate' => $pass_rate,
		'failure_rate' => $failure_rate,
		'duration_p50_ms' => wh_percentile_ms($durations, 50),
		'duration_p90_ms' => wh_percentile_ms($durations, 90),
	);
}

/**
 * @param array<string,mixed> $run
 */
function wh_duration_ms(array $run): int {
	$start = (string)($run['run_started_at'] ?? '');
	$end = (string)($run['updated_at'] ?? '');
	$start_ts = strtotime($start);
	$end_ts = strtotime($end);
	if ($start_ts === false || $end_ts === false || $end_ts < $start_ts) {
		return 0;
	}
	return (int)(($end_ts - $start_ts) * 1000);
}

/**
 * @param list<int> $values
 */
function wh_percentile_ms(array $values, int $percentile): int {
	if (empty($values)) {
		return 0;
	}
	sort($values);
	$index = (int)ceil(($percentile / 100) * count($values)) - 1;
	$index = max(0, min($index, count($values) - 1));
	return (int)$values[$index];
}

/**
 * @param list<array<string,mixed>> $golden_runs
 * @param list<array<string,mixed>> $smoke_runs
 * @param list<array<string,mixed>> $flaky_runs
 * @return list<array<string,mixed>>
 */
function wh_compute_alerts(array $golden_runs, array $smoke_runs, array $flaky_runs): array {
	$alerts = array();
	$now = time();

	$golden_last_hour = wh_runs_since($golden_runs, $now - 3600);
	$golden_hour_summary = wh_summarize_runs($golden_last_hour);
	$golden_hour_failure_rate = (float)($golden_hour_summary['failure_rate'] ?? 0.0);
	if ($golden_hour_failure_rate > 0.10) {
		$alerts[] = array(
			'severity' => 'P0',
			'code' => 'golden_check_failure_rate_high',
			'message' => 'golden_check_failure_rate > 10% in last hour',
			'value' => $golden_hour_failure_rate,
		);
	}

	$golden_summary = wh_summarize_runs($golden_runs);
	$golden_p90_minutes = ((int)($golden_summary['duration_p90_ms'] ?? 0)) / 60000;
	if ($golden_p90_minutes > 10) {
		$alerts[] = array(
			'severity' => 'P1',
			'code' => 'golden_check_duration_p90_high',
			'message' => 'golden_check_duration_p90 > 10 minutes',
			'value_minutes' => round($golden_p90_minutes, 2),
		);
	}

	$smoke_summary = wh_summarize_runs($smoke_runs);
	$smoke_failure_rate = (float)($smoke_summary['failure_rate'] ?? 0.0);
	if (($smoke_summary['total_runs'] ?? 0) > 0 && $smoke_failure_rate > 0.05) {
		$alerts[] = array(
			'severity' => 'P1',
			'code' => 'smoke_harness_failure_rate_high',
			'message' => 'smoke_harness_failure_rate > 5%',
			'value' => $smoke_failure_rate,
		);
	}

	$flaky_last_day = wh_runs_since($flaky_runs, $now - 86400);
	$flaky_failures = 0;
	foreach ($flaky_last_day as $run) {
		$conclusion = (string)($run['conclusion'] ?? '');
		if ($conclusion !== '' && $conclusion !== 'success') {
			$flaky_failures++;
		}
	}
	if ($flaky_failures > 5) {
		$alerts[] = array(
			'severity' => 'P2',
			'code' => 'flaky_detect_count_high',
			'message' => 'flaky-detect non-success runs > 5 in 24h',
			'value' => $flaky_failures,
		);
	}

	return $alerts;
}

/**
 * @param list<array<string,mixed>> $runs
 * @return list<array<string,mixed>>
 */
function wh_runs_since(array $runs, int $epoch): array {
	$filtered = array();
	foreach ($runs as $run) {
		$created = strtotime((string)($run['created_at'] ?? ''));
		if ($created === false) {
			continue;
		}
		if ($created >= $epoch) {
			$filtered[] = $run;
		}
	}
	return $filtered;
}

/**
 * @param array<string,mixed> $report
 */
function wh_to_markdown(array $report): string {
	$metrics = is_array($report['metrics'] ?? null) ? $report['metrics'] : array();
	$golden = is_array($metrics['golden_check'] ?? null) ? $metrics['golden_check'] : array();
	$smoke = is_array($metrics['smoke_harness'] ?? null) ? $metrics['smoke_harness'] : array();
	$flaky = is_array($metrics['flaky_detect'] ?? null) ? $metrics['flaky_detect'] : array();
	$alerts = is_array($report['alerts'] ?? null) ? $report['alerts'] : array();

	$lines = array();
	$lines[] = '# CIC Weekly Reliability Health';
	$lines[] = '';
	$lines[] = '- Generated: ' . (string)($report['generated_at'] ?? '');
	$lines[] = '- Window: ' . (string)($report['window_days'] ?? 7) . ' days';
	$lines[] = '';
	$lines[] = '## Golden Check';
	$lines[] = '- Total runs: ' . (string)($golden['total_runs'] ?? 0);
	$lines[] = '- Pass rate: ' . (string)($golden['pass_rate'] ?? 0.0);
	$lines[] = '- Failure rate: ' . (string)($golden['failure_rate'] ?? 0.0);
	$lines[] = '- Duration P50 (ms): ' . (string)($golden['duration_p50_ms'] ?? 0);
	$lines[] = '- Duration P90 (ms): ' . (string)($golden['duration_p90_ms'] ?? 0);
	$lines[] = '';
	$lines[] = '## Smoke Harness';
	$lines[] = '- Total runs: ' . (string)($smoke['total_runs'] ?? 0);
	$lines[] = '- Pass rate: ' . (string)($smoke['pass_rate'] ?? 0.0);
	$lines[] = '- Failure rate: ' . (string)($smoke['failure_rate'] ?? 0.0);
	$lines[] = '';
	$lines[] = '## Flaky Detect';
	$lines[] = '- Total runs: ' . (string)($flaky['total_runs'] ?? 0);
	$lines[] = '- Pass rate: ' . (string)($flaky['pass_rate'] ?? 0.0);
	$lines[] = '- Failure rate: ' . (string)($flaky['failure_rate'] ?? 0.0);
	$lines[] = '';
	$lines[] = '## Alerts';
	if (empty($alerts)) {
		$lines[] = '- None';
	} else {
		foreach ($alerts as $alert) {
			$sev = (string)($alert['severity'] ?? 'P?');
			$msg = (string)($alert['message'] ?? 'alert');
			$code = (string)($alert['code'] ?? 'unknown');
			$lines[] = sprintf('- [%s] %s (`%s`)', $sev, $msg, $code);
		}
	}
	$lines[] = '';
	return implode(PHP_EOL, $lines) . PHP_EOL;
}

function wh_prepare_dir(string $path): void {
	if ($path === '' || $path === '.') {
		return;
	}
	if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
		throw new RuntimeException('Unable to create directory: ' . $path);
	}
}
