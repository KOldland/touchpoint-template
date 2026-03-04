<?php

declare(strict_types=1);

require_once __DIR__ . '/golden_normalize.php';

$options = regen_parse_options($argv);
$input = (string)($options['input'] ?? '');
$fixture_name = (string)($options['fixture-name'] ?? '');
$author = (string)($options['author'] ?? '@ci-qa-team');
$prompt_version = (string)($options['prompt-version'] ?? 'cic-01');
$prompt = (string)($options['prompt'] ?? '');
$prompt_file = (string)($options['prompt-file'] ?? '');
$notes = (string)($options['notes'] ?? 'Regenerated with regenerate_fixture_ui.php');
$redact_keys = (string)($options['redact'] ?? '');
$output_dir = (string)($options['output-dir'] ?? 'tmp/golden-preview');

if ($input === '') {
	$input = regen_prompt('Input JSON path');
}
if ($fixture_name === '') {
	$fixture_name = regen_prompt('Fixture filename (example: generate_awareness_ok.json)');
}
if ($author === '') {
	$author = '@ci-qa-team';
}

if (!preg_match('/^[A-Za-z0-9._-]+\.json$/', $fixture_name)) {
	regen_fail('fixture-name must end with .json and contain only letters, numbers, dot, underscore, dash.');
}
if (!is_file($input)) {
	regen_fail('Input file not found: ' . $input);
}

$raw = file_get_contents($input);
if (!is_string($raw) || $raw === '') {
	regen_fail('Input file is empty or unreadable.');
}

regen_assert_no_secrets($raw);

$decoded = json_decode($raw, true);
if (!is_array($decoded)) {
	regen_fail('Input JSON must decode to object/array.');
}

$redactions = array_values(array_filter(array_map('trim', explode(',', $redact_keys)), static fn(string $value): bool => $value !== ''));
if (!empty($redactions)) {
	$decoded = regen_redact_keys($decoded, $redactions);
}

$normalized = golden_normalize_value($decoded);
$fixture_json = golden_canonical_json($normalized);
$checksum = 'sha256:' . hash('sha256', $fixture_json);

if ($prompt_file !== '' && is_file($prompt_file)) {
	$prompt = (string)file_get_contents($prompt_file);
}
$prompt_hash = 'sha256:' . hash('sha256', trim($prompt));

$stamp = gmdate('Ymd_His');
$base_dir = rtrim($output_dir, '/') . '/' . $stamp . '_' . pathinfo($fixture_name, PATHINFO_FILENAME);
if (!is_dir($base_dir) && !mkdir($base_dir, 0775, true) && !is_dir($base_dir)) {
	regen_fail('Unable to create output directory: ' . $base_dir);
}

$fixture_path = $base_dir . '/' . $fixture_name;
$meta_name = str_replace('.json', '.meta.json', $fixture_name);
$meta_path = $base_dir . '/' . $meta_name;

$meta = array(
	'version' => '1.0.0',
	'prompt_hash' => $prompt_hash,
	'prompt_version' => $prompt_version,
	'created_at' => gmdate('c'),
	'author' => $author,
	'checksum' => $checksum,
	'notes' => $notes,
);

$meta_json = json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if ($meta_json === false) {
	regen_fail('Unable to encode metadata JSON.');
}
$meta_json .= PHP_EOL;

file_put_contents($fixture_path, $fixture_json);
file_put_contents($meta_path, $meta_json);

fwrite(STDOUT, "\nFixture preview written:\n");
fwrite(STDOUT, "- {$fixture_path}\n");
fwrite(STDOUT, "- {$meta_path}\n");

fwrite(STDOUT, "\nNormalization preview (first 30 lines):\n");
$lines = explode("\n", trim($fixture_json));
for ($i = 0; $i < min(30, count($lines)); $i++) {
	fwrite(STDOUT, sprintf('%3d | %s', $i + 1, $lines[$i]) . "\n");
}

fwrite(STDOUT, "\nPR snippet:\n");
fwrite(STDOUT, "Fixture: app/public/wp-content/plugins/kh-smma/tests/fixtures/golden/{$fixture_name}\n");
fwrite(STDOUT, "Owner: {$author}\n");
fwrite(STDOUT, "Reason for change: <fill in>\n");
fwrite(STDOUT, "Prompt version: {$prompt_version}\n");
fwrite(STDOUT, "Prompt hash: {$prompt_hash}\n");
fwrite(STDOUT, "Checksum: {$checksum}\n");
fwrite(STDOUT, "Required label: golden-owner-approved\n");
fwrite(STDOUT, "\nThis tool does not commit or copy files into fixture directories.\n");

/**
 * @return array<string,string>
 */
function regen_parse_options(array $argv): array {
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

function regen_prompt(string $label): string {
	fwrite(STDOUT, $label . ': ');
	$line = fgets(STDIN);
	return is_string($line) ? trim($line) : '';
}

function regen_fail(string $message): void {
	fwrite(STDERR, 'ERROR: ' . $message . "\n");
	exit(1);
}

function regen_assert_no_secrets(string $content): void {
	$patterns = array(
		'/sk_(live|test)_[A-Za-z0-9]+/',
		'/whsec_[A-Za-z0-9]+/',
		'/-----BEGIN (RSA|EC|OPENSSH|PRIVATE) KEY-----/',
		'/\bAKIA[0-9A-Z]{16}\b/',
		'/\bBearer\s+[A-Za-z0-9\-._~+\/=]+/i',
	);
	foreach ($patterns as $pattern) {
		if (preg_match($pattern, $content) === 1) {
			regen_fail('Input appears to contain secret material. Scrub and retry.');
		}
	}
}

/**
 * @param mixed $value
 * @param list<string> $redactions
 * @return mixed
 */
function regen_redact_keys($value, array $redactions) {
	if (!is_array($value)) {
		return $value;
	}

	$out = array();
	foreach ($value as $key => $item) {
		$key_string = is_string($key) ? strtolower($key) : (string)$key;
		$should_redact = false;
		foreach ($redactions as $pattern) {
			if ($pattern !== '' && str_contains($key_string, strtolower($pattern))) {
				$should_redact = true;
				break;
			}
		}
		if ($should_redact) {
			$out[$key] = '{{REDACTED}}';
			continue;
		}
		$out[$key] = regen_redact_keys($item, $redactions);
	}

	return $out;
}
