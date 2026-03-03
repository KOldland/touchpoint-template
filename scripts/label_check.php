<?php

declare(strict_types=1);

const GOLDEN_APPROVAL_LABEL = 'golden-owner-approved';

$options = parse_options($argv);
$base = $options['base'] ?? default_base_ref();
$head = $options['head'] ?? 'HEAD';
$event_path = $options['event'] ?? (string) getenv('GITHUB_EVENT_PATH');

$changed_files = get_changed_files($base, $head);
$needs_label = requires_golden_label($changed_files);

if (!$needs_label) {
	echo "Label check passed: no fixture/contract changes detected.\n";
	exit(0);
}

$labels = get_pr_labels($event_path);
if (in_array(GOLDEN_APPROVAL_LABEL, $labels, true)) {
	echo "Label check passed: found label '" . GOLDEN_APPROVAL_LABEL . "'.\n";
	exit(0);
}

$owners = discover_fixture_owners();

fwrite(STDERR, "Label check failed: fixture/contract files changed but label '" . GOLDEN_APPROVAL_LABEL . "' is missing.\n");
if (!empty($owners)) {
	fwrite(STDERR, "Fixture owners to notify:\n");
	foreach ($owners as $fixture => $owner) {
		fwrite(STDERR, " - {$fixture}: {$owner}\n");
	}
}
fwrite(STDERR, "Add the label after owner ACKs and re-run checks.\n");
exit(2);

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
		}
	}
	return $options;
}

function default_base_ref(): string {
	$from_env = (string) getenv('GITHUB_BASE_REF');
	if ($from_env !== '') {
		return 'origin/' . $from_env;
	}
	return 'origin/main';
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
 */
function requires_golden_label(array $files): bool {
	foreach ($files as $file) {
		if (str_starts_with($file, 'app/public/wp-content/plugins/kh-smma/tests/fixtures/golden/')) {
			return true;
		}
		if (str_starts_with($file, 'docs/contracts/')) {
			return true;
		}
	}
	return false;
}

/**
 * @return list<string>
 */
function get_pr_labels(string $event_path): array {
	$labels = array();

	if ($event_path !== '' && is_file($event_path)) {
		$raw = file_get_contents($event_path);
		if (is_string($raw) && $raw !== '') {
			$event = json_decode($raw, true);
			if (is_array($event) && isset($event['pull_request']['labels']) && is_array($event['pull_request']['labels'])) {
				foreach ($event['pull_request']['labels'] as $label) {
					if (is_array($label) && isset($label['name']) && is_string($label['name'])) {
						$labels[] = $label['name'];
					}
				}
			}
		}
	}

	return array_values(array_unique($labels));
}

/**
 * @return array<string,string>
 */
function discover_fixture_owners(): array {
	$owners = array();
	$readme = 'app/public/wp-content/plugins/kh-smma/tests/fixtures/golden/README.md';

	if (is_file($readme)) {
		$lines = file($readme, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
		if (is_array($lines)) {
			foreach ($lines as $line) {
				if (
					preg_match('/\|\s*`([^`]+\.json)`\s*\|\s*`(@[^`]+)`\s*\|/', $line, $m) === 1 ||
					preg_match('/-\s*`([^`]+\.json)`\s*[—-]\s*(@[A-Za-z0-9_-]+)/u', $line, $m) === 1
				) {
					$owners[$m[1]] = $m[2];
				}
			}
		}
	}

	if (!empty($owners)) {
		return $owners;
	}

	$meta_files = glob('app/public/wp-content/plugins/kh-smma/tests/fixtures/golden/*.meta.json');
	if ($meta_files === false) {
		return array();
	}
	foreach ($meta_files as $meta_file) {
		$raw = file_get_contents($meta_file);
		if (!is_string($raw) || $raw === '') {
			continue;
		}
		$meta = json_decode($raw, true);
		if (!is_array($meta)) {
			continue;
		}
		$owner = isset($meta['author']) && is_string($meta['author']) ? $meta['author'] : '@unknown';
		$fixture = basename((string) $meta_file, '.meta.json') . '.json';
		$owners[$fixture] = $owner;
	}

	ksort($owners);
	return $owners;
}
