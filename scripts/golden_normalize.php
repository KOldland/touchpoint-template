<?php

declare(strict_types=1);

/**
 * Normalize data recursively for deterministic golden comparisons.
 *
 * - Removes transient helper keys (e.g. _mock, _prompt_hash)
 * - Canonicalizes obvious volatile fields (ids/timestamps)
 * - Sorts associative array keys for stable JSON encoding
 *
 * @param mixed       $value
 * @param string|null $key
 * @return mixed
 */
function golden_normalize_value($value, ?string $key = null) {
	if (is_array($value)) {
		$is_assoc = golden_is_assoc($value);
		$normalized = array();

		foreach ($value as $k => $item) {
			$child_key = is_string($k) ? $k : null;
			if (is_string($child_key) && str_starts_with($child_key, '_')) {
				continue;
			}
			$normalized[$k] = golden_normalize_value($item, $child_key);
		}

		if ($is_assoc) {
			ksort($normalized);
		}

		return $normalized;
	}

	if (is_string($value)) {
		if (preg_match('/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}(?:\\.\\d+)?Z$/', $value)) {
			return '{{ISO8601}}';
		}

		if (
			is_string($key) &&
			preg_match('/(^id$|_id$|token$|_token$)/i', $key) === 1 &&
			preg_match('/^\\{\\{[A-Z0-9_]+\\}\\}$/', $value) !== 1
		) {
			return '{{ID_TOKEN}}';
		}

		$value = preg_replace('/\\b(evt|cs|in|sub|cus|price|pi|pm|tok|adset|cmp|asset|ad)_[A-Za-z0-9_]+\\b/', '{{ID_TOKEN}}', $value);
		return $value;
	}

	if (is_int($value) || is_float($value)) {
		if (is_string($key) && preg_match('/(created|updated|timestamp|time)$/i', $key) === 1) {
			return '{{UNIX_TS}}';
		}
	}

	return $value;
}

/**
 * @param array<mixed> $value
 */
function golden_is_assoc(array $value): bool {
	if (array() === $value) {
		return false;
	}
	return array_keys($value) !== range(0, count($value) - 1);
}

/**
 * @param mixed $value
 */
function golden_canonical_json($value): string {
	$encoded = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
	if ($encoded === false) {
		throw new RuntimeException('Failed to encode canonical JSON.');
	}
	return $encoded . PHP_EOL;
}
