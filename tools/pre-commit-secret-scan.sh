#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(git rev-parse --show-toplevel)"
cd "$ROOT_DIR"

if [[ ! -f scripts/secret_scan.php ]]; then
  echo "[pre-commit] scripts/secret_scan.php not found; skipping." >&2
  exit 0
fi

echo "[pre-commit] running secret scan"
php scripts/secret_scan.php
