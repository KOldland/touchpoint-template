<?php

declare(strict_types=1);

$remote = $_SERVER['REMOTE_ADDR'] ?? '';
if ($remote !== '127.0.0.1' && $remote !== '::1' && $remote !== '') {
	http_response_code(403);
	echo 'Local access only.';
	exit;
}

require_once __DIR__ . '/../scripts/golden_normalize.php';

$fixture_dir = __DIR__ . '/../app/public/wp-content/plugins/kh-smma/tests/fixtures/golden';
$fixtures = glob($fixture_dir . '/*.json') ?: array();
$fixtures = array_values(array_filter($fixtures, static fn(string $path): bool => !str_ends_with($path, '.meta.json')));
sort($fixtures);

$selected = isset($_GET['fixture']) ? basename((string)$_GET['fixture']) : basename($fixtures[0] ?? '');
$fixture_path = $fixture_dir . '/' . $selected;
$meta_path = str_replace('.json', '.meta.json', $fixture_path);

$expected = is_file($fixture_path) ? json_decode((string)file_get_contents($fixture_path), true) : array();
$meta = is_file($meta_path) ? json_decode((string)file_get_contents($meta_path), true) : array();

$actual = array();
$diff = '';
if (isset($_GET['run']) && $_GET['run'] === '1' && is_file($fixture_path)) {
	$actual = run_mock_fixture($selected);
	$diff = unified_diff($expected, $actual, $selected);
}

function run_mock_fixture(string $fixture): array {
	$plugin_root = __DIR__ . '/../app/public/wp-content/plugins/kh-smma';
	$bootstrap = $plugin_root . '/tests/bootstrap.php';
	$mock = $plugin_root . '/tests/MockLLMClient.php';
	if (!is_file($bootstrap) || !is_file($mock)) {
		return array('error' => 'Missing bootstrap/mock files');
	}

	putenv('KH_SMMA_TEST_MODE=ci');
	putenv('KH_SMMA_GOLDEN_FIXTURE=' . $fixture);
	putenv('OPENAI_API_KEY');
	putenv('OPENAI_KEY');
	putenv('ANTHROPIC_API_KEY');
	putenv('ANTHROPIC_KEY');
	putenv('DUAL_GPT_API_KEY');
	putenv('LLM_API_KEY');

	require_once $bootstrap;
	require_once $mock;
	\KH_SMMA\Tests\inject_mock_llm_client();

	$client = new \KH_SMMA\Tests\MockLLMClient();
	$response = $client->call('SMMA-AI', 'preview');
	return is_array($response) ? $response : array();
}

function unified_diff(array $expected, array $actual, string $name): string {
	$tmp = sys_get_temp_dir();
	$base = preg_replace('/[^A-Za-z0-9_.-]+/', '_', $name) ?: 'fixture';
	$exp = $tmp . '/' . $base . '.expected.json';
	$act = $tmp . '/' . $base . '.actual.json';
	file_put_contents($exp, golden_canonical_json(golden_normalize_value($expected)));
	file_put_contents($act, golden_canonical_json(golden_normalize_value($actual)));
	$cmd = 'git --no-pager diff --no-index -- ' . escapeshellarg($exp) . ' ' . escapeshellarg($act);
	$out = shell_exec($cmd . ' 2>&1');
	return is_string($out) ? $out : '';
}

?><!doctype html>
<html>
<head>
  <meta charset="utf-8" />
  <title>Golden Preview</title>
  <style>
    body { font-family: -apple-system,BlinkMacSystemFont,Segoe UI,sans-serif; margin: 20px; color:#111; }
    .row { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
    pre { background:#0b1020; color:#e6edf3; padding:12px; border-radius:6px; overflow-x:auto; min-height: 280px; }
    select, button { padding:8px; font-size:14px; }
    table { border-collapse: collapse; margin-top: 10px; }
    td,th { border: 1px solid #ddd; padding: 6px 8px; font-size: 13px; }
  </style>
</head>
<body>
  <h1>Golden Fixture Preview</h1>
  <form method="get">
    <label for="fixture">Fixture</label>
    <select name="fixture" id="fixture">
      <?php foreach ($fixtures as $path): $name = basename($path); ?>
        <option value="<?= htmlspecialchars($name, ENT_QUOTES) ?>" <?= $name === $selected ? 'selected' : '' ?>><?= htmlspecialchars($name, ENT_QUOTES) ?></option>
      <?php endforeach; ?>
    </select>
    <button type="submit" name="run" value="1">Run Mock & Diff</button>
  </form>

  <h2>Metadata</h2>
  <table>
    <tr><th>author</th><td><?= htmlspecialchars((string)($meta['author'] ?? ''), ENT_QUOTES) ?></td></tr>
    <tr><th>prompt_version</th><td><?= htmlspecialchars((string)($meta['prompt_version'] ?? ''), ENT_QUOTES) ?></td></tr>
    <tr><th>prompt_hash</th><td><?= htmlspecialchars((string)($meta['prompt_hash'] ?? ''), ENT_QUOTES) ?></td></tr>
    <tr><th>checksum</th><td><?= htmlspecialchars((string)($meta['checksum'] ?? ''), ENT_QUOTES) ?></td></tr>
  </table>

  <div class="row">
    <div>
      <h2>Expected</h2>
      <pre><?= htmlspecialchars(golden_canonical_json(golden_normalize_value($expected)), ENT_QUOTES) ?></pre>
    </div>
    <div>
      <h2>Actual (MockLLM)</h2>
      <pre><?= htmlspecialchars($actual ? golden_canonical_json(golden_normalize_value($actual)) : 'Run preview to generate actual output.', ENT_QUOTES) ?></pre>
    </div>
  </div>

  <h2>Unified Diff</h2>
  <pre><?= htmlspecialchars($diff !== '' ? $diff : 'No diff generated.', ENT_QUOTES) ?></pre>

  <p>Local only tool. Run with: <code>php -S 127.0.0.1:8080 -t tools</code> and open <code>/golden_preview.php</code>.</p>
</body>
</html>
