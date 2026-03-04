# CIC Secrets Runbook

## Purpose
Define canonical secret names, storage locations, and safe usage for CIC pipelines and release scripts.

## Owner
- Primary: `@KOldland`
- Ops secret management owner: `@ops-oncall`

## Canonical secret names
Runtime secrets:
- `KH_STRIPE_SECRET_KEY`
- `KH_STRIPE_WEBHOOK_SECRET`
- `KHM_ANON_SALT`
- `PAID_API_KEY`
- `PAID_API_SECRET`

CI aliases:
- `STRIPE_TEST_KEY` -> mapped to `KH_STRIPE_SECRET_KEY`
- `STRIPE_WEBHOOK_SECRET` -> mapped to `KH_STRIPE_WEBHOOK_SECRET`

Non-secret CIC controls:
- `KH_SMMA_TEST_MODE`
- `KH_SMMA_GOLDEN_FIXTURE`

## Storage policy
- GitHub Actions secrets for CI/test values.
- Ops vault/platform secret manager for staging/production values.
- Never store secret values in repo files, fixtures, or screenshots.

## Prerequisites
- Secret values configured in GitHub repository settings and/or Ops vault.
- `scripts/secret_scan.php` and `scripts/secret_preflight.php` available.

## Commands
Local scan (strict):
```bash
php scripts/secret_scan.php --strict
```

Changed-files scan (fast):
```bash
php scripts/secret_scan.php --strict --changed
```

CI preflight example:
```bash
php scripts/secret_preflight.php --profile cic-ci --output artifacts/secret-preflight-ci.json
```

## Artifacts
- `artifacts/secret-scan-*.json`
- `artifacts/secret-scan-*-telemetry.json`
- `artifacts/secret-preflight-ci.json`

Telemetry:
- `cic.secret_scan.passed`
- `cic.secret_scan.failed`

## Failure modes and triage
1. Secret scan finding:
- Remove or replace literal with env lookup.
- Re-run strict scan before commit.

2. Preflight missing secret:
- Add required secret in GH Actions or vault.
- Re-run preflight and attach updated artifact.

3. False-positive entropy detection:
- Rename ambiguous token-like literals and re-scan.

## PM sign-off checklist
- [ ] All required secret names documented (without values).
- [ ] CI secret-scan artifact attached and clean.
- [ ] Preflight artifact attached for CIC workflow profile.
- [ ] No runbook contains real secret values.
