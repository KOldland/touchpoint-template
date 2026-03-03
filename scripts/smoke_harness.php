<?php

declare(strict_types=1);

require_once __DIR__ . '/golden_normalize.php';

const CIC_SMOKE_PLUGIN_ROOT = __DIR__ . '/../app/public/wp-content/plugins/kh-smma';
const CIC_SMOKE_GOLDEN_DIR = CIC_SMOKE_PLUGIN_ROOT . '/tests/fixtures/golden';

if (!function_exists('cic_smoke_run_harness')) {
	/**
	 * @param array<string,mixed> $options
	 * @return array<string,mixed>
	 */
	function cic_smoke_run_harness(array $options = array()): array {
		$output_dir = (string)($options['output'] ?? 'artifacts/smoke-output');
		$fixture = (string)($options['fixture'] ?? getenv('KH_SMMA_GOLDEN_FIXTURE') ?: 'generate_awareness_ok.json');
		$force_mismatch = (string)($options['force-mismatch'] ?? getenv('KHM_SMOKE_FORCE_MISMATCH') ?: '');
		$skip_poll = !empty($options['skip-poll']);

		cic_smoke_prepare_environment($fixture);
		cic_smoke_prepare_dirs($output_dir);
		cic_smoke_reset_test_state();

		$log_lines = array();
		$telemetry = array();
		$mismatches = array();
		$stage_checksums = array();
		$diff_files = array();

		$log = static function (string $line) use (&$log_lines): void {
			$log_lines[] = $line;
		};

		$log('CIC smoke harness started.');
		$log('Fixture: ' . $fixture);
		$log('skip-poll: ' . ($skip_poll ? 'true' : 'false'));

		update_option('kh_smma_feature_flags', array('smma' => true, 'smma_paid_adapters' => false));

		$flags = new \KH_SMMA\Services\FeatureFlags();
		$generator = new \KH_SMMA\Services\SmmaGenerator();
		$audit_logger = new \KH_SMMA\Services\AuditLogger(new \wpdb());
		$controller = new \KH_SMMA\API\RestController($flags, $generator, $audit_logger);

		$generate_request = array(
			'post_id' => 123,
			'num_variants' => 1,
			'phase_tag' => 'Awareness',
			'tone' => 'Authority',
			'geo_targets' => array('GB'),
			'generate_google_ads' => false,
			'blocks_summary' => 'Deterministic smoke fixture request.',
		);

		$telemetry[] = array(
			'event' => 'generate.request',
			'fixture' => $fixture,
			'payload_hash' => cic_smoke_hash($generate_request),
		);

		$generate_response = $controller->handle_generate(new \WP_REST_Request($generate_request, array('X-WP-Nonce' => 'nonce')));
		if ($generate_response instanceof \WP_Error) {
			return cic_smoke_fail($output_dir, $log_lines, $telemetry, 'generate.response returned WP_Error');
		}

		$expected_generate = cic_smoke_expected_generate($fixture);
		$actual_generate_full = array(
			'linkedin_variants' => is_array($generate_response['linkedin_variants'] ?? null) ? $generate_response['linkedin_variants'] : array(),
			'model' => (string)($generate_response['model'] ?? ''),
		);
		$actual_generate = cic_smoke_project_to_expected_shape($expected_generate, $actual_generate_full);
		cic_smoke_compare_stage(
			'generate.response',
			$expected_generate,
			$actual_generate,
			$force_mismatch,
			$output_dir,
			$mismatches,
			$stage_checksums,
			$diff_files
		);
		$telemetry[] = array(
			'event' => 'generate.response',
			'fixture' => $fixture,
			'payload_hash' => cic_smoke_hash($actual_generate_full),
		);
		$log('Stage generate.response complete.');

		$variant = $actual_generate_full['linkedin_variants'][0] ?? null;
		if (!is_array($variant) || empty($variant['text']) || empty($variant['variant_id'])) {
			return cic_smoke_fail($output_dir, $log_lines, $telemetry, 'No variant produced by generate stage.');
		}

		putenv('KH_SMMA_GOLDEN_FIXTURE=compliance_pass_response.json');
		$compliance_request = array(
			'variant_id' => (string)$variant['variant_id'],
			'text' => (string)$variant['text'],
			'channel' => 'linkedin',
			'metadata' => array('phase_tag' => 'Awareness'),
		);
		$compliance_response = $controller->handle_compliance_check(new \WP_REST_Request($compliance_request, array('X-WP-Nonce' => 'nonce')));
		if ($compliance_response instanceof \WP_Error) {
			return cic_smoke_fail($output_dir, $log_lines, $telemetry, 'compliance.check returned WP_Error');
		}

		$expected_compliance = cic_smoke_load_fixture('compliance_ok.json');
		$actual_compliance = array(
			'passed' => (bool)($compliance_response['pass'] ?? false),
			'level' => (string)($compliance_response['level'] ?? ''),
			'message' => (string)($compliance_response['details']['message'] ?? ''),
			'confidence_score' => (float)($compliance_response['confidence'] ?? 0.0),
			'flags' => array_values(is_array($compliance_response['flags'] ?? null) ? $compliance_response['flags'] : array()),
		);
		cic_smoke_compare_stage(
			'compliance.check',
			$expected_compliance,
			$actual_compliance,
			$force_mismatch,
			$output_dir,
			$mismatches,
			$stage_checksums,
			$diff_files
		);
		$telemetry[] = array(
			'event' => 'compliance.check',
			'fixture' => 'compliance_ok.json',
			'payload_hash' => cic_smoke_hash($actual_compliance),
		);
		$log('Stage compliance.check complete.');

		$draft_schedule_id = (int) wp_insert_post(
			array(
				'post_type' => 'kh_smma_schedule',
				'post_title' => 'Draft schedule for variant-edit smoke',
				'post_status' => 'publish',
			),
			true
		);
		if ($draft_schedule_id <= 0) {
			return cic_smoke_fail($output_dir, $log_lines, $telemetry, 'Unable to create draft schedule for variant edit.');
		}

		update_post_meta($draft_schedule_id, '_kh_smma_payload', array(
			'post_id' => 123,
			'variant_id' => (string)$variant['variant_id'],
			'channel' => 'linkedin',
			'phase_tag' => 'Awareness',
			'text' => (string)$variant['text'],
		));
		update_post_meta($draft_schedule_id, '_kh_smma_sponsor_id', 0);
		update_post_meta($draft_schedule_id, '_kh_smma_sponsor_mode', '');
		update_post_meta($draft_schedule_id, '_kh_smma_sponsor_assets', array());

		$edited_text = (string)$variant['text'] . ' Updated for deterministic smoke harness.';
		putenv('KH_SMMA_GOLDEN_FIXTURE=compliance_pass_response.json');
		$variant_edit_response = $controller->handle_variant_edit(
			new \WP_REST_Request(
				array(
					'schedule_id' => $draft_schedule_id,
					'updated_text' => $edited_text,
				),
				array('X-WP-Nonce' => 'nonce')
			)
		);
		if ($variant_edit_response instanceof \WP_Error) {
			return cic_smoke_fail($output_dir, $log_lines, $telemetry, 'variant.edit returned WP_Error');
		}

		$stored_variant_payload = get_post_meta($draft_schedule_id, '_kh_smma_payload', true);
		$actual_variant_edit = array(
			'status' => (string)($variant_edit_response['status'] ?? ''),
			'compliance_passed' => (bool)($variant_edit_response['compliance']['passed'] ?? false),
			'stored_text' => is_array($stored_variant_payload) ? (string)($stored_variant_payload['text'] ?? '') : '',
		);
		$expected_variant_edit = array(
			'status' => 'updated',
			'compliance_passed' => true,
			'stored_text' => $edited_text,
		);
		cic_smoke_compare_stage(
			'variant.edit',
			$expected_variant_edit,
			$actual_variant_edit,
			$force_mismatch,
			$output_dir,
			$mismatches,
			$stage_checksums,
			$diff_files
		);
		$telemetry[] = array(
			'event' => 'variant.edit',
			'fixture' => 'inline',
			'payload_hash' => cic_smoke_hash($actual_variant_edit),
		);
		$log('Stage variant.edit complete.');

		$schedule_request = array(
			'post_id' => 123,
			'schedule' => array(
				array(
					'variant_id' => (string)$variant['variant_id'],
					'scheduled_at' => '2026-03-03T10:00:00+00:00',
					'geo' => 'GB',
					'text' => $edited_text,
				),
			),
			'boost' => false,
		);
		$schedule_response = $controller->handle_schedule(new \WP_REST_Request($schedule_request, array('X-WP-Nonce' => 'nonce')));
		if ($schedule_response instanceof \WP_Error) {
			return cic_smoke_fail($output_dir, $log_lines, $telemetry, 'schedule.create returned WP_Error');
		}

		$created = is_array($schedule_response['created'] ?? null) ? $schedule_response['created'] : array();
		$created_first = is_array($created[0] ?? null) ? $created[0] : array();
		$schedule_id = (int)($created_first['schedule_id'] ?? 0);
		if ($schedule_id <= 0) {
			return cic_smoke_fail($output_dir, $log_lines, $telemetry, 'schedule.create did not return schedule_id');
		}

		$actual_schedule = array(
			'created_count' => count($created),
			'schedule_status' => (string)($created_first['schedule_status'] ?? ''),
			'approval_status' => (string)($created_first['approval_status'] ?? ''),
			'approval_required' => (bool)($created_first['approval_required'] ?? false),
		);
		$expected_schedule = array(
			'created_count' => 1,
			'schedule_status' => 'pending',
			'approval_status' => 'auto_approved',
			'approval_required' => false,
		);
		cic_smoke_compare_stage(
			'schedule.create',
			$expected_schedule,
			$actual_schedule,
			$force_mismatch,
			$output_dir,
			$mismatches,
			$stage_checksums,
			$diff_files
		);
		$telemetry[] = array(
			'event' => 'schedule.create',
			'fixture' => 'inline',
			'payload_hash' => cic_smoke_hash($actual_schedule),
		);
		$log('Stage schedule.create complete: schedule_id=' . $schedule_id);

		$GLOBALS['kh_test_wp_query_posts'] = array($schedule_id);
		update_post_meta($schedule_id, '_kh_smma_schedule_status', 'pending');
		update_post_meta($schedule_id, '_kh_smma_scheduled_at', time());
		update_post_meta($schedule_id, '_kh_smma_account_id', 0);
		update_post_meta($schedule_id, '_kh_smma_campaign_id', 0);
		update_post_meta($schedule_id, '_kh_smma_delivery_mode', 'manual_export');

		$vault = new \KH_SMMA\Security\CredentialVault('smoke-harness-key');
		$tokens = new \KH_SMMA\Services\TokenRepository(new \wpdb(), $vault);
		$queue_logger = new \KH_SMMA\Services\AuditLogger(new \wpdb());
		$manual_export_adapter = new \KH_SMMA\Adapters\ManualExportAdapter();
		$manual_export_adapter->register();
		$processor = new \KH_SMMA\Services\ScheduleQueueProcessor($tokens, $queue_logger);
		$processor->process_due_schedules();

		$dispatch_status = (string) get_post_meta($schedule_id, '_kh_smma_schedule_status', true);
		$dispatch_bundle = get_post_meta($schedule_id, '_kh_smma_export_bundle', true);
		$actual_dry_run = cic_smoke_build_dry_run_manifest($dispatch_status, is_array($dispatch_bundle) ? $dispatch_bundle : array());
		$expected_dry_run = cic_smoke_load_fixture('paid_adapter_dry_run_manifest.json');
		cic_smoke_compare_stage(
			'paid_adapter.dry_run',
			$expected_dry_run,
			$actual_dry_run,
			$force_mismatch,
			$output_dir,
			$mismatches,
			$stage_checksums,
			$diff_files
		);
		$telemetry[] = array(
			'event' => 'paid_adapter.dry_run',
			'fixture' => 'paid_adapter_dry_run_manifest.json',
			'status' => $dispatch_status,
			'payload_hash' => cic_smoke_hash($actual_dry_run),
		);
		$log('Stage paid_adapter.dry_run complete.');

		$actual_event_order = array_values(array_map(static fn(array $event): string => (string)($event['event'] ?? ''), $telemetry));
		$expected_event_order = array(
			'generate.request',
			'generate.response',
			'compliance.check',
			'variant.edit',
			'schedule.create',
			'paid_adapter.dry_run',
		);
		if ($actual_event_order !== $expected_event_order) {
			$mismatches[] = array(
				'stage' => 'telemetry.order',
				'reason' => 'event_order_mismatch',
				'expected' => $expected_event_order,
				'actual' => $actual_event_order,
			);
		}

		$result = empty($mismatches) ? 'success' : 'failure';
		$summary = array(
			'result' => $result,
			'fixture' => $fixture,
			'checked_stages' => array_keys($stage_checksums),
			'stage_checksums' => $stage_checksums,
			'mismatches' => $mismatches,
			'telemetry_event_count' => count($telemetry),
		);

		$summary_path = $output_dir . '/smoke-summary.json';
		$telemetry_path = $output_dir . '/smoke-telemetry.json';
		$log_path = $output_dir . '/smoke-log.txt';
		$diff_zip_path = $output_dir . '/smoke-diffs.zip';

		cic_smoke_write_json($summary_path, $summary);
		cic_smoke_write_json($telemetry_path, $telemetry);
		file_put_contents($log_path, implode(PHP_EOL, $log_lines) . PHP_EOL);

		$zip_sources = array($summary_path, $telemetry_path, $log_path);
		foreach ($diff_files as $file) {
			if (is_file($file)) {
				$zip_sources[] = $file;
			}
		}
		cic_smoke_write_zip($diff_zip_path, $zip_sources);

		return array(
			'result' => $result,
			'exit_code' => $result === 'success' ? 0 : 1,
			'summary_path' => $summary_path,
			'telemetry_path' => $telemetry_path,
			'log_path' => $log_path,
			'diff_zip_path' => $diff_zip_path,
			'mismatches' => $mismatches,
		);
	}
}

/**
 * @param mixed $expected
 * @param mixed $actual
 * @return mixed
 */
function cic_smoke_project_to_expected_shape($expected, $actual) {
	if (is_array($expected)) {
		if (!is_array($actual)) {
			return $expected;
		}

		$is_assoc = golden_is_assoc($expected);
		$out = array();

		if ($is_assoc) {
			foreach ($expected as $key => $expected_value) {
				$out[$key] = cic_smoke_project_to_expected_shape($expected_value, $actual[$key] ?? null);
			}
			return $out;
		}

		foreach ($expected as $index => $expected_item) {
			$out[$index] = cic_smoke_project_to_expected_shape($expected_item, $actual[$index] ?? null);
		}
		return $out;
	}

	return $actual;
}

/**
 * @return array<string,string>
 */
function cic_smoke_parse_options(array $argv): array {
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

function cic_smoke_prepare_environment(string $fixture): void {
	$autoload = CIC_SMOKE_PLUGIN_ROOT . '/vendor/autoload.php';
	if (!is_file($autoload)) {
		throw new RuntimeException('Missing plugin dependencies. Run: (cd app/public/wp-content/plugins/kh-smma && composer install)');
	}

	$keys = array('OPENAI_API_KEY', 'OPENAI_KEY', 'ANTHROPIC_API_KEY', 'ANTHROPIC_KEY', 'DUAL_GPT_API_KEY', 'LLM_API_KEY');
	foreach ($keys as $key) {
		$value = getenv($key);
		if (is_string($value) && strlen($value) > 10) {
			throw new RuntimeException('Real LLM key present while running deterministic smoke harness: ' . $key);
		}
	}

	putenv('KH_SMMA_TEST_MODE=ci');
	putenv('CI=true');
	putenv('KH_SMMA_GOLDEN_FIXTURE=' . $fixture);

	require_once CIC_SMOKE_PLUGIN_ROOT . '/tests/bootstrap.php';
	require_once CIC_SMOKE_PLUGIN_ROOT . '/tests/MockLLMClient.php';
	\KH_SMMA\Tests\inject_mock_llm_client();
}

function cic_smoke_prepare_dirs(string $output_dir): void {
	if (!is_dir($output_dir) && !mkdir($output_dir, 0775, true) && !is_dir($output_dir)) {
		throw new RuntimeException('Unable to create output directory: ' . $output_dir);
	}
	$diff_dir = $output_dir . '/diffs';
	if (!is_dir($diff_dir) && !mkdir($diff_dir, 0775, true) && !is_dir($diff_dir)) {
		throw new RuntimeException('Unable to create diff directory: ' . $diff_dir);
	}
}

function cic_smoke_reset_test_state(): void {
	$GLOBALS['kh_test_post_meta'] = array();
	$GLOBALS['kh_test_options'] = array();
	$GLOBALS['kh_test_filters'] = array();
	$GLOBALS['kh_test_wp_query_posts'] = array();
	$GLOBALS['kh_test_next_post_id'] = 1000;
}

/**
 * @param mixed $payload
 */
function cic_smoke_hash($payload): string {
	return hash('sha256', golden_canonical_json(golden_normalize_value($payload)));
}

/**
 * @return array<string,mixed>
 */
function cic_smoke_load_fixture(string $name): array {
	$path = CIC_SMOKE_GOLDEN_DIR . '/' . $name;
	if (!is_file($path)) {
		throw new RuntimeException('Fixture not found: ' . $path);
	}
	$raw = file_get_contents($path);
	if (!is_string($raw) || $raw === '') {
		throw new RuntimeException('Fixture unreadable: ' . $path);
	}
	$decoded = json_decode($raw, true);
	if (!is_array($decoded)) {
		throw new RuntimeException('Fixture invalid JSON: ' . $path);
	}
	return $decoded;
}

/**
 * @return array<string,mixed>
 */
function cic_smoke_expected_generate(string $fixture): array {
	$fixture_payload = cic_smoke_load_fixture($fixture);
	$content = (string)($fixture_payload['choices'][0]['message']['content'] ?? '');
	$decoded = json_decode($content, true);
	$variants = is_array($decoded['linkedin_variants'] ?? null) ? $decoded['linkedin_variants'] : array();

	return array(
		'linkedin_variants' => $variants,
		'model' => (string)($fixture_payload['model'] ?? 'mock-gpt-4-turbo'),
	);
}

/**
 * @param array<string,mixed> $bundle
 * @return array<string,mixed>
 */
function cic_smoke_build_dry_run_manifest(string $dispatch_status, array $bundle): array {
	$planned = $dispatch_status === 'awaiting_manual_export' && !empty($bundle);
	return array(
		'run_id' => 'dryrun_{{RUN_ID}}',
		'adapter' => 'meta_ads',
		'mode' => 'dry_run',
		'items' => array(
			array(
				'asset_id' => 'asset_{{ASSET_ID_1}}',
				'action' => 'create_campaign',
				'status' => $planned ? 'planned' : 'failed',
			),
			array(
				'asset_id' => 'asset_{{ASSET_ID_2}}',
				'action' => 'create_adset',
				'status' => $planned ? 'planned' : 'failed',
			),
		),
	);
}

/**
 * @param array<string,mixed> $expected
 * @param array<string,mixed> $actual
 * @param array<int,array<string,mixed>> $mismatches
 * @param array<string,array<string,string>> $stage_checksums
 * @param array<int,string> $diff_files
 */
function cic_smoke_compare_stage(
	string $stage,
	array $expected,
	array $actual,
	string $force_mismatch,
	string $output_dir,
	array &$mismatches,
	array &$stage_checksums,
	array &$diff_files
): void {
	if ($force_mismatch !== '' && $force_mismatch === $stage) {
		$actual['forced_mismatch'] = true;
	}

	$expected_normalized = golden_normalize_value($expected);
	$actual_normalized = golden_normalize_value($actual);

	$expected_json = golden_canonical_json($expected_normalized);
	$actual_json = golden_canonical_json($actual_normalized);
	$expected_checksum = 'sha256:' . hash('sha256', $expected_json);
	$actual_checksum = 'sha256:' . hash('sha256', $actual_json);

	$stage_checksums[$stage] = array(
		'expected' => $expected_checksum,
		'actual' => $actual_checksum,
	);

	if ($expected_checksum === $actual_checksum) {
		return;
	}

	$safe_stage = preg_replace('/[^a-zA-Z0-9_.-]+/', '_', $stage) ?: 'stage';
	$expected_file = $output_dir . '/diffs/' . $safe_stage . '.expected.json';
	$actual_file = $output_dir . '/diffs/' . $safe_stage . '.actual.json';
	$diff_file = $output_dir . '/diffs/' . $safe_stage . '.diff.patch';

	file_put_contents($expected_file, $expected_json);
	file_put_contents($actual_file, $actual_json);
	$diff = cic_smoke_unified_diff($expected_file, $actual_file);
	file_put_contents($diff_file, $diff);
	$diff_files[] = $diff_file;

	$mismatches[] = array(
		'stage' => $stage,
		'reason' => 'canonical_mismatch',
		'expected_checksum' => $expected_checksum,
		'actual_checksum' => $actual_checksum,
		'diff_file' => $diff_file,
	);
}

function cic_smoke_unified_diff(string $expected_file, string $actual_file): string {
	$cmd = 'git --no-pager diff --no-index -- ' . escapeshellarg($expected_file) . ' ' . escapeshellarg($actual_file);
	$output = shell_exec($cmd . ' 2>&1');
	return is_string($output) && $output !== '' ? $output : "diff unavailable\n";
}

/**
 * @param mixed $payload
 */
function cic_smoke_write_json(string $path, $payload): void {
	$encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
	if ($encoded === false) {
		throw new RuntimeException('Unable to encode JSON output: ' . $path);
	}
	file_put_contents($path, $encoded . PHP_EOL);
}

/**
 * @param list<string> $files
 */
function cic_smoke_write_zip(string $zip_path, array $files): void {
	if (!class_exists('ZipArchive')) {
		return;
	}

	$zip = new ZipArchive();
	if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
		throw new RuntimeException('Unable to write zip file: ' . $zip_path);
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
 * @param array<int,string> $log_lines
 * @param array<int,array<string,mixed>> $telemetry
 * @return array<string,mixed>
 */
function cic_smoke_fail(string $output_dir, array $log_lines, array $telemetry, string $message): array {
	$log_lines[] = 'FAIL: ' . $message;
	$summary_path = $output_dir . '/smoke-summary.json';
	$telemetry_path = $output_dir . '/smoke-telemetry.json';
	$log_path = $output_dir . '/smoke-log.txt';
	$diff_zip_path = $output_dir . '/smoke-diffs.zip';

	cic_smoke_write_json($summary_path, array(
		'result' => 'failure',
		'mismatches' => array(array('stage' => 'runtime', 'reason' => $message)),
	));
	cic_smoke_write_json($telemetry_path, $telemetry);
	file_put_contents($log_path, implode(PHP_EOL, $log_lines) . PHP_EOL);
	cic_smoke_write_zip($diff_zip_path, array($summary_path, $telemetry_path, $log_path));

	return array(
		'result' => 'failure',
		'exit_code' => 1,
		'summary_path' => $summary_path,
		'telemetry_path' => $telemetry_path,
		'log_path' => $log_path,
		'diff_zip_path' => $diff_zip_path,
		'mismatches' => array(array('stage' => 'runtime', 'reason' => $message)),
	);
}

if (PHP_SAPI === 'cli' && realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
	$options = cic_smoke_parse_options($argv);
	$result = cic_smoke_run_harness($options);
	echo 'Result: ' . $result['result'] . PHP_EOL;
	echo 'Summary: ' . $result['summary_path'] . PHP_EOL;
	echo 'Telemetry: ' . $result['telemetry_path'] . PHP_EOL;
	echo 'Log: ' . $result['log_path'] . PHP_EOL;
	echo 'Diffs: ' . $result['diff_zip_path'] . PHP_EOL;
	exit((int)($result['exit_code'] ?? 1));
}
