<?php

declare(strict_types=1);

$options = triage_parse_options($argv);
$golden_summary_path = (string)($options['golden-summary'] ?? 'artifacts/golden-fast/golden-summary.json');
$flaky_report_path = (string)($options['flaky-report'] ?? 'artifacts/flaky-report.json');
$golden_telemetry_path = (string)($options['golden-telemetry'] ?? 'artifacts/golden-fast/golden-telemetry.json');
$flaky_telemetry_path = (string)($options['flaky-telemetry'] ?? 'artifacts/flaky-telemetry.json');
$output_path = (string)($options['output'] ?? 'artifacts/ci-triage-report.json');
$markdown_path = (string)($options['markdown'] ?? 'artifacts/ci-triage-report.md');

$golden = triage_read_json($golden_summary_path);
$flaky = triage_read_json($flaky_report_path);
$golden_telemetry = triage_read_json($golden_telemetry_path);
$flaky_telemetry = triage_read_json($flaky_telemetry_path);

$golden_result = (string)($golden['result'] ?? 'unknown');
$mismatches = is_array($golden['mismatches'] ?? null) ? $golden['mismatches'] : array();
$golden_mismatch_count = count($mismatches);
$golden_duration_ms = (int)($golden['duration_ms'] ?? 0);

$flaky_summary = is_array($flaky['summary'] ?? null) ? $flaky['summary'] : array();
$flaky_classification = (string)($flaky_summary['classification'] ?? 'unknown');
$flaky_fail_rate = (float)($flaky_summary['fail_rate'] ?? 0.0);
$flaky_runs = (int)($flaky_summary['total_runs'] ?? 0);

$owners = triage_extract_owners($mismatches);
$probable_cause = triage_probable_cause($golden_result, $golden_mismatch_count, $flaky_classification);
$alert_candidates = triage_alert_candidates($golden_duration_ms, $golden_mismatch_count, $flaky_fail_rate, $flaky_runs);

$report = array(
	'generated_at' => gmdate('c'),
	'inputs' => array(
		'golden_summary' => $golden_summary_path,
		'flaky_report' => $flaky_report_path,
		'golden_telemetry' => $golden_telemetry_path,
		'flaky_telemetry' => $flaky_telemetry_path,
	),
	'golden' => array(
		'result' => $golden_result,
		'mismatch_count' => $golden_mismatch_count,
		'duration_ms' => $golden_duration_ms,
	),
	'flaky' => array(
		'classification' => $flaky_classification,
		'fail_rate' => $flaky_fail_rate,
		'total_runs' => $flaky_runs,
	),
	'owners' => $owners,
	'probable_cause' => $probable_cause,
	'recommended_actions' => triage_recommendations($golden_result, $mismatches, $flaky_classification, $flaky_fail_rate),
	'alert_candidates' => $alert_candidates,
	'telemetry_observed' => array(
		'golden_events' => triage_count_events($golden_telemetry),
		'flaky_events' => triage_count_events($flaky_telemetry),
	),
	'links' => array(
		'runbook' => 'docs/observability/alerting_runbook.md',
		'diff_html_hint' => 'artifacts/golden-fast/golden-diff.html',
	),
);

triage_prepare_dir(dirname($output_path));
triage_prepare_dir(dirname($markdown_path));

$json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if ($json === false) {
	fwrite(STDERR, "Unable to encode triage report JSON.\n");
	exit(1);
}
file_put_contents($output_path, $json . PHP_EOL);

$md = triage_to_markdown($report);
file_put_contents($markdown_path, $md);

fwrite(STDOUT, "Triage report JSON: {$output_path}\n");
fwrite(STDOUT, "Triage report MD: {$markdown_path}\n");
exit(0);

/**
 * @return array<string,string>
 */
function triage_parse_options(array $argv): array {
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
function triage_read_json(string $path): array {
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

function triage_prepare_dir(string $path): void {
	if ($path === '' || $path === '.') {
		return;
	}
	if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
		throw new RuntimeException('Unable to create directory: ' . $path);
	}
}

/**
 * @param list<array<string,mixed>> $mismatches
 * @return list<string>
 */
function triage_extract_owners(array $mismatches): array {
	$owners = array();
	foreach ($mismatches as $mismatch) {
		if (!is_array($mismatch)) {
			continue;
		}
		$owner = trim((string)($mismatch['owner'] ?? '@unknown'));
		if ($owner === '') {
			$owner = '@unknown';
		}
		$owners[$owner] = true;
	}
	$out = array_keys($owners);
	sort($out);
	return $out;
}

function triage_probable_cause(string $golden_result, int $golden_mismatch_count, string $flaky_classification): string {
	if ($golden_result === 'failure' && $golden_mismatch_count > 0 && $flaky_classification === 'flaky') {
		return 'mixed: deterministic mismatch plus flaky execution';
	}
	if ($golden_result === 'failure' && $golden_mismatch_count > 0) {
		return 'deterministic fixture or contract mismatch';
	}
	if ($flaky_classification === 'flaky') {
		return 'test instability';
	}
	if ($golden_result === 'success' && in_array($flaky_classification, array('stable_pass', 'unknown'), true)) {
		return 'no active reliability issue';
	}
	return 'unknown';
}

/**
 * @return list<array<string,mixed>>
 */
function triage_alert_candidates(int $golden_duration_ms, int $golden_mismatch_count, float $flaky_fail_rate, int $flaky_runs): array {
	$candidates = array();
	if ($golden_mismatch_count > 0) {
		$candidates[] = array(
			'alert_id' => 'cic_golden_check_failure_rate_p0',
			'severity' => 'P0',
			'reason' => 'Golden mismatches detected in deterministic checks',
		);
	}
	if ($golden_duration_ms > 600000) {
		$candidates[] = array(
			'alert_id' => 'cic_golden_check_duration_p1',
			'severity' => 'P1',
			'reason' => 'Golden check duration exceeded 10 minutes',
		);
	}
	if ($flaky_runs >= 10 && $flaky_fail_rate > 0.2) {
		$candidates[] = array(
			'alert_id' => 'cic_flaky_tests_detected_p2',
			'severity' => 'P2',
			'reason' => sprintf('Flaky fail rate %.2f exceeded threshold 0.20', $flaky_fail_rate),
		);
	}
	return $candidates;
}

/**
 * @param list<array<string,mixed>> $mismatches
 * @return list<string>
 */
function triage_recommendations(string $golden_result, array $mismatches, string $flaky_classification, float $flaky_fail_rate): array {
	$actions = array();

	if ($golden_result === 'failure' && !empty($mismatches)) {
		$actions[] = 'Download golden-diff HTML artifact and inspect owner-tagged mismatch entries.';
		$actions[] = 'Run local reproduction: php scripts/dev_golden_check.php --fixture <fixture> --output artifacts/dev-golden-check';
		$actions[] = 'Run correlator again after reproduction: php scripts/ci_triage_report.php --golden-summary artifacts/golden-fast/golden-summary.json';
	}

	if ($flaky_classification === 'flaky') {
		$actions[] = sprintf('Flaky fail rate %.2f detected; apply flake-investigate and assign owner.', $flaky_fail_rate);
		$actions[] = 'Re-run detector with 10+ iterations and inspect first/last failure traces.';
	}

	if (empty($actions)) {
		$actions[] = 'No immediate action. Continue monitoring CIC dashboards and alert channels.';
	}

	return $actions;
}

/**
 * @param array<string,mixed> $telemetry
 */
function triage_count_events(array $telemetry): int {
	if ($telemetry === array()) {
		return 0;
	}
	if (isset($telemetry[0]) && is_array($telemetry[0])) {
		return count($telemetry);
	}
	if (isset($telemetry['events']) && is_array($telemetry['events'])) {
		return count($telemetry['events']);
	}
	return 0;
}

/**
 * @param array<string,mixed> $report
 */
function triage_to_markdown(array $report): string {
	$lines = array();
	$lines[] = '# CIC Triage Report';
	$lines[] = '';
	$lines[] = '- Generated: ' . (string)($report['generated_at'] ?? '');
	$lines[] = '- Golden result: ' . (string)($report['golden']['result'] ?? 'unknown');
	$lines[] = '- Golden mismatches: ' . (string)($report['golden']['mismatch_count'] ?? 0);
	$lines[] = '- Golden duration (ms): ' . (string)($report['golden']['duration_ms'] ?? 0);
	$lines[] = '- Flaky classification: ' . (string)($report['flaky']['classification'] ?? 'unknown');
	$lines[] = '- Flaky fail rate: ' . (string)($report['flaky']['fail_rate'] ?? 0.0);
	$lines[] = '- Probable cause: ' . (string)($report['probable_cause'] ?? 'unknown');
	$lines[] = '';
	$lines[] = '## Owners';
	$owners = is_array($report['owners'] ?? null) ? $report['owners'] : array();
	foreach ($owners as $owner) {
		$lines[] = '- ' . (string)$owner;
	}
	if (empty($owners)) {
		$lines[] = '- @ci-qa-team';
	}
	$lines[] = '';
	$lines[] = '## Alert Candidates';
	$alerts = is_array($report['alert_candidates'] ?? null) ? $report['alert_candidates'] : array();
	foreach ($alerts as $alert) {
		if (!is_array($alert)) {
			continue;
		}
		$lines[] = sprintf('- [%s] %s - %s', (string)($alert['severity'] ?? 'P?'), (string)($alert['alert_id'] ?? 'unknown'), (string)($alert['reason'] ?? ''));
	}
	if (empty($alerts)) {
		$lines[] = '- none';
	}
	$lines[] = '';
	$lines[] = '## Recommended Actions';
	$actions = is_array($report['recommended_actions'] ?? null) ? $report['recommended_actions'] : array();
	foreach ($actions as $action) {
		$lines[] = '- ' . (string)$action;
	}
	$lines[] = '';
	$lines[] = 'Runbook: ' . (string)($report['links']['runbook'] ?? 'docs/observability/alerting_runbook.md');
	$lines[] = '';
	return implode(PHP_EOL, $lines) . PHP_EOL;
}
