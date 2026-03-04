<?php

declare(strict_types=1);

if (!function_exists('flaky_run')) {
	/**
	 * @return array<string,mixed>
	 */
	function flaky_run(string $command, int $runs): array {
		$results = array();
		for ($i = 1; $i <= $runs; $i++) {
			$start = microtime(true);
			$out = array();
			$exit = 0;
			exec($command . ' 2>&1', $out, $exit);
			$duration_ms = (int) round((microtime(true) - $start) * 1000);
			$results[] = array(
				'run' => $i,
				'exit_code' => $exit,
				'duration_ms' => $duration_ms,
				'output_tail' => array_slice($out, -40),
				'stack_trace' => flaky_extract_stack_trace($out),
			);
		}

		$summary = flaky_build_summary($results);
		return array(
			'command' => $command,
			'runs' => $runs,
			'summary' => $summary,
			'results' => $results,
		);
	}
}

if (!function_exists('flaky_build_summary')) {
	/**
	 * @param list<array<string,mixed>> $results
	 * @return array<string,mixed>
	 */
	function flaky_build_summary(array $results): array {
		$total = count($results);
		$failures = array_values(array_filter($results, static fn(array $r): bool => (int)($r['exit_code'] ?? 1) !== 0));
		$failure_count = count($failures);
		$pass_count = $total - $failure_count;
		$fail_rate = $total > 0 ? round($failure_count / $total, 4) : 0.0;

		$exit_codes = array_values(array_map(static fn(array $r): int => (int)($r['exit_code'] ?? 1), $results));
		$classification = flaky_classify($exit_codes);

		$first_failure = $failures[0] ?? null;
		$last_failure = !empty($failures) ? $failures[array_key_last($failures)] : null;

		return array(
			'classification' => $classification,
			'total_runs' => $total,
			'pass_count' => $pass_count,
			'failure_count' => $failure_count,
			'fail_rate' => $fail_rate,
			'exit_codes' => $exit_codes,
			'first_failure_trace' => is_array($first_failure) ? ($first_failure['stack_trace'] ?? '') : '',
			'last_failure_trace' => is_array($last_failure) ? ($last_failure['stack_trace'] ?? '') : '',
		);
	}
}

if (!function_exists('flaky_classify')) {
	/**
	 * @param list<int> $exit_codes
	 */
	function flaky_classify(array $exit_codes): string {
		$all_pass = !empty($exit_codes) && count(array_filter($exit_codes, static fn(int $v): bool => $v === 0)) === count($exit_codes);
		$all_fail = !empty($exit_codes) && count(array_filter($exit_codes, static fn(int $v): bool => $v !== 0)) === count($exit_codes);

		if ($all_pass) {
			return 'stable_pass';
		}
		if ($all_fail) {
			return 'stable_fail';
		}
		return 'flaky';
	}
}

if (!function_exists('flaky_extract_stack_trace')) {
	/**
	 * @param list<string> $lines
	 */
	function flaky_extract_stack_trace(array $lines): string {
		$matches = array();
		foreach ($lines as $line) {
			$trimmed = trim($line);
			if ($trimmed === '') {
				continue;
			}
			if (str_contains($trimmed, 'Stack trace') || preg_match('/^#\d+\s+/', $trimmed) === 1 || str_contains($trimmed, 'Fatal error') || str_contains($trimmed, 'PHP Fatal error')) {
				$matches[] = $trimmed;
			}
		}
		if (empty($matches)) {
			return '';
		}
		return implode("\n", $matches);
	}
}

if (PHP_SAPI === 'cli' && realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
	$options = flaky_parse_options($argv);
	$runs = max(2, (int)($options['runs'] ?? 10));
	$command = (string)($options['command'] ?? '');
	$test = (string)($options['test'] ?? '');
	$output_path = (string)($options['output'] ?? 'artifacts/flaky-report.json');
	$append_doc = (string)($options['append-doc'] ?? '');
	$owner = (string)($options['owner'] ?? '@ci-qa-team');
	$flake_threshold = (float)($options['flake-threshold'] ?? 0.2);
	$label = (string)($options['label'] ?? 'flake-investigate');
	$label_signal = (string)($options['label-signal'] ?? '');
	$telemetry_path = (string)($options['telemetry'] ?? '');
	$run_id = (string)($options['run-id'] ?? getenv('GITHUB_RUN_ID') ?: 'local');

	if ($command === '' && $test === '') {
		fwrite(STDERR, "Usage: php scripts/detect_flaky_tests.php --command \"<cmd>\" [--runs 10] [--output path]\n");
		fwrite(STDERR, "   or: php scripts/detect_flaky_tests.php --test tests/SomeTest.php [--runs 10]\n");
		exit(1);
	}

	if ($command === '') {
		$phpunit = 'app/public/wp-content/plugins/kh-smma/vendor/bin/phpunit';
		$command = $phpunit . ' ' . escapeshellarg($test) . ' --colors=never';
	}

	$result = flaky_run($command, $runs);
	$summary = is_array($result['summary'] ?? null) ? $result['summary'] : array();
	$classification = (string)($summary['classification'] ?? 'unknown');
	$fail_rate = (float)($summary['fail_rate'] ?? 0.0);

	$output_dir = dirname($output_path);
	if (!is_dir($output_dir) && !mkdir($output_dir, 0775, true) && !is_dir($output_dir)) {
		fwrite(STDERR, "Unable to create output directory: {$output_dir}\n");
		exit(1);
	}

	$encoded = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
	if ($encoded === false) {
		fwrite(STDERR, "Unable to encode flaky report JSON.\n");
		exit(1);
	}
	file_put_contents($output_path, $encoded . PHP_EOL);

	if ($telemetry_path !== '') {
		$telemetry_dir = dirname($telemetry_path);
		if (!is_dir($telemetry_dir) && !mkdir($telemetry_dir, 0775, true) && !is_dir($telemetry_dir)) {
			fwrite(STDERR, "Unable to create telemetry directory: {$telemetry_dir}\n");
			exit(1);
		}
		$event = array(
			array(
				'event' => 'cic.flaky_tests.detected',
				'run_id' => $run_id,
				'command' => $command,
				'classification' => $classification,
				'fail_rate' => $fail_rate,
				'failure_count' => (int)($summary['failure_count'] ?? 0),
				'total_runs' => (int)($summary['total_runs'] ?? $runs),
				'owner' => $owner,
				'timestamp' => gmdate('c'),
			),
		);
		$telemetry_json = json_encode($event, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
		if ($telemetry_json !== false) {
			file_put_contents($telemetry_path, $telemetry_json . PHP_EOL);
		}
	}

	fwrite(STDOUT, "classification: {$classification}\n");
	fwrite(STDOUT, "fail_rate: {$fail_rate}\n");
	fwrite(STDOUT, "report: {$output_path}\n");

	if ($append_doc !== '') {
		$doc_dir = dirname($append_doc);
		if (!is_dir($doc_dir) && !mkdir($doc_dir, 0775, true) && !is_dir($doc_dir)) {
			fwrite(STDERR, "Unable to create doc directory: {$doc_dir}\n");
			exit(1);
		}
		$line = sprintf(
			"| %s | %s | %s | %.2f | %s |\n",
			gmdate('Y-m-d H:i:s') . ' UTC',
			str_replace('|', '\\|', $command),
			$classification,
			$fail_rate,
			$owner
		);
		if (!is_file($append_doc)) {
			file_put_contents($append_doc, "# Flaky Tests\n\n| Timestamp | Command | Classification | Fail Rate | Owner |\n|---|---|---|---:|---|\n");
		}
		file_put_contents($append_doc, $line, FILE_APPEND);
		fwrite(STDOUT, "updated: {$append_doc}\n");
	}

	if ($label_signal !== '' && $classification === 'flaky' && $fail_rate >= $flake_threshold) {
		$signal_dir = dirname($label_signal);
		if (!is_dir($signal_dir) && !mkdir($signal_dir, 0775, true) && !is_dir($signal_dir)) {
			fwrite(STDERR, "Unable to create label signal directory: {$signal_dir}\n");
			exit(1);
		}
		$signal = array(
			'apply_label' => true,
			'label' => $label,
			'classification' => $classification,
			'fail_rate' => $fail_rate,
			'threshold' => $flake_threshold,
			'owner' => $owner,
			'command' => $command,
		);
		$signal_json = json_encode($signal, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
		if ($signal_json !== false) {
			file_put_contents($label_signal, $signal_json . PHP_EOL);
			fwrite(STDOUT, "label_signal: {$label_signal}\n");
		}
	}

	if ($classification === 'stable_pass') {
		exit(0);
	}
	if ($classification === 'stable_fail') {
		exit(1);
	}

	fwrite(STDERR, "Detected flaky test behavior. Recommend {$label} when fail_rate >= {$flake_threshold}.\n");
	exit(2);
}

/**
 * @return array<string,string>
 */
function flaky_parse_options(array $argv): array {
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
