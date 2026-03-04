<?php

declare(strict_types=1);

$options = triage_parse_options($argv);
$golden_summary_path = (string)($options['golden-summary'] ?? 'artifacts/golden-fast/golden-summary.json');
$flaky_report_path = (string)($options['flaky-report'] ?? 'artifacts/flaky-report.json');
$output_path = (string)($options['output'] ?? 'artifacts/ci-triage-report.json');
$markdown_path = (string)($options['markdown'] ?? 'artifacts/ci-triage-report.md');

$golden = triage_read_json($golden_summary_path);
$flaky = triage_read_json($flaky_report_path);

$golden_result = (string)($golden['result'] ?? 'unknown');
$mismatches = is_array($golden['mismatches'] ?? null) ? $golden['mismatches'] : array();
$flaky_summary = is_array($flaky['summary'] ?? null) ? $flaky['summary'] : array();
$flaky_classification = (string)($flaky_summary['classification'] ?? 'unknown');
$flaky_fail_rate = (float)($flaky_summary['fail_rate'] ?? 0.0);

$owners = array();
foreach ($mismatches as $mismatch) {
	if (!is_array($mismatch)) {
		continue;
	}
	$owner = (string)($mismatch['owner'] ?? '@unknown');
	$owners[$owner] = true;
}

$probable_cause = 'unknown';
if ($golden_result === 'failure' && !empty($mismatches) && $flaky_classification === 'flaky') {
	$probable_cause = 'mixed: payload mismatch + flaky execution';
} elseif ($golden_result === 'failure' && !empty($mismatches)) {
	$probable_cause = 'deterministic fixture or contract mismatch';
} elseif ($flaky_classification === 'flaky') {
	$probable_cause = 'test instability';
} elseif ($golden_result === 'success' && in_array($flaky_classification, array('stable_pass', 'unknown'), true)) {
	$probable_cause = 'no active reliability issue';
}

$report = array(
	'generated_at' => gmdate('c'),
	'golden' => array(
		'summary_path' => $golden_summary_path,
		'result' => $golden_result,
		'mismatch_count' => count($mismatches),
	),
	'flaky' => array(
		'report_path' => $flaky_report_path,
		'classification' => $flaky_classification,
		'fail_rate' => $flaky_fail_rate,
	),
	'owners' => array_keys($owners),
	'probable_cause' => $probable_cause,
	'recommended_actions' => triage_recommendations($golden_result, $mismatches, $flaky_classification, $flaky_fail_rate),
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
function triage_recommendations(string $golden_result, array $mismatches, string $flaky_classification, float $flaky_fail_rate): array {
	$actions = array();

	if ($golden_result === 'failure' && !empty($mismatches)) {
		$actions[] = 'Download golden diff artifact and inspect owner-tagged mismatch entries.';
		$actions[] = 'Run local reproduction: php scripts/dev_golden_check.php --fixture <fixture> --output artifacts/dev-golden-check';
	}

	if ($flaky_classification === 'flaky') {
		$actions[] = sprintf('Flaky fail rate %.2f detected; apply flake-investigate and assign owner.', $flaky_fail_rate);
		$actions[] = 'Re-run detector with 10+ iterations and inspect first/last failure traces.';
	}

	if (empty($actions)) {
		$actions[] = 'No immediate action. Continue monitoring dashboard and alert channels.';
	}

	return $actions;
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
	$lines[] = '## Recommended Actions';
	$actions = is_array($report['recommended_actions'] ?? null) ? $report['recommended_actions'] : array();
	foreach ($actions as $action) {
		$lines[] = '- ' . (string)$action;
	}
	$lines[] = '';
	return implode(PHP_EOL, $lines) . PHP_EOL;
}
