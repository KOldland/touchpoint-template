#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(git rev-parse --show-toplevel)"
cd "$ROOT_DIR"

if [[ ! -f scripts/secret_scan.php ]]; then
  echo "[pre-commit] scripts/secret_scan.php not found; skipping." >&2
  exit 0
fi

STAGED_FILES="$(git diff --cached --name-only --diff-filter=ACM | tr '\n' ',' | sed 's/,$//')"
if [[ -z "${STAGED_FILES}" ]]; then
  echo "[pre-commit] no staged files to scan."
  exit 0
fi

echo "[pre-commit] running secret scan on staged files"
php scripts/secret_scan.php --paths "${STAGED_FILES}" --strict --fix --output artifacts/secret-scan-precommit.json --telemetry artifacts/secret-scan-precommit-telemetry.json
