#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(git rev-parse --show-toplevel)"
HOOKS_DIR="$ROOT_DIR/.git/hooks"

if [[ ! -d "$HOOKS_DIR" ]]; then
  echo "Unable to find .git/hooks at $HOOKS_DIR" >&2
  exit 1
fi

cp "$ROOT_DIR/tools/pre-commit-secret-scan.sh" "$HOOKS_DIR/pre-commit"
chmod +x "$HOOKS_DIR/pre-commit"

echo "Installed pre-commit hook: $HOOKS_DIR/pre-commit"
