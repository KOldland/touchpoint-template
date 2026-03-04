<?php

declare(strict_types=1);

$options = oft_parse_options($argv);
$output_dir = (string)($options['output-dir'] ?? 'artifacts/observability/alert-fire');
$mode = strtolower((string)($options['mode'] ?? 'dry-run'));
$endpoint = (string)($options['endpoint'] ?? getenv('OBS_ALERT_TEST_WEBHOOK') ?: '');
$timeout_ms = max(500, (int)($options['timeout-ms'] ?? 5000));
$requested = oft_parse_levels((string)($options['alerts'] ?? 'P0,P1,P2'));
$run_id = (string)($options['run-id'] ?? (getenv('GITHUB_RUN_ID') ?: 'local'));
$build_url = (string)($options['build-url'] ?? (getenv('GITHUB_SERVER_URL') && getenv('GITHUB_REPOSITORY') && getenv('GITHUB_RUN_ID')
	? rtrim((string)getenv('GITHUB_SERVER_URL'), '/') . '/' . trim((string)getenv('GITHUB_REPOSITORY'), '/') . '/actions/runs/' . (string)getenv('GITHUB_RUN_ID')
	: 'local-build'));
$pr_url = (string)($options['pr-url'] ?? 'n/a');

if (!in_array($mode, array('dry-run', 'emit'), true)) {
	fwrite(STDERR, "--mode must be dry-run or emit\n");
	exit(2);
}
if ($mode === 'emit' && $endpoint === '') {
	fwrite(STDERR, "--endpoint (or OBS_ALERT_TEST_WEBHOOK) is required in emit mode\n");
	exit(2);
}

oft_prepare_dir($output_dir);

$profiles = oft_alert_profiles($run_id, $build_url, $pr_url);
$profiles = array_values(array_filter($profiles, static function (array $profile) use ($requested): bool {
	return in_array((string)$profile['severity'], $requested, true);
}));

if ($profiles === array()) {
	fwrite(STDERR, "No alert severities selected. Use --alerts=P0,P1,P2\n");
	exit(2);
}

$started_at = gmdate('c');
$events = array();
$notifications = array();
$delivery_failures = 0;

$events[] = array(
	'event' => 'cic.alert_fire_test.started',
	'run_id' => $run_id,
	'mode' => $mode,
	'alert_count' => count($profiles),
	'timestamp' => $started_at,
);

foreach ($profiles as $profile) {
	$event = array(
		'event' => 'cic.alert_fire_test.triggered',
		'run_id' => $run_id,
		'alert_id' => $profile['id'],
		'severity' => $profile['severity'],
		'metric' => $profile['metric'],
		'value' => $profile['simulated_value'],
		'threshold' => $profile['threshold'],
		'window' => $profile['window'],
		'owner' => $profile['owner'],
		'runbook' => $profile['runbook'],
		'timestamp' => gmdate('c'),
	);
	$events[] = $event;

	$payload = array(
		'test_mode' => true,
		'alert' => $profile,
		'event' => $event,
		'links' => array(
			'build_url' => $build_url,
			'pr_url' => $pr_url,
			'diff_artifact' => 'artifacts/golden-fast/golden-diff.html',
		),
		'owner_hint' => $profile['owner'],
	);

	if ($mode === 'dry-run') {
		$notifications[] = array(
			'alert_id' => $profile['id'],
			'severity' => $profile['severity'],
			'route' => $profile['route'],
			'delivery' => 'simulated',
			'status_code' => 0,
			'endpoint' => 'n/a',
			'timestamp' => gmdate('c'),
		);
		continue;
	}

	$delivery = oft_post_json($endpoint, $payload, $timeout_ms);
	$notifications[] = array(
		'alert_id' => $profile['id'],
		'severity' => $profile['severity'],
		'route' => $profile['route'],
		'delivery' => $delivery['ok'] ? 'delivered' : 'failed',
		'status_code' => $delivery['status_code'],
		'endpoint' => $endpoint,
		'error' => $delivery['error'],
		'timestamp' => gmdate('c'),
	);
	if (!$delivery['ok']) {
		$delivery_failures++;
	}
}

$events[] = array(
	'event' => $delivery_failures > 0 ? 'cic.alert_fire_test.failed' : 'cic.alert_fire_test.completed',
	'run_id' => $run_id,
	'mode' => $mode,
	'delivery_failures' => $delivery_failures,
	'timestamp' => gmdate('c'),
);

$summary = array(
	'run_id' => $run_id,
	'mode' => $mode,
	'endpoint' => $mode === 'emit' ? $endpoint : 'n/a',
	'started_at' => $started_at,
	'ended_at' => gmdate('c'),
	'alerts_requested' => $requested,
	'alerts_triggered' => array_map(static fn(array $profile): string => (string)$profile['id'], $profiles),
	'delivery_failures' => $delivery_failures,
	'result' => $delivery_failures > 0 ? 'failure' : 'success',
	'evidence' => array(
		'events_json' => $output_dir . '/alert-fire-events.json',
		'events_jsonl' => $output_dir . '/alert-fire-events.jsonl',
		'notifications_json' => $output_dir . '/alert-fire-notifications.json',
		'summary_json' => $output_dir . '/alert-fire-summary.json',
	),
);

oft_write_json($output_dir . '/alert-fire-events.json', $events);
oft_write_jsonl($output_dir . '/alert-fire-events.jsonl', $events);
oft_write_json($output_dir . '/alert-fire-notifications.json', $notifications);
oft_write_json($output_dir . '/alert-fire-summary.json', $summary);

fwrite(STDOUT, "alert fire summary: " . $output_dir . "/alert-fire-summary.json\n");
fwrite(STDOUT, "alerts triggered: " . implode(', ', $summary['alerts_triggered']) . "\n");

if ($delivery_failures > 0) {
	exit(1);
}

exit(0);

/**
 * @return array<string,string>
 */
function oft_parse_options(array $argv): array {
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
function oft_parse_levels(string $input): array {
	$levels = array_map(static fn(string $v): string => strtoupper(trim($v)), explode(',', $input));
	$levels = array_values(array_filter($levels, static fn(string $v): bool => in_array($v, array('P0', 'P1', 'P2'), true)));
	return array_values(array_unique($levels));
}

function oft_prepare_dir(string $path): void {
	if ($path === '' || $path === '.') {
		return;
	}
	if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
		throw new RuntimeException('Unable to create directory: ' . $path);
	}
}

/**
 * @return list<array<string,mixed>>
 */
function oft_alert_profiles(string $run_id, string $build_url, string $pr_url): array {
	$common = array(
		'runbook' => 'docs/observability/alerting_runbook.md',
		'run_id' => $run_id,
		'build_url' => $build_url,
		'pr_url' => $pr_url,
	);

	return array(
		array_merge($common, array(
			'id' => 'cic_golden_check_failure_rate_p0',
			'severity' => 'P0',
			'metric' => 'golden_check_failure_rate',
			'simulated_value' => 18.4,
			'threshold' => '>10',
			'window' => '1h',
			'route' => array('pagerduty:cic-primary', 'slack:#cic-alerts'),
			'owner' => '@ci-qa-team',
		)),
		array_merge($common, array(
			'id' => 'cic_golden_check_duration_p1',
			'severity' => 'P1',
			'metric' => 'golden_check_duration_p90',
			'simulated_value' => 742000,
			'threshold' => '>600000',
			'window' => '1h',
			'route' => array('slack:#cic-alerts', 'email:ops-oncall'),
			'owner' => '@observability-owner',
		)),
		array_merge($common, array(
			'id' => 'cic_flaky_tests_detected_p2',
			'severity' => 'P2',
			'metric' => 'cic_flaky_tests_detected',
			'simulated_value' => 6,
			'threshold' => '>3',
			'window' => '24h',
			'route' => array('ticket:ci-flaky-investigate', 'slack:#cic-alerts'),
			'owner' => '@ci-qa-team',
		)),
	);
}

/**
 * @param array<string,mixed> $payload
 * @return array{ok:bool,status_code:int,error:string}
 */
function oft_post_json(string $url, array $payload, int $timeout_ms): array {
	$json = json_encode($payload, JSON_UNESCAPED_SLASHES);
	if ($json === false) {
		return array('ok' => false, 'status_code' => 0, 'error' => 'json_encode_failed');
	}

	$context = stream_context_create(array(
		'http' => array(
			'method' => 'POST',
			'header' => "Content-Type: application/json\r\nContent-Length: " . strlen($json) . "\r\n",
			'content' => $json,
			'timeout' => $timeout_ms / 1000,
			'ignore_errors' => true,
		),
	));

	$result = @file_get_contents($url, false, $context);
	$status_code = 0;
	$error = '';

	if (isset($http_response_header) && is_array($http_response_header)) {
		foreach ($http_response_header as $header) {
			if (preg_match('#HTTP/[0-9.]+\s+([0-9]{3})#', $header, $m) === 1) {
				$status_code = (int)$m[1];
				break;
			}
		}
	}

	if ($result === false) {
		$error = 'http_request_failed';
	}

	$ok = $status_code >= 200 && $status_code < 300;
	if (!$ok && $error === '' && $status_code > 0) {
		$error = 'http_status_' . $status_code;
	}

	return array('ok' => $ok, 'status_code' => $status_code, 'error' => $error);
}

/**
 * @param array<string,mixed>|list<array<string,mixed>> $payload
 */
function oft_write_json(string $path, array $payload): void {
	$json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
	if ($json === false) {
		throw new RuntimeException('Unable to encode JSON: ' . $path);
	}
	file_put_contents($path, $json . PHP_EOL);
}

/**
 * @param list<array<string,mixed>> $rows
 */
function oft_write_jsonl(string $path, array $rows): void {
	$lines = array();
	foreach ($rows as $row) {
		$line = json_encode($row, JSON_UNESCAPED_SLASHES);
		if ($line === false) {
			continue;
		}
		$lines[] = $line;
	}
	file_put_contents($path, implode(PHP_EOL, $lines) . PHP_EOL);
}
