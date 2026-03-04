<?php

declare(strict_types=1);

$options = ff_parse_options($argv);
$flag = (string)($options['flag'] ?? '');
$actor = (string)($options['actor'] ?? 'release-bot');
$env = (string)($options['env'] ?? 'local');
$artifact_dir = (string)($options['artifact-dir'] ?? 'artifacts/release/flags');
$state_file = (string)($options['state-file'] ?? $artifact_dir . '/feature-flags-state.json');
$pct_option = (string)($options['pct-option'] ?? ($flag !== '' ? $flag . '_rollout_pct' : 'khm_release_rollout_pct'));
$wp_path = (string)($options['wp-path'] ?? '');
$dry_run = isset($options['dry-run']);

if ($flag === '') {
	fwrite(STDERR, "--flag is required.\n");
	exit(2);
}

$enabled = null;
if (array_key_exists('enabled', $options)) {
	$enabled = ff_parse_bool((string)$options['enabled']);
	if ($enabled === null) {
		fwrite(STDERR, "--enabled must be 0|1|true|false\n");
		exit(2);
	}
}

$pct = null;
if (array_key_exists('pct', $options)) {
	if (!is_numeric($options['pct'])) {
		fwrite(STDERR, "--pct must be numeric 0..100\n");
		exit(2);
	}
	$pct = (int)$options['pct'];
	if ($pct < 0 || $pct > 100) {
		fwrite(STDERR, "--pct out of range (0..100)\n");
		exit(2);
	}
}

ff_prepare_dir($artifact_dir);
ff_prepare_dir(dirname($state_file));

$use_wp = ff_can_use_wp($wp_path);
$current = ff_read_current_state($use_wp, $wp_path, $state_file, $flag, $pct_option);
$desired_flag = $enabled ?? $current['flag_enabled'];
$desired_pct = $pct ?? $current['rollout_pct'];

$changed = ($desired_flag !== $current['flag_enabled']) || ($desired_pct !== $current['rollout_pct']);
$result = 'success';
$message = 'no changes';

if ($changed) {
	if ($dry_run) {
		$message = 'dry-run: change preview only';
	} else {
		if ($use_wp) {
			ff_wp_set_option($wp_path, $flag, $desired_flag ? '1' : '0');
			ff_wp_set_option($wp_path, $pct_option, (string)$desired_pct);
		} else {
			$state = ff_read_state_file($state_file);
			$state[$flag] = $desired_flag ? '1' : '0';
			$state[$pct_option] = (string)$desired_pct;
			ff_write_json($state_file, $state);
		}
		$message = 'state updated';
	}
}

$summary = array(
	'run_id' => getenv('GITHUB_RUN_ID') ?: 'local',
	'actor' => $actor,
	'env' => $env,
	'flag' => $flag,
	'pct_option' => $pct_option,
	'current' => $current,
	'desired' => array('flag_enabled' => $desired_flag, 'rollout_pct' => $desired_pct),
	'changed' => $changed,
	'dry_run' => $dry_run,
	'mode' => $use_wp ? 'wp-cli' : 'state-file',
	'result' => $result,
	'message' => $message,
	'timestamp' => gmdate('c'),
);

$summary_path = $artifact_dir . '/feature-flag-toggle-' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $flag) . '.json';
ff_write_json($summary_path, $summary);

$audit_path = $artifact_dir . '/feature-flag-audit.jsonl';
$audit_event = array(
	'event' => 'cic.feature_flag.toggle',
	'actor' => $actor,
	'flag' => $flag,
	'pct_option' => $pct_option,
	'dry_run' => $dry_run,
	'changed' => $changed,
	'desired_flag' => $desired_flag,
	'desired_pct' => $desired_pct,
	'mode' => $summary['mode'],
	'timestamp' => gmdate('c'),
);
file_put_contents($audit_path, json_encode($audit_event, JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND);

fwrite(STDOUT, "feature flag toggle: {$flag}, changed=" . ($changed ? 'true' : 'false') . ", mode={$summary['mode']}\n");
fwrite(STDOUT, "summary: {$summary_path}\n");

exit(0);

/**
 * @return array<string,string>
 */
function ff_parse_options(array $argv): array {
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

function ff_parse_bool(string $value): ?bool {
	$normalized = strtolower(trim($value));
	if (in_array($normalized, array('1', 'true', 'yes', 'on'), true)) {
		return true;
	}
	if (in_array($normalized, array('0', 'false', 'no', 'off'), true)) {
		return false;
	}
	return null;
}

function ff_can_use_wp(string $wp_path): bool {
	$wp = trim((string)shell_exec('command -v wp 2>/dev/null'));
	if ($wp === '') {
		return false;
	}
	if ($wp_path !== '' && !is_dir($wp_path)) {
		return false;
	}
	return true;
}

/**
 * @return array{flag_enabled:bool,rollout_pct:int}
 */
function ff_read_current_state(bool $use_wp, string $wp_path, string $state_file, string $flag, string $pct_option): array {
	if ($use_wp) {
		$flag_raw = ff_wp_get_option($wp_path, $flag);
		$pct_raw = ff_wp_get_option($wp_path, $pct_option);
		return array(
			'flag_enabled' => in_array(strtolower(trim($flag_raw)), array('1', 'true', 'yes', 'on'), true),
			'rollout_pct' => is_numeric($pct_raw) ? max(0, min(100, (int)$pct_raw)) : 0,
		);
	}

	$state = ff_read_state_file($state_file);
	$flag_raw = (string)($state[$flag] ?? '0');
	$pct_raw = (string)($state[$pct_option] ?? '0');
	return array(
		'flag_enabled' => in_array(strtolower(trim($flag_raw)), array('1', 'true', 'yes', 'on'), true),
		'rollout_pct' => is_numeric($pct_raw) ? max(0, min(100, (int)$pct_raw)) : 0,
	);
}

function ff_wp_get_option(string $wp_path, string $option): string {
	$path_arg = $wp_path !== '' ? ' --path=' . escapeshellarg($wp_path) : '';
	$cmd = 'wp' . $path_arg . ' option get ' . escapeshellarg($option) . ' --format=plaintext 2>/dev/null';
	$output = shell_exec($cmd);
	return is_string($output) ? trim($output) : '';
}

function ff_wp_set_option(string $wp_path, string $option, string $value): void {
	$path_arg = $wp_path !== '' ? ' --path=' . escapeshellarg($wp_path) : '';
	$cmd = 'wp' . $path_arg . ' option set ' . escapeshellarg($option) . ' ' . escapeshellarg($value) . ' >/dev/null';
	exec($cmd, $out, $exit);
	if ($exit !== 0) {
		throw new RuntimeException('Failed to set WP option: ' . $option);
	}
}

/**
 * @return array<string,string>
 */
function ff_read_state_file(string $path): array {
	if (!is_file($path)) {
		return array();
	}
	$raw = file_get_contents($path);
	if (!is_string($raw) || $raw === '') {
		return array();
	}
	$decoded = json_decode($raw, true);
	if (!is_array($decoded)) {
		return array();
	}
	$out = array();
	foreach ($decoded as $k => $v) {
		if (is_string($k) && (is_string($v) || is_int($v) || is_bool($v))) {
			$out[$k] = (string)$v;
		}
	}
	return $out;
}

/**
 * @param array<string,mixed> $payload
 */
function ff_write_json(string $path, array $payload): void {
	$json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
	if ($json === false) {
		throw new RuntimeException('Unable to encode JSON: ' . $path);
	}
	file_put_contents($path, $json . PHP_EOL);
}

function ff_prepare_dir(string $path): void {
	if ($path === '' || $path === '.') {
		return;
	}
	if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
		throw new RuntimeException('Unable to create directory: ' . $path);
	}
}
