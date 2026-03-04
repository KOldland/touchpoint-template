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
				'output_tail' => array_slice($out, -20),
			);
		}

		$class = flaky_classify(array_map(static fn(array $r): int => (int)$r['exit_code'], $results));
		return array(
			'command' => $command,
			'runs' => $runs,
			'classification' => $class,
			'results' => $results,
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

if (PHP_SAPI === 'cli' && realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
	$options = flaky_parse_options($argv);
	$runs = max(2, (int)($options['runs'] ?? 10));
	$command = (string)($options['command'] ?? '');
	$test = (string)($options['test'] ?? '');
	$output_path = (string)($options['output'] ?? 'artifacts/flaky-report.json');
	$append_doc = (string)($options['append-doc'] ?? '');
	$owner = (string)($options['owner'] ?? '@ci-qa-team');

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

	$output_dir = dirname($output_path);
	if (!is_dir($output_dir) && !mkdir($output_dir, 0775, true) && !is_dir($output_dir)) {
		fwrite(STDERR, "Unable to create output directory: {$output_dir}\n");
		exit(1);
	}
	$json = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
	if ($json === false) {
		fwrite(STDERR, "Unable to encode flaky report JSON.\n");
		exit(1);
	}
	file_put_contents($output_path, $json . PHP_EOL);

	fwrite(STDOUT, "classification: {$result['classification']}\n");
	fwrite(STDOUT, "report: {$output_path}\n");

	if ($append_doc !== '') {
		$doc_dir = dirname($append_doc);
		if (!is_dir($doc_dir) && !mkdir($doc_dir, 0775, true) && !is_dir($doc_dir)) {
			fwrite(STDERR, "Unable to create doc directory: {$doc_dir}\n");
			exit(1);
		}
		$line = sprintf(
			"| %s | %s | %s | %s |\n",
			gmdate('Y-m-d H:i:s') . ' UTC',
			str_replace('|', '\\|', $command),
			$result['classification'],
			$owner
		);
		if (!is_file($append_doc)) {
			file_put_contents($append_doc, "# Flaky Tests\n\n| Timestamp | Command | Classification | Owner |\n|---|---|---|---|\n");
		}
		file_put_contents($append_doc, $line, FILE_APPEND);
		fwrite(STDOUT, "updated: {$append_doc}\n");
	}

	if ($result['classification'] === 'stable_pass') {
		exit(0);
	}
	if ($result['classification'] === 'stable_fail') {
		exit(1);
	}
	fwrite(STDERR, "Detected flaky test behavior. Recommend quarantine label.\n");
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
