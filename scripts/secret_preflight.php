<?php

declare(strict_types=1);

$options = preflight_parse_options($argv);
$profile = (string)($options['profile'] ?? 'cic-ci');
$required_csv = (string)($options['required'] ?? '');
$forbidden_csv = (string)($options['forbidden'] ?? '');
$output_path = (string)($options['output'] ?? 'artifacts/secret-preflight.json');

$profiles = array(
	'cic-ci' => array(
		'required' => array('KH_SMMA_TEST_MODE', 'KH_SMMA_GOLDEN_FIXTURE'),
		'forbidden' => array('OPENAI_API_KEY', 'OPENAI_KEY', 'ANTHROPIC_API_KEY', 'ANTHROPIC_KEY', 'DUAL_GPT_API_KEY', 'LLM_API_KEY'),
	),
	'khm-webhooks' => array(
		'required' => array('KH_STRIPE_SECRET_KEY', 'KH_STRIPE_WEBHOOK_SECRET'),
		'forbidden' => array(),
	),
	'ops-runtime' => array(
		'required' => array('KHM_ANON_SALT'),
		'forbidden' => array(),
	),
);

if (!array_key_exists($profile, $profiles)) {
	fwrite(STDERR, "Unknown profile: {$profile}. Available: " . implode(', ', array_keys($profiles)) . "\n");
	exit(2);
}

$required = $profiles[$profile]['required'];
$forbidden = $profiles[$profile]['forbidden'];
if ($required_csv !== '') {
	$required = preflight_csv($required_csv);
}
if ($forbidden_csv !== '') {
	$forbidden = preflight_csv($forbidden_csv);
}

$missing = array();
foreach ($required as $key) {
	$value = getenv($key);
	if (!is_string($value) || trim($value) === '') {
		$missing[] = $key;
	}
}

$forbidden_present = array();
foreach ($forbidden as $key) {
	$value = getenv($key);
	if (is_string($value) && trim($value) !== '') {
		$forbidden_present[] = $key;
	}
}

$summary = array(
	'profile' => $profile,
	'required' => $required,
	'forbidden' => $forbidden,
	'missing_required' => $missing,
	'forbidden_present' => $forbidden_present,
	'result' => (empty($missing) && empty($forbidden_present)) ? 'pass' : 'fail',
	'generated_at' => gmdate('c'),
);

preflight_prepare_dir(dirname($output_path));
$encoded = json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if ($encoded === false) {
	fwrite(STDERR, "Unable to encode preflight summary JSON.\n");
	exit(2);
}
file_put_contents($output_path, $encoded . PHP_EOL);

if (!empty($missing) || !empty($forbidden_present)) {
	fwrite(STDERR, "Secret preflight failed for profile {$profile}.\n");
	if (!empty($missing)) {
		fwrite(STDERR, "Missing required env vars:\n");
		foreach ($missing as $name) {
			fwrite(STDERR, " - {$name}\n");
		}
	}
	if (!empty($forbidden_present)) {
		fwrite(STDERR, "Forbidden env vars set in this profile:\n");
		foreach ($forbidden_present as $name) {
			fwrite(STDERR, " - {$name}\n");
		}
	}
	fwrite(STDERR, "Details written: {$output_path}\n");
	exit(1);
}

fwrite(STDOUT, "Secret preflight passed for profile {$profile}.\n");
fwrite(STDOUT, "Details written: {$output_path}\n");
exit(0);

/**
 * @return array<string,string>
 */
function preflight_parse_options(array $argv): array {
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
 * @return list<string>
 */
function preflight_csv(string $input): array {
	$out = array();
	foreach (explode(',', $input) as $item) {
		$item = trim($item);
		if ($item === '') {
			continue;
		}
		$out[] = $item;
	}
	return array_values(array_unique($out));
}

function preflight_prepare_dir(string $path): void {
	if ($path === '' || $path === '.') {
		return;
	}
	if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
		throw new RuntimeException('Unable to create directory: ' . $path);
	}
}
