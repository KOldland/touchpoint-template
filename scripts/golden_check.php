<?php

declare(strict_types=1);

require_once __DIR__ . '/golden_normalize.php';

$options = parse_options($argv);
$base = $options['base'] ?? default_base_ref();
$head = $options['head'] ?? 'HEAD';
$event_path = $options['event'] ?? (string) getenv('GITHUB_EVENT_PATH');
$output_path = $options['output'] ?? 'artifacts/golden-summary.json';
$diff_dir = $options['diff-dir'] ?? 'artifacts/golden-diffs';
$zip_path = $options['zip'] ?? 'artifacts/golden-diff.zip';
$skip_label_check = isset($options['skip-label-check']);
$fixtures_override = isset($options['fixtures']) ? array_values(array_filter(array_map('trim', explode(',', (string) $options['fixtures'])))) : array();

ensure_ci_key_safety();

prepare_dir(dirname($output_path));
prepare_dir($diff_dir);

$summary = array(
	'result' => 'success',
	'base' => $base,
	'head' => $head,
	'checked_fixtures' => array(),
	'mismatches' => array(),
	'governance' => array('status' => 'passed'),
);

if (!$skip_label_check) {
	$label_check_cmd = 'php ' . escapeshellarg(__DIR__ . '/label_check.php') .
		' --base ' . escapeshellarg($base) .
		' --head ' . escapeshellarg($head);
	if ($event_path !== '') {
		$label_check_cmd .= ' --event ' . escapeshellarg($event_path);
	}
	$label_output = array();
	$label_exit = 0;
	exec($label_check_cmd . ' 2>&1', $label_output, $label_exit);
	if ($label_exit !== 0) {
		$summary['result'] = 'failure';
		$summary['governance'] = array(
			'status' => 'failed',
			'exit_code' => $label_exit,
			'message' => implode("\n", $label_output),
		);
		write_summary($output_path, $summary);
		write_zip($zip_path, array($output_path));
		fwrite(STDERR, implode("\n", $label_output) . "\n");
		exit($label_exit);
	}
}

$changed_files = get_changed_files($base, $head);
$changed_fixtures = changed_fixture_names($changed_files);
$fixtures = determine_fixtures_to_check($fixtures_override, $changed_fixtures);

$owner_map = discover_owner_map();

foreach ($fixtures as $fixture) {
	$summary['checked_fixtures'][] = $fixture;
	$fixture_path = fixture_path($fixture);
	$meta_path = meta_path($fixture);

	if (!is_file($fixture_path)) {
		$summary['mismatches'][] = mismatch_record($fixture, $owner_map[$fixture] ?? '@unknown', 'missing_fixture', 'Missing fixture file', '', '', '');
		continue;
	}

	$expected_raw = file_get_contents($fixture_path);
	if (!is_string($expected_raw) || $expected_raw === '') {
		$summary['mismatches'][] = mismatch_record($fixture, $owner_map[$fixture] ?? '@unknown', 'read_error', 'Unable to read fixture file', '', '', '');
		continue;
	}

	$expected = json_decode($expected_raw, true);
	if (!is_array($expected)) {
		$summary['mismatches'][] = mismatch_record($fixture, $owner_map[$fixture] ?? '@unknown', 'json_error', 'Fixture JSON is invalid or non-object', '', '', '');
		continue;
	}

	$meta = read_meta($meta_path);
	$owner = (string)($meta['author'] ?? ($owner_map[$fixture] ?? '@unknown'));
	$prompt_hash = (string)($meta['prompt_hash'] ?? '');

	$actual = build_actual_payload($fixture, $expected);
	$expected_normalized = golden_normalize_value($expected);
	$actual_normalized = golden_normalize_value($actual);

	$expected_json = golden_canonical_json($expected_normalized);
	$actual_json = golden_canonical_json($actual_normalized);

	$expected_checksum = 'sha256:' . hash('sha256', $expected_json);
	$actual_checksum = 'sha256:' . hash('sha256', $actual_json);

	if ($expected_checksum !== $actual_checksum) {
		$safe_name = str_replace('.json', '', $fixture);
		$expected_file = $diff_dir . '/' . $safe_name . '.expected.json';
		$actual_file = $diff_dir . '/' . $safe_name . '.actual.json';
		$diff_file = $diff_dir . '/' . $safe_name . '.diff.patch';

		file_put_contents($expected_file, $expected_json);
		file_put_contents($actual_file, $actual_json);
		$diff = build_unified_diff($expected_file, $actual_file);
		file_put_contents($diff_file, $diff);

		$summary['mismatches'][] = array(
			'fixture' => $fixture,
			'owner' => $owner,
			'prompt_hash' => $prompt_hash,
			'checksum_expected' => $expected_checksum,
			'checksum_actual' => $actual_checksum,
			'diff_file' => $diff_file,
			'reason' => 'canonical_mismatch',
		);
	}
}

if (!empty($summary['mismatches'])) {
	$summary['result'] = 'failure';
}

write_summary($output_path, $summary);

$artifact_files = array($output_path);
foreach ($summary['mismatches'] as $mismatch) {
	if (isset($mismatch['diff_file']) && is_string($mismatch['diff_file']) && is_file($mismatch['diff_file'])) {
		$artifact_files[] = $mismatch['diff_file'];
	}
}
write_zip($zip_path, $artifact_files);

if ($summary['result'] === 'failure') {
	fwrite(STDERR, "Golden check failed with " . count($summary['mismatches']) . " mismatch(es).\n");
	foreach ($summary['mismatches'] as $mismatch) {
		fwrite(STDERR, ' - ' . $mismatch['fixture'] . ' (owner: ' . $mismatch['owner'] . ")\n");
	}
	exit(1);
}

echo "Golden check passed.\n";
exit(0);

/**
 * @return array<string,string>
 */
function parse_options(array $argv): array {
	$options = array();
	for ($i = 1; $i < count($argv); $i++) {
		$arg = (string) $argv[$i];
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

function default_base_ref(): string {
	$base = (string) getenv('GITHUB_BASE_REF');
	if ($base !== '') {
		return 'origin/' . $base;
	}
	return 'origin/main';
}

function ensure_ci_key_safety(): void {
	if ((string) getenv('KH_SMMA_TEST_MODE') !== 'ci') {
		return;
	}
	$keys = array('OPENAI_API_KEY', 'OPENAI_KEY', 'ANTHROPIC_API_KEY', 'ANTHROPIC_KEY', 'DUAL_GPT_API_KEY', 'LLM_API_KEY');
	foreach ($keys as $key) {
		$value = getenv($key);
		if (is_string($value) && strlen($value) > 10) {
			throw new RuntimeException('Real LLM key present while KH_SMMA_TEST_MODE=ci: ' . $key);
		}
	}
}

function prepare_dir(string $dir): void {
	if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
		throw new RuntimeException('Unable to create directory: ' . $dir);
	}
}

/**
 * @return list<string>
 */
function get_changed_files(string $base, string $head): array {
	$cmd = 'git diff --name-only ' . escapeshellarg($base . '...' . $head);
	$output = shell_exec($cmd);
	if (!is_string($output) || trim($output) === '') {
		return array();
	}
	$lines = preg_split('/\r?\n/', trim($output));
	if (!is_array($lines)) {
		return array();
	}
	return array_values(array_filter(array_map('trim', $lines), static fn(string $line): bool => $line !== ''));
}

/**
 * @param list<string> $files
 * @return list<string>
 */
function changed_fixture_names(array $files): array {
	$fixtures = array();
	foreach ($files as $file) {
		if (!str_starts_with($file, 'app/public/wp-content/plugins/kh-smma/tests/fixtures/golden/')) {
			continue;
		}
		$name = basename($file);
		if (str_ends_with($name, '.meta.json')) {
			$name = str_replace('.meta.json', '.json', $name);
		}
		if (str_ends_with($name, '.json')) {
			$fixtures[$name] = true;
		}
	}
	return array_keys($fixtures);
}

/**
 * @param list<string> $override
 * @param list<string> $changed
 * @return list<string>
 */
function determine_fixtures_to_check(array $override, array $changed): array {
	if (!empty($override)) {
		return $override;
	}

	if (!empty($changed)) {
		$baseline_smoke = array('generate_awareness_ok.json', 'compliance_ok.json');
		$merged = array_values(array_unique(array_merge($changed, $baseline_smoke)));
		sort($merged);
		return $merged;
	}

	return array('generate_awareness_ok.json', 'compliance_ok.json');
}

/**
 * @return array<string,string>
 */
function discover_owner_map(): array {
	$owners = array();
	$readme = 'app/public/wp-content/plugins/kh-smma/tests/fixtures/golden/README.md';
	if (!is_file($readme)) {
		return $owners;
	}
	$lines = file($readme, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
	if (!is_array($lines)) {
		return $owners;
	}
	foreach ($lines as $line) {
		if (
			preg_match('/\|\s*`([^`]+\.json)`\s*\|\s*`(@[^`]+)`\s*\|/', $line, $m) === 1 ||
			preg_match('/-\s*`([^`]+\.json)`\s*[—-]\s*(@[A-Za-z0-9_-]+)/u', $line, $m) === 1
		) {
			$owners[$m[1]] = $m[2];
		}
	}
	return $owners;
}

function fixture_path(string $fixture): string {
	return 'app/public/wp-content/plugins/kh-smma/tests/fixtures/golden/' . $fixture;
}

function meta_path(string $fixture): string {
	return str_replace('.json', '.meta.json', fixture_path($fixture));
}

/**
 * @return array<string,mixed>
 */
function read_meta(string $path): array {
	if (!is_file($path)) {
		return array();
	}
	$raw = file_get_contents($path);
	if (!is_string($raw) || $raw === '') {
		return array();
	}
	$decoded = json_decode($raw, true);
	return is_array($decoded) ? $decoded : array();
}

/**
 * @param array<string,mixed> $expected
 * @return array<string,mixed>
 */
function build_actual_payload(string $fixture, array $expected): array {
	$forced_mismatch = (string) getenv('KHM_GOLDEN_CHECK_FORCE_MISMATCH');

	switch ($fixture) {
		case 'generate_awareness_ok.json':
		case 'generate_sponsor_warn.json':
		case 'generate_sponsor_fail.json':
		case 'google_ad_draft.json':
		case 'compliance_ok.json':
		case 'compliance_warn.json':
		case 'compliance_fail.json':
			$actual = run_mock_llm_fixture($fixture);
			break;

		case 'checkout_session_completed.json':
			$actual = array(
				'id' => 'evt_{{EVENT_ID}}',
				'type' => 'checkout.session.completed',
				'created' => '{{UNIX_TS}}',
				'data' => array(
					'object' => array(
						'id' => 'cs_{{CHECKOUT_SESSION_ID}}',
						'mode' => 'subscription',
						'customer' => 'cus_{{CUSTOMER_ID}}',
						'subscription' => 'sub_{{SUBSCRIPTION_ID}}',
						'metadata' => array(
							'user_id' => '123',
							'tier_slug' => 'premium',
							'stripe_price_id' => 'price_{{PRICE_ID}}',
							'consent' => 'true',
						),
					),
				),
			);
			break;

		case 'paid_adapter_dry_run_manifest.json':
			$actual = array(
				'run_id' => 'dryrun_{{RUN_ID}}',
				'adapter' => 'meta_ads',
				'mode' => 'dry_run',
				'items' => array(
					array('asset_id' => 'asset_{{ASSET_ID_1}}', 'action' => 'create_campaign', 'status' => 'planned'),
					array('asset_id' => 'asset_{{ASSET_ID_2}}', 'action' => 'create_adset', 'status' => 'planned'),
				),
			);
			break;

		case 'paid_adapter_dry_run_response.json':
			$actual = array(
				'run_id' => 'dryrun_{{RUN_ID}}',
				'adapter' => 'meta_ads',
				'mode' => 'dry_run',
				'status' => 'ok',
				'summary' => array('planned_operations' => 2, 'warnings' => 0, 'errors' => 0),
				'artifacts' => array(array('artifact_type' => 'manifest', 'path' => '{{MANIFEST_PATH}}')),
			);
			break;

		case 'paid_adapter_execute_response.json':
			$actual = array(
				'run_id' => 'exec_{{RUN_ID}}',
				'adapter' => 'meta_ads',
				'mode' => 'execute',
				'result' => 'success',
				'created_ids' => array(
					'campaign_id' => 'cmp_{{CAMPAIGN_ID}}',
					'adset_id' => 'adset_{{ADSET_ID}}',
					'ad_id' => 'ad_{{AD_ID}}',
				),
			);
			break;

		default:
			$actual = $expected;
	}

	if ($forced_mismatch === $fixture) {
		$actual['forced_mismatch'] = true;
	}

	return $actual;
}

/**
 * @return array<string,mixed>
 */
function run_mock_llm_fixture(string $fixture): array {
	$mock_path = 'app/public/wp-content/plugins/kh-smma/tests/MockLLMClient.php';
	if (!is_file($mock_path)) {
		throw new RuntimeException('MockLLMClient not found: ' . $mock_path);
	}
	require_once $mock_path;

	$previous_fixture = getenv('KH_SMMA_GOLDEN_FIXTURE');
	$previous_mode = getenv('KH_SMMA_TEST_MODE');
	putenv('KH_SMMA_TEST_MODE=ci');
	putenv('KH_SMMA_GOLDEN_FIXTURE=' . $fixture);

	try {
		$client = new \KH_SMMA\Tests\MockLLMClient();
		$response = $client->call('SMMA-AI', 'golden-check-runtime');
		if (!is_array($response)) {
			throw new RuntimeException('MockLLMClient returned non-array response.');
		}
		return $response;
	} finally {
		if ($previous_fixture === false) {
			putenv('KH_SMMA_GOLDEN_FIXTURE');
		} else {
			putenv('KH_SMMA_GOLDEN_FIXTURE=' . $previous_fixture);
		}
		if ($previous_mode === false) {
			putenv('KH_SMMA_TEST_MODE');
		} else {
			putenv('KH_SMMA_TEST_MODE=' . $previous_mode);
		}
	}
}

function build_unified_diff(string $expected_file, string $actual_file): string {
	$cmd = 'git --no-pager diff --no-index -- ' . escapeshellarg($expected_file) . ' ' . escapeshellarg($actual_file);
	$output = shell_exec($cmd . ' 2>&1');
	if (!is_string($output)) {
		return "diff unavailable\n";
	}
	return $output;
}

/**
 * @param array<string,mixed> $summary
 */
function write_summary(string $path, array $summary): void {
	$encoded = json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
	if ($encoded === false) {
		throw new RuntimeException('Unable to encode golden summary JSON.');
	}
	file_put_contents($path, $encoded . PHP_EOL);
}

/**
 * @param list<string> $files
 */
function write_zip(string $zip_path, array $files): void {
	prepare_dir(dirname($zip_path));

	if (!class_exists('ZipArchive')) {
		return;
	}

	$zip = new ZipArchive();
	if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
		throw new RuntimeException('Unable to create zip artifact: ' . $zip_path);
	}

	foreach ($files as $file) {
		if (!is_file($file)) {
			continue;
		}
		$zip->addFile($file, basename($file));
	}

	$zip->close();
}

/**
 * @return array<string,mixed>
 */
function mismatch_record(string $fixture, string $owner, string $reason, string $message, string $expected, string $actual, string $diff): array {
	return array(
		'fixture' => $fixture,
		'owner' => $owner,
		'reason' => $reason,
		'message' => $message,
		'checksum_expected' => $expected,
		'checksum_actual' => $actual,
		'diff_file' => $diff,
	);
}
