#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(git rev-parse --show-toplevel)"
SECRETS_FILE="${1:-$ROOT_DIR/.env.local.secrets}"

if [[ ! -f "$SECRETS_FILE" ]]; then
  echo "Local secrets file not found: $SECRETS_FILE" >&2
  echo "Create it from ci/example.env and keep it untracked." >&2
  exit 1
fi

# Check permissive file mode (best effort across macOS/Linux).
MODE=""
if command -v stat >/dev/null 2>&1; then
  if stat -f "%Lp" "$SECRETS_FILE" >/dev/null 2>&1; then
    MODE="$(stat -f "%Lp" "$SECRETS_FILE")"
  elif stat -c "%a" "$SECRETS_FILE" >/dev/null 2>&1; then
    MODE="$(stat -c "%a" "$SECRETS_FILE")"
  fi
fi

if [[ -n "$MODE" ]]; then
  OTHER_PERM="${MODE: -1}"
  if [[ "$OTHER_PERM" != "0" ]]; then
    echo "Warning: $SECRETS_FILE permissions are too open (mode $MODE). Recommend chmod 600." >&2
  fi
fi

set -a
# shellcheck disable=SC1090
source "$SECRETS_FILE"
set +a

LOADED=(
  KH_STRIPE_SECRET_KEY
  KH_STRIPE_WEBHOOK_SECRET
  KHM_ANON_SALT
  KH_SMMA_TEST_MODE
  KH_SMMA_GOLDEN_FIXTURE
  PAID_API_KEY
  PAID_API_SECRET
)

printf 'Loaded local secrets/env keys (values hidden):\n'
for key in "${LOADED[@]}"; do
  if [[ -n "${!key:-}" ]]; then
    printf ' - %s\n' "$key"
  fi
done

echo "Tip: run php scripts/secret_scan.php --strict before commit/push."
