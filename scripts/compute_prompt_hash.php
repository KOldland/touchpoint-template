<?php

declare(strict_types=1);

$options = parse_options($argv);
$prompt = (string)($options['prompt'] ?? '');
$file = (string)($options['file'] ?? '');

if ($prompt === '' && $file === '') {
	fwrite(STDERR, "Usage: php scripts/compute_prompt_hash.php --prompt 'text' | --file docs/contracts/prompts/<name>.txt\n");
	exit(1);
}

if ($file !== '') {
	if (!is_file($file)) {
		fwrite(STDERR, "Prompt file not found: {$file}\n");
		exit(1);
	}
	$prompt = (string)file_get_contents($file);
}

$hash = hash('sha256', trim($prompt));
fwrite(STDOUT, 'sha256:' . $hash . PHP_EOL);

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
