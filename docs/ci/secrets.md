# CIC Secrets and Key Management

This document defines canonical secret names and where they must be provisioned.

## Policy

- Never commit secrets, API keys, webhook secrets, salts, or vault tokens to the repository.
- Never place secrets in golden fixtures, runbook screenshots, CI logs, or PR comments.
- Use environment injection only (GitHub Actions secrets and/or Ops vault).

## Canonical Secret Names

CIC and runtime names used across workflows/scripts:

- `KH_SMMA_TEST_MODE` (non-secret, expected `ci` for deterministic tests)
- `KH_SMMA_GOLDEN_FIXTURE` (non-secret fixture selector)
- `KH_STRIPE_SECRET_KEY` (secret)
- `KH_STRIPE_WEBHOOK_SECRET` (secret)
- `KHM_ANON_SALT` (secret)
- `PAID_API_KEY` (secret)
- `PAID_API_SECRET` (secret)

CI-only secret aliases (GitHub Actions):

- `STRIPE_TEST_KEY` (mapped to `KH_STRIPE_SECRET_KEY` in CI)
- `STRIPE_WEBHOOK_SECRET` (mapped to `KH_STRIPE_WEBHOOK_SECRET` in CI)

## Where to Set Secrets

1. GitHub Actions repository secrets
- Use: Settings -> Secrets and variables -> Actions.
- Minimum for webhook CI: `STRIPE_TEST_KEY`, `STRIPE_WEBHOOK_SECRET`.
- Optional runtime preflight examples: `KH_STRIPE_SECRET_KEY`, `KH_STRIPE_WEBHOOK_SECRET`, `KHM_ANON_SALT`.

2. Ops vault / platform secret manager
- Preferred for production/runtime secrets.
- Inject during deploy/runner boot (template: `ops/fetch_secrets.sh`).

## CI Injection Pattern

Example env mapping in workflows:

```yaml
env:
  KH_STRIPE_SECRET_KEY: ${{ secrets.STRIPE_TEST_KEY }}
  KH_STRIPE_WEBHOOK_SECRET: ${{ secrets.STRIPE_WEBHOOK_SECRET }}
```

Then validate with preflight:

```bash
php scripts/secret_preflight.php --profile khm-webhooks --output artifacts/secret-preflight.json
```

## Local Development

1. Copy placeholders:

```bash
cp ci/example.env .env.local.secrets
chmod 600 .env.local.secrets
```

2. Load local values (untracked file only):

```bash
./scripts/load_local_secrets.sh
```

3. Scan before commit/push:

```bash
php scripts/secret_scan.php --strict
```

## CI Secret Scan Modes

Fast local/PR mode (changed files):

```bash
php scripts/secret_scan.php --changed --strict
```

Full mode (entire repository):

```bash
php scripts/secret_scan.php --strict --output artifacts/secret-scan-findings.json --telemetry artifacts/secret-scan-telemetry.json
```

Telemetry events emitted:

- `cic.secret_scan.passed`
- `cic.secret_scan.failed`

## Branch Protection Guidance

Ops should mark these checks as required for protected branches:

- `secret-scan-local`
- `secret-preflight-ci`
- `secret-scan-full`

If the repository uses the consolidated CIC workflow, ensure its `secret-scan` job is also required.
