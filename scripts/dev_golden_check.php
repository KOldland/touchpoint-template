<?php

declare(strict_types=1);

$options = dev_parse_options($argv);
$fixture = $options['fixture'] ?? '';
$fixtures = $options['fixtures'] ?? '';
$output_dir = rtrim((string)($options['output'] ?? 'artifacts/dev-golden-check'), '/');
$base = (string)($options['base'] ?? 'origin/main');
$head = (string)($options['head'] ?? 'HEAD');
$open_diffs = isset($options['open']);

if ($fixture !== '' && $fixtures === '') {
	$fixtures = $fixture;
}

if (!is_dir($output_dir) && !mkdir($output_dir, 0775, true) && !is_dir($output_dir)) {
	fwrite(STDERR, "Unable to create output directory: {$output_dir}\n");
	exit(1);
}

$summary_path = $output_dir . '/golden-summary.json';
$diff_dir = $output_dir . '/golden-diffs';
$zip_path = $output_dir . '/golden-diff.zip';

$cmd = array(
	'php',
	'scripts/golden_check.php',
	'--base', $base,
	'--head', $head,
	'--output', $summary_path,
	'--diff-dir', $diff_dir,
	'--zip', $zip_path,
	'--skip-label-check',
);
if ($fixtures !== '') {
	$cmd[] = '--fixtures';
	$cmd[] = $fixtures;
}

$escaped = array_map('escapeshellarg', $cmd);
$command_line = implode(' ', $escaped);

$stdout = array();
$exit_code = 0;
exec($command_line . ' 2>&1', $stdout, $exit_code);

if (!is_file($summary_path)) {
	fwrite(STDERR, "golden_check did not produce summary: {$summary_path}\n");
	foreach ($stdout as $line) {
		fwrite(STDERR, $line . "\n");
	}
	exit($exit_code !== 0 ? $exit_code : 1);
}

$summary_raw = file_get_contents($summary_path);
$summary = is_string($summary_raw) ? json_decode($summary_raw, true) : null;
if (!is_array($summary)) {
	fwrite(STDERR, "Unable to parse summary JSON: {$summary_path}\n");
	exit(1);
}

$result = (string)($summary['result'] ?? 'unknown');
$checked = count($summary['checked_fixtures'] ?? array());
$mismatches = $summary['mismatches'] ?? array();

fwrite(STDOUT, "dev_golden_check result: {$result}\n");
fwrite(STDOUT, "checked_fixtures: {$checked}\n");
fwrite(STDOUT, "summary: {$summary_path}\n");
fwrite(STDOUT, "diff_zip: {$zip_path}\n");

if (!empty($stdout)) {
	fwrite(STDOUT, "\nengine output:\n");
	foreach ($stdout as $line) {
		fwrite(STDOUT, "  {$line}\n");
	}
}

if (empty($mismatches)) {
	fwrite(STDOUT, "\nNo mismatches detected.\n");
	exit($exit_code);
}

fwrite(STDOUT, "\nMismatches:\n");
foreach ($mismatches as $mismatch) {
	$fixture_name = (string)($mismatch['fixture'] ?? 'unknown');
	$owner = (string)($mismatch['owner'] ?? '@unknown');
	$reason = (string)($mismatch['reason'] ?? 'mismatch');
	$diff_file = (string)($mismatch['diff_file'] ?? '');
	fwrite(STDOUT, "- {$fixture_name} ({$owner}) reason={$reason}\n");
	if ($diff_file !== '') {
		fwrite(STDOUT, "  diff: {$diff_file}\n");
		if ($open_diffs) {
			dev_open_file($diff_file);
		}
	}
}

exit($exit_code === 0 ? 1 : $exit_code);

/**
 * @return array<string,string>
 */
function dev_parse_options(array $argv): array {
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

function dev_open_file(string $path): void {
	if (!is_file($path)) {
		return;
	}
	if (PHP_OS_FAMILY === 'Darwin') {
		exec('open ' . escapeshellarg($path) . ' >/dev/null 2>&1');
		return;
	}
	if (PHP_OS_FAMILY === 'Linux') {
		exec('xdg-open ' . escapeshellarg($path) . ' >/dev/null 2>&1');
	}
}
