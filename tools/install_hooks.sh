#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(git rev-parse --show-toplevel)"
HOOKS_DIR="$(git rev-parse --git-path hooks)"

if [[ ! -d "$HOOKS_DIR" ]]; then
  echo "Unable to find .git/hooks at $HOOKS_DIR" >&2
  exit 1
fi

install_hook() {
  local source_file="$1"
  local target_name="$2"
  local source_path="$ROOT_DIR/tools/$source_file"
  local target_path="$HOOKS_DIR/$target_name"

  if [[ ! -f "$source_path" ]]; then
    echo "Hook source missing: $source_path" >&2
    exit 1
  fi

  if [[ -f "$target_path" ]]; then
    cp "$target_path" "$target_path.bak"
    echo "Backed up existing hook: $target_path.bak"
  fi

  cp "$source_path" "$target_path"
  chmod +x "$target_path"
  echo "Installed $target_name hook: $target_path"
}

install_hook "pre-commit-secret-scan.sh" "pre-commit"
install_hook "pre-push-secret-scan.sh" "pre-push"
