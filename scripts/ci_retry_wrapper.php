<?php

declare(strict_types=1);

$options = retry_parse_options($argv);
$attempts_opt = isset($options['attempts']) ? (int)$options['attempts'] : null;
$retries_opt = isset($options['retries']) ? (int)$options['retries'] : null;
$attempts = $attempts_opt !== null ? max(1, $attempts_opt) : max(1, ($retries_opt ?? 0) + 1);

$backoff_opt = isset($options['backoff']) ? (int)$options['backoff'] : null;
$delay_ms_opt = isset($options['delay-ms']) ? (int)$options['delay-ms'] : null;
$backoff_seconds = $backoff_opt !== null ? max(1, $backoff_opt) : max(1, (int)ceil(($delay_ms_opt ?? 2000) / 1000));
$command = (string)($options['command'] ?? '');
$step = (string)($options['step'] ?? 'ci.step');
$run_id = (string)($options['run-id'] ?? getenv('GITHUB_RUN_ID') ?: 'local');
$transient_codes = retry_parse_int_list((string)($options['transient-exit-codes'] ?? '75,137,143,255'));
$transient_patterns = retry_parse_string_list((string)($options['transient-patterns'] ?? 'timed out,temporary failure,connection reset,network is unreachable'));
$log_path = (string)($options['log'] ?? 'artifacts/retry-attempts.log');
$telemetry_path = (string)($options['telemetry'] ?? 'artifacts/retry-telemetry.json');

if ($command === '') {
	fwrite(STDERR, "Usage: php scripts/ci_retry_wrapper.php --command \"<cmd>\" [--attempts 2|--retries 1] [--backoff 2|--delay-ms 2000]\n");
	exit(1);
}

retry_prepare_dir(dirname($log_path));
retry_prepare_dir(dirname($telemetry_path));

$log_lines = array();
$telemetry = array();

for ($attempt = 1; $attempt <= $attempts; $attempt++) {
	$started_at = microtime(true);
	$output = array();
	$exit_code = 0;

	$log_lines[] = sprintf('[%s] step=%s attempt=%d/%d command=%s', gmdate('c'), $step, $attempt, $attempts, $command);
	exec($command . ' 2>&1', $output, $exit_code);
	$duration_ms = (int) round((microtime(true) - $started_at) * 1000);
	$output_text = implode("\n", $output);

	$is_transient = retry_is_transient_failure($exit_code, $output_text, $transient_codes, $transient_patterns);

	$telemetry[] = array(
		'event' => 'cic.retrial',
		'run_id' => $run_id,
		'step' => $step,
		'attempt' => $attempt,
		'exit_code' => $exit_code,
		'duration_ms' => $duration_ms,
		'is_transient' => $is_transient,
		'timestamp' => gmdate('c'),
	);

	foreach ($output as $line) {
		$log_lines[] = '  ' . $line;
	}
	$log_lines[] = sprintf('  exit_code=%d duration_ms=%d transient=%s', $exit_code, $duration_ms, $is_transient ? 'true' : 'false');

	if ($exit_code === 0) {
		retry_write_outputs($log_path, $telemetry_path, $log_lines, $telemetry);
		fwrite(STDOUT, "Retry wrapper success on attempt {$attempt}.\n");
		exit(0);
	}

	$has_next = $attempt < $attempts;
	if (!$has_next || !$is_transient) {
		retry_write_outputs($log_path, $telemetry_path, $log_lines, $telemetry);
		if (!$is_transient && $has_next) {
			fwrite(STDERR, "Non-transient failure detected; not retrying.\n");
		}
		fwrite(STDERR, "Retry wrapper failed after {$attempt} attempt(s).\n");
		exit($exit_code);
	}

	$delay_seconds = $backoff_seconds * (2 ** ($attempt - 1));
	$log_lines[] = sprintf('  retry_backoff_seconds=%d', $delay_seconds);
	fwrite(STDERR, "Transient failure (exit {$exit_code}); retrying in {$delay_seconds}s...\n");
	sleep($delay_seconds);
}

retry_write_outputs($log_path, $telemetry_path, $log_lines, $telemetry);
exit(1);

/**
 * @return array<string,string>
 */
function retry_parse_options(array $argv): array {
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
 * @return list<int>
 */
function retry_parse_int_list(string $input): array {
	$values = array();
	foreach (explode(',', $input) as $item) {
		$item = trim($item);
		if ($item === '' || !is_numeric($item)) {
			continue;
		}
		$values[] = (int)$item;
	}
	return array_values(array_unique($values));
}

/**
 * @return list<string>
 */
function retry_parse_string_list(string $input): array {
	$values = array();
	foreach (explode(',', $input) as $item) {
		$item = strtolower(trim($item));
		if ($item === '') {
			continue;
		}
		$values[] = $item;
	}
	return array_values(array_unique($values));
}

/**
 * @param list<int> $transient_codes
 * @param list<string> $transient_patterns
 */
function retry_is_transient_failure(int $exit_code, string $output, array $transient_codes, array $transient_patterns): bool {
	if (in_array($exit_code, $transient_codes, true)) {
		return true;
	}

	$output_lower = strtolower($output);
	foreach ($transient_patterns as $pattern) {
		if ($pattern !== '' && str_contains($output_lower, $pattern)) {
			return true;
		}
	}

	return false;
}

function retry_prepare_dir(string $path): void {
	if ($path === '' || $path === '.') {
		return;
	}
	if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
		throw new RuntimeException('Unable to create directory: ' . $path);
	}
}

/**
 * @param list<string> $log_lines
 * @param list<array<string,mixed>> $telemetry
 */
function retry_write_outputs(string $log_path, string $telemetry_path, array $log_lines, array $telemetry): void {
	file_put_contents($log_path, implode(PHP_EOL, $log_lines) . PHP_EOL);

	$encoded = json_encode($telemetry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
	if ($encoded === false) {
		throw new RuntimeException('Unable to encode retry telemetry JSON.');
	}
	file_put_contents($telemetry_path, $encoded . PHP_EOL);
}
