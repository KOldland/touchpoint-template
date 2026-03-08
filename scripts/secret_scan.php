<?php

declare(strict_types=1);

const SECRET_SCAN_VERSION = 'cic-07';

$options = secret_scan_parse_options($argv);
$strict = isset($options['strict']);
$changed_only = isset($options['changed']);
$fix_mode = isset($options['fix']);
$base = (string)($options['base'] ?? (getenv('GITHUB_BASE_REF') ? 'origin/' . getenv('GITHUB_BASE_REF') : 'origin/main'));
$head = (string)($options['head'] ?? 'HEAD');
$paths_option = (string)($options['paths'] ?? '');
$max_file_bytes = max(1024, (int)($options['max-file-bytes'] ?? 1024 * 1024));
$output_path = (string)($options['output'] ?? 'artifacts/secret-scan-findings.json');
$telemetry_path = (string)($options['telemetry'] ?? 'artifacts/secret-scan-telemetry.json');
$run_id = (string)($options['run-id'] ?? getenv('GITHUB_RUN_ID') ?: 'local');
$quiet = isset($options['quiet']);

$repo_root = realpath(__DIR__ . '/..');
if (!is_string($repo_root) || $repo_root === '') {
	fwrite(STDERR, "Unable to resolve repository root.\n");
	exit(2);
}

$scan_paths = secret_scan_collect_paths($repo_root, $paths_option, $changed_only, $base, $head, $max_file_bytes);
$patterns = secret_scan_patterns();
$findings = array();

foreach ($scan_paths as $absolute_path) {
	$relative_path = secret_scan_relpath($repo_root, $absolute_path);
	$content = file_get_contents($absolute_path);
	if (!is_string($content) || $content === '') {
		continue;
	}

	$lines = preg_split('/\r\n|\r|\n/', $content);
	if (!is_array($lines)) {
		continue;
	}

	foreach ($lines as $line_index => $line) {
		$line_number = $line_index + 1;
		foreach ($patterns as $pattern) {
			if (!secret_scan_pattern_applies($pattern, $relative_path, $strict)) {
				continue;
			}
			$regex = (string)$pattern['regex'];
			$match_count = preg_match_all($regex, $line, $matches, PREG_SET_ORDER);
			if ($match_count === false || $match_count < 1) {
				continue;
			}

			foreach ($matches as $match) {
				$token = (string)($match[0] ?? '');
				if ($token === '') {
					continue;
				}

				$findings[] = array(
					'pattern' => (string)$pattern['id'],
					'severity' => (string)$pattern['severity'],
					'file' => $relative_path,
					'line' => $line_number,
					'match_masked' => secret_scan_mask_token($token),
					'hint' => (string)$pattern['hint'],
				);
			}
		}

		if ($strict) {
			$entropy_hits = secret_scan_entropy_tokens($line, $relative_path);
			foreach ($entropy_hits as $token) {
				$findings[] = array(
					'pattern' => 'high_entropy_token',
					'severity' => 'medium',
					'file' => $relative_path,
					'line' => $line_number,
					'match_masked' => secret_scan_mask_token($token),
					'hint' => 'High-entropy token detected. Move to env/vault or replace with placeholder.',
				);
			}
		}
	}
}

$findings = secret_scan_dedupe_findings($findings);
secret_scan_prepare_dir(dirname($output_path));
secret_scan_prepare_dir(dirname($telemetry_path));

$payload = array(
	'version' => SECRET_SCAN_VERSION,
	'scan_mode' => $changed_only ? 'changed' : 'full',
	'strict' => $strict,
	'base' => $base,
	'head' => $head,
	'scanned_file_count' => count($scan_paths),
	'finding_count' => count($findings),
	'findings' => $findings,
		'generated_at' => gmdate('c'),
		'scope' => 'first-party',
);
secret_scan_write_json($output_path, $payload);

$event_name = empty($findings) ? 'cic.secret_scan.passed' : 'cic.secret_scan.failed';
$telemetry = array(
	array(
		'event' => $event_name,
		'run_id' => $run_id,
		'scan_mode' => $payload['scan_mode'],
		'strict' => $strict,
		'finding_count' => count($findings),
		'files' => array_values(array_slice(array_unique(array_map(static fn(array $f): string => (string)$f['file'], $findings)), 0, 200)),
		'timestamp' => gmdate('c'),
	),
);
secret_scan_write_json($telemetry_path, $telemetry);

if (!empty($findings)) {
	if (!$quiet) {
		fwrite(STDERR, "Secret scan failed. Findings:\n");
		$display_limit = 200;
		$display_count = min($display_limit, count($findings));
		for ($i = 0; $i < $display_count; $i++) {
			$finding = $findings[$i];
			fwrite(STDERR, sprintf(
				" - [%s] %s:%d token=%s\\n   hint: %s\\n",
				$finding['pattern'],
				$finding['file'],
				$finding['line'],
				$finding['match_masked'],
				$finding['hint']
			));
		}
		if (count($findings) > $display_limit) {
			fwrite(STDERR, sprintf(" ... %d additional finding(s) omitted from console output. See JSON artifact.\\n", count($findings) - $display_limit));
		}

		if ($fix_mode) {
			fwrite(STDERR, "\nRemediation (dry-run suggestions):\n");
			fwrite(STDERR, "1. Replace hardcoded values with getenv()/wp-config constants.\n");
			fwrite(STDERR, "2. For Stripe secrets use KH_STRIPE_SECRET_KEY / KH_STRIPE_WEBHOOK_SECRET.\n");
			fwrite(STDERR, "3. For LLM credentials use vault/GH secrets only, never fixtures/docs.\n");
			fwrite(STDERR, "4. Re-run: php scripts/secret_scan.php --strict --changed --fix\n");
		}

		fwrite(STDERR, "\nArtifacts:\n - {$output_path}\n - {$telemetry_path}\n");
	}
	exit(1);
}

if (!$quiet) {
	fwrite(STDOUT, "Secret scan passed.\n");
	fwrite(STDOUT, "Scanned files: " . count($scan_paths) . "\n");
	fwrite(STDOUT, "Artifacts:\n - {$output_path}\n - {$telemetry_path}\n");
}
exit(0);

/**
 * @return array<string,string>
 */
function secret_scan_parse_options(array $argv): array {
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
 * @return list<array{id:string,regex:string,severity:string,hint:string,strict_only:bool,fixtures_only:bool}>
 */
function secret_scan_patterns(): array {
	return array(
		array(
			'id' => 'stripe_secret',
			'regex' => '/\bsk_(live|test)_[A-Za-z0-9]{16,}\b/',
			'severity' => 'critical',
			'hint' => 'Move Stripe API keys to KH_STRIPE_SECRET_KEY in vault/GH secrets.',
			'strict_only' => false,
			'fixtures_only' => false,
		),
		array(
			'id' => 'stripe_webhook_secret',
			'regex' => '/\bwhsec_[A-Za-z0-9]{16,}\b/',
			'severity' => 'critical',
			'hint' => 'Move webhook signing secret to KH_STRIPE_WEBHOOK_SECRET.',
			'strict_only' => false,
			'fixtures_only' => false,
		),
		array(
			'id' => 'openai_key',
			'regex' => '/\bsk-(proj-)?[A-Za-z0-9_-]{20,}\b/',
			'severity' => 'critical',
			'hint' => 'Do not commit OpenAI keys. Use vault or CI secrets.',
			'strict_only' => false,
			'fixtures_only' => false,
		),
		array(
			'id' => 'aws_access_key',
			'regex' => '/\b(A3T|AKIA|ASIA|AGPA|AIDA|AROA|AIPA)[A-Z0-9]{16}\b/',
			'severity' => 'critical',
			'hint' => 'Move AWS credentials to vault and rotate leaked keys immediately.',
			'strict_only' => false,
			'fixtures_only' => false,
		),
		array(
			'id' => 'github_token',
			'regex' => '/\bgh[pousr]_[A-Za-z0-9]{20,255}\b/',
			'severity' => 'critical',
			'hint' => 'Never commit GitHub tokens. Revoke and rotate immediately.',
			'strict_only' => false,
			'fixtures_only' => false,
		),
		array(
			'id' => 'google_api_key',
			'regex' => '/\bAIza[0-9A-Za-z\-_]{35}\b/',
			'severity' => 'critical',
			'hint' => 'Move Google API key to secret manager.',
			'strict_only' => false,
			'fixtures_only' => false,
		),
		array(
			'id' => 'slack_token',
			'regex' => '/\bxox[baprs]-[A-Za-z0-9-]{10,}\b/',
			'severity' => 'critical',
			'hint' => 'Slack token detected. Revoke and rotate.',
			'strict_only' => false,
			'fixtures_only' => false,
		),
		array(
			'id' => 'private_key_block',
			'regex' => '/-----BEGIN (RSA|EC|OPENSSH|PRIVATE|PGP) KEY-----/',
			'severity' => 'critical',
			'hint' => 'Private key material must never be committed.',
			'strict_only' => false,
			'fixtures_only' => false,
		),
		array(
			'id' => 'bearer_token',
			'regex' => '~\bBearer\s+[A-Za-z0-9\-._\~+\/=]{20,}\b~i',
			'severity' => 'high',
			'hint' => 'Do not store bearer tokens in code/docs.',
			'strict_only' => false,
			'fixtures_only' => false,
		),
		array(
			'id' => 'pii_email_in_fixture',
			'regex' => '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i',
			'severity' => 'medium',
			'hint' => 'Fixtures/docs should not include real email addresses. Use placeholders.',
			'strict_only' => false,
			'fixtures_only' => true,
		),
		array(
			'id' => 'pii_ssn_like',
			'regex' => '/\b\d{3}-\d{2}-\d{4}\b/',
			'severity' => 'high',
			'hint' => 'Potential SSN-like value detected; replace with placeholder.',
			'strict_only' => true,
			'fixtures_only' => false,
		),
	);
}

/**
 * @param array{id:string,regex:string,severity:string,hint:string,strict_only:bool,fixtures_only:bool} $pattern
 */
function secret_scan_pattern_applies(array $pattern, string $relative_path, bool $strict): bool {
	if (($pattern['strict_only'] ?? false) && !$strict) {
		return false;
	}

	if ($pattern['fixtures_only'] ?? false) {
		if (!str_contains($relative_path, '/fixtures/') && !str_contains($relative_path, 'fixtures/')) {
			return false;
		}
	}

	return true;
}

/**
 * @return list<string>
 */
function secret_scan_collect_paths(string $repo_root, string $paths_option, bool $changed_only, string $base, string $head, int $max_file_bytes): array {
	$targets = array();

	if ($paths_option !== '') {
		foreach (explode(',', $paths_option) as $raw_path) {
			$clean = trim($raw_path);
			if ($clean === '') {
				continue;
			}
			$absolute = str_starts_with($clean, '/') ? $clean : $repo_root . '/' . $clean;
			$targets[] = $absolute;
		}
	} elseif ($changed_only) {
		$cmd = 'git diff --name-only ' . escapeshellarg($base . '...' . $head);
		$output = shell_exec($cmd);
		$lines = is_string($output) ? preg_split('/\r?\n/', trim($output)) : array();
		if (is_array($lines)) {
			foreach ($lines as $line) {
				$line = trim((string)$line);
				if ($line === '') {
					continue;
				}
				$targets[] = $repo_root . '/' . $line;
			}
		}
	} else {
		$targets = array(
			$repo_root . '/scripts',
			$repo_root . '/tools',
			$repo_root . '/docs/ci',
			$repo_root . '/docs/contracts',
			$repo_root . '/ci',
			$repo_root . '/ops',
			$repo_root . '/.github/workflows',
			$repo_root . '/app/public/wp-content/plugins/kh-smma',
			$repo_root . '/app/public/wp-content/plugins/khm-plugin',
		);
	}

	$files = array();
	foreach ($targets as $target) {
		if (is_file($target)) {
			if (!secret_scan_should_skip_file($repo_root, $target, $max_file_bytes)) {
				$files[] = realpath($target) ?: $target;
			}
			continue;
		}
		if (!is_dir($target)) {
			continue;
		}

		$iter = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($target, FilesystemIterator::SKIP_DOTS)
		);
		foreach ($iter as $file_info) {
			if (!$file_info->isFile()) {
				continue;
			}
			$path = (string)$file_info->getPathname();
			if (secret_scan_should_skip_file($repo_root, $path, $max_file_bytes)) {
				continue;
			}
			$files[] = $path;
		}
	}

	$files = array_values(array_unique(array_map(static fn(string $p): string => str_replace('\\', '/', (string)(realpath($p) ?: $p)), $files)));
	sort($files);
	return $files;
}

function secret_scan_should_skip_file(string $repo_root, string $absolute_path, int $max_file_bytes): bool {
	$relative = secret_scan_relpath($repo_root, $absolute_path);
	$skip_parts = array(
		'/.git/',
		'/vendor/',
		'/node_modules/',
		'/app/public/wp-admin/',
		'/app/public/wp-includes/',
		'/app/public/wp-content/uploads/',
		'/tmp/',
		'/artifacts/',
		'/coverage/',
	);
	foreach ($skip_parts as $part) {
		if (str_contains('/' . $relative, $part)) {
			return true;
		}
	}

	$name = basename($absolute_path);
	$skip_names = array('composer.lock', 'package-lock.json', 'yarn.lock', '.DS_Store');
	if (in_array($name, $skip_names, true)) {
		return true;
	}

	$size = @filesize($absolute_path);
	if (is_int($size) && $size > $max_file_bytes) {
		return true;
	}

	$sample = @file_get_contents($absolute_path, false, null, 0, 4096);
	if (!is_string($sample)) {
		return true;
	}
	if (str_contains($sample, "\0")) {
		return true;
	}

	return false;
}

function secret_scan_relpath(string $root, string $absolute_path): string {
	$path = str_replace('\\', '/', $absolute_path);
	$normalized_root = str_replace('\\', '/', $root);
	if (str_starts_with($path, $normalized_root . '/')) {
		return substr($path, strlen($normalized_root) + 1);
	}
	return ltrim($path, '/');
}

function secret_scan_mask_token(string $token): string {
	$len = strlen($token);
	if ($len <= 6) {
		return str_repeat('*', $len);
	}
	return substr($token, 0, 4) . str_repeat('*', max(4, $len - 6)) . substr($token, -2);
}

/**
 * @param list<array<string,mixed>> $findings
 * @return list<array<string,mixed>>
 */
function secret_scan_dedupe_findings(array $findings): array {
	$seen = array();
	$deduped = array();
	foreach ($findings as $finding) {
		$key = implode('|', array(
			(string)($finding['pattern'] ?? ''),
			(string)($finding['file'] ?? ''),
			(string)($finding['line'] ?? ''),
			(string)($finding['match_masked'] ?? ''),
		));
		if (isset($seen[$key])) {
			continue;
		}
		$seen[$key] = true;
		$deduped[] = $finding;
	}
	return $deduped;
}

/**
 * @return list<string>
 */
function secret_scan_entropy_tokens(string $line, string $relative_path): array {
	$hits = array();
	$ext = strtolower((string)pathinfo($relative_path, PATHINFO_EXTENSION));
	$allowed_ext = array('php', 'sh', 'env', 'yml', 'yaml', 'json', 'ini', 'conf');
	if (!in_array($ext, $allowed_ext, true)) {
		return $hits;
	}

	// High-entropy heuristics are only useful on secret-like assignment lines.
	if (preg_match('/(secret|token|api[_-]?key|password|passwd|authorization|bearer|webhook|stripe|openai|anthropic|vault)/i', $line) !== 1) {
		return $hits;
	}
	if (!str_contains($line, '=') && !str_contains($line, ':')) {
		return $hits;
	}
	if (str_contains($line, '${{ secrets.')) {
		return $hits;
	}

	$match_count = preg_match_all('/\b[A-Za-z0-9+_.=-]{28,}\b/', $line, $matches);
	if ($match_count === false || $match_count < 1) {
		return $hits;
	}

	$allow_substrings = array(
		'example',
		'placeholder',
		'{{',
		'}}',
		'aaaaaaaa',
		'000000',
		'111111',
		'sha256:',
		'prompt_hash',
		'checksum',
		'copyright',
		'generated',
		'origin/main',
		'github',
		'workflow',
		'artifacts',
		'for_test',
		'test_secret',
		'your_',
		'secrets.',
	);

	foreach ($matches[0] as $token) {
		$lower = strtolower($token);
		$skip = false;
		foreach ($allow_substrings as $allowed) {
			if (str_contains($lower, $allowed)) {
				$skip = true;
				break;
			}
		}
		if ($skip) {
			continue;
		}
		if (secret_scan_shannon_entropy($token) < 4.1) {
			continue;
		}
		if (!secret_scan_has_mixed_charset($token)) {
			continue;
		}
		$hits[] = $token;
		if (count($hits) >= 2) {
			break;
		}
	}

	return $hits;
}

function secret_scan_has_mixed_charset(string $token): bool {
	$has_digit = preg_match('/[0-9]/', $token) === 1;
	$has_letter = preg_match('/[A-Za-z]/', $token) === 1;
	return $has_digit && $has_letter;
}

function secret_scan_shannon_entropy(string $value): float {
	$length = strlen($value);
	if ($length === 0) {
		return 0.0;
	}

	$freq = array_count_values(str_split($value));
	$entropy = 0.0;
	foreach ($freq as $count) {
		$p = $count / $length;
		$entropy -= $p * log($p, 2);
	}
	return $entropy;
}

/**
 * @param array<string,mixed> $payload
 */
function secret_scan_write_json(string $path, array $payload): void {
	$json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
	if ($json === false) {
		throw new RuntimeException('Unable to encode JSON output: ' . $path);
	}
	file_put_contents($path, $json . PHP_EOL);
}

function secret_scan_prepare_dir(string $path): void {
	if ($path === '' || $path === '.') {
		return;
	}
	if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
		throw new RuntimeException('Unable to create directory: ' . $path);
	}
}
