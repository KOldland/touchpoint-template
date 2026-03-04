#!/usr/bin/env bash
set -euo pipefail

# Template only: inject secrets at runner boot from your secret manager.
# Do not echo secret values in logs.

: "${GITHUB_ENV:?GITHUB_ENV must be set in GitHub Actions environment}"

# Example: Vault CLI usage (replace paths/keys for your environment).
# export VAULT_ADDR="https://vault.example.com"
# export VAULT_TOKEN="${VAULT_TOKEN:?missing VAULT_TOKEN}"
# KH_STRIPE_SECRET_KEY="$(vault kv get -field=kh_stripe_secret_key secret/touchpoint/prod)"
# KH_STRIPE_WEBHOOK_SECRET="$(vault kv get -field=kh_stripe_webhook_secret secret/touchpoint/prod)"
# KHM_ANON_SALT="$(vault kv get -field=khm_anon_salt secret/touchpoint/prod)"

# Example: fallback from existing environment (CI or runner-injected values).
KH_STRIPE_SECRET_KEY="${KH_STRIPE_SECRET_KEY:-}"
KH_STRIPE_WEBHOOK_SECRET="${KH_STRIPE_WEBHOOK_SECRET:-}"
KHM_ANON_SALT="${KHM_ANON_SALT:-}"

if [[ -z "$KH_STRIPE_SECRET_KEY" || -z "$KH_STRIPE_WEBHOOK_SECRET" || -z "$KHM_ANON_SALT" ]]; then
  echo "Missing one or more required secrets for runtime injection." >&2
  echo "Required: KH_STRIPE_SECRET_KEY, KH_STRIPE_WEBHOOK_SECRET, KHM_ANON_SALT" >&2
  exit 1
fi

{
  echo "KH_STRIPE_SECRET_KEY=$KH_STRIPE_SECRET_KEY"
  echo "KH_STRIPE_WEBHOOK_SECRET=$KH_STRIPE_WEBHOOK_SECRET"
  echo "KHM_ANON_SALT=$KHM_ANON_SALT"
} >> "$GITHUB_ENV"

echo "Secrets injected into GITHUB_ENV (values hidden)."
