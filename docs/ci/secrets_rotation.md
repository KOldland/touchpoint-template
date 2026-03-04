# Secrets Rotation Runbook

This runbook covers rotation steps for CIC/runtime secrets and required validation.

## Preconditions

- Open an Ops ticket with owner + maintenance window.
- Confirm rollback path exists before changing secrets.
- Ensure `scripts/secret_scan.php --strict` passes before and after rotation PRs.

## 1) Rotate `KHM_ANON_SALT`

Impact:
- This changes deterministic anonymization behavior for newly generated hashes.
- Historical hash correlation may be impacted unless the system keeps legacy salt strategy.

Steps:
1. Generate new random salt in vault.
2. Update runtime injection source (`ops/fetch_secrets.sh` pattern / platform secret store).
3. Deploy to staging first.
4. Run smoke checks for new records and verify expected anonymized output behavior.
5. If required by product policy, execute recomputation job for new windows only.

Validation checklist:
- New writes produce valid `reference_hash` values.
- No runtime errors on anonymization code paths.
- Monitoring shows normal event volumes.

## 2) Rotate `KH_STRIPE_WEBHOOK_SECRET`

Expected side effect:
- Short-term increase in invalid signature events while endpoints/secrets propagate.

Steps:
1. In Stripe dashboard, roll webhook signing secret.
2. Update secret manager value for `KH_STRIPE_WEBHOOK_SECRET`.
3. Deploy/restart services consuming the secret.
4. Trigger staging webhook events and confirm valid processing.
5. Monitor `webhook.invalid_signature` for a temporary spike and return to baseline.

Validation checklist:
- Valid signed webhooks succeed.
- Invalid signatures still reject with `400`.
- No sustained alert breach after propagation window.

## 3) Rotate `KH_STRIPE_SECRET_KEY`

Steps:
1. Create/roll API key in Stripe.
2. Update secret manager value.
3. Run staging API smoke checks.
4. Confirm no payment/auth regressions.

## 4) Rotate LLM Provider Keys

CIC behavior note:
- CIC deterministic tests use MockLLM and should not require real LLM keys.

Steps:
1. Rotate provider key in vault.
2. Confirm CI still runs with `KH_SMMA_TEST_MODE=ci` and no live-key usage in CIC jobs.
3. Run integration jobs (if any) in controlled environment.

## Ops Evidence to Attach

- Secret update ticket link and timestamp.
- Staging smoke run artifact link.
- Alert screenshot/log for expected transient signal (if applicable).
- Confirmation that post-rotation scans pass:

```bash
php scripts/secret_scan.php --strict
php scripts/secret_preflight.php --profile khm-webhooks
```
