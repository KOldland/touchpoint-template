#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(git rev-parse --show-toplevel)"
cd "$ROOT_DIR"

if [[ ! -f scripts/secret_scan.php ]]; then
  echo "[pre-push] scripts/secret_scan.php not found; skipping." >&2
  exit 0
fi

echo "[pre-push] running strict secret scan on changed files"
php scripts/secret_scan.php --strict --changed --fix --output artifacts/secret-scan-prepush.json --telemetry artifacts/secret-scan-prepush-telemetry.json
