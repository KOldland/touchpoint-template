<?php

declare(strict_types=1);

$options = parse_options($argv);
$retries = max(0, (int)($options['retries'] ?? 2));
$base_delay_ms = max(100, (int)($options['delay-ms'] ?? 500));
$command = (string)($options['command'] ?? '');

if ($command === '') {
	fwrite(STDERR, "Usage: php scripts/ci_retry_wrapper.php --command \"<cmd>\" [--retries 2] [--delay-ms 500]\n");
	exit(1);
}

$attempt = 0;
while (true) {
	$attempt++;
	fwrite(STDOUT, "Attempt {$attempt}: {$command}\n");

	passthru($command, $exit_code);
	if ($exit_code === 0) {
		exit(0);
	}

	if ($attempt > $retries) {
		fwrite(STDERR, "Command failed after {$attempt} attempt(s).\n");
		exit($exit_code);
	}

	$delay = (int)($base_delay_ms * (2 ** ($attempt - 1)));
	fwrite(STDERR, "Command failed (exit {$exit_code}). Retrying in {$delay}ms...\n");
	usleep($delay * 1000);
}

/**
 * @return array<string,string>
 */
function parse_options(array $argv): array {
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
