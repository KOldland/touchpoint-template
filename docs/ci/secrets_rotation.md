# CIC Secrets Rotation Runbook

## Purpose
Provide safe, repeatable rotation steps for CIC-impacting secrets.

## Owner
- Primary: `@ops-oncall`
- CIC validation owner: `@KOldland`

## Prerequisites
- Change ticket opened with owner + ETA.
- Rollback plan documented.
- Dry-run validation window scheduled.

## Rotation: `KHM_ANON_SALT`
Steps:
1. Generate new salt in vault.
2. Update runtime secret injection.
3. Deploy to staging.
4. Verify new records hash correctly.

Validation commands:
```bash
php scripts/secret_scan.php --strict
php scripts/secret_preflight.php --profile cic-ci --output artifacts/secret-preflight-ci.json
```

## Rotation: `KH_STRIPE_WEBHOOK_SECRET`
Steps:
1. Rotate webhook secret in Stripe dashboard.
2. Update vault/GH secret.
3. Redeploy webhook consumers.
4. Trigger staging webhook test events.

Expected transient effect:
- temporary rise in invalid-signature events during propagation.

## Rotation: `KH_STRIPE_SECRET_KEY`
Steps:
1. Rotate Stripe API key.
2. Update secure store.
3. Run staging payment API smoke checks.

## Rotation: LLM keys
CIC note:
- CIC deterministic checks do not require live LLM keys.

Steps:
1. Rotate provider key in vault.
2. Confirm CIC jobs still run with `KH_SMMA_TEST_MODE=ci`.
3. Confirm no secret appears in artifacts/logs.

## Artifacts to attach
- Rotation ticket link.
- Time and actor.
- Post-rotation preflight artifact.
- Staging smoke evidence.
- Observability screenshot/log showing return to baseline.

## Failure modes and triage
1. Persistent invalid signature spike:
- Confirm webhook secret sync in all environments.
- Temporarily pause rollout and revert to known-good secret if needed.

2. API auth failures after key rotation:
- Validate key scope and environment mapping.
- Roll back key update while investigating.

## PM sign-off checklist
- [ ] Rotation steps completed in staging first.
- [ ] Post-rotation scans and preflight clean.
- [ ] Expected transient telemetry behavior observed and resolved.
- [ ] Evidence attached with timestamps and owner.
