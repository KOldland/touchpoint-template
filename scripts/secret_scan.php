<?php

declare(strict_types=1);

$targets = array(
	'app/public/wp-content/plugins/kh-smma/tests/fixtures/golden',
	'docs/contracts',
	'scripts',
);

$patterns = array(
	'stripe_secret' => '/sk_(live|test)_[A-Za-z0-9]{16,}/',
	'webhook_secret' => '/whsec_[A-Za-z0-9]{16,}/',
	'private_key' => '/-----BEGIN (RSA|EC|OPENSSH|PRIVATE) KEY-----/',
	'aws_access_key' => '/\bAKIA[0-9A-Z]{16}\b/',
	'bearer_token' => '/\bBearer\s+[A-Za-z0-9\-._~+\/]+=*/i',
);

$hits = array();

foreach ($targets as $target) {
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

		$path = str_replace('\\', '/', (string) $file_info->getPathname());
		if (str_contains($path, '/vendor/') || str_contains($path, '/node_modules/')) {
			continue;
		}

		$content = file_get_contents($path);
		if ($content === false) {
			continue;
		}

		foreach ($patterns as $name => $regex) {
			if (preg_match($regex, $content) === 1) {
				$hits[] = array('pattern' => $name, 'file' => $path);
			}
		}
	}
}

if (!empty($hits)) {
	fwrite(STDERR, "Secret scan failed. Potential secret-like patterns found:\n");
	foreach ($hits as $hit) {
		fwrite(STDERR, ' - [' . $hit['pattern'] . '] ' . $hit['file'] . "\n");
	}
	exit(1);
}

fwrite(STDOUT, "Secret scan passed.\n");
