# CIC Smoke Harness

`scripts/smoke_harness.php` is the deterministic end-to-end CIC integration gate for:

`generate -> compliance -> variant-edit -> schedule -> paid dry_run`.

It runs with `KH_SMMA_TEST_MODE=ci`, `MockLLMClient`, and test stubs only. No external APIs are called.

## Local run

```bash
# Install plugin test dependencies first
cd app/public/wp-content/plugins/kh-smma
composer install --prefer-dist --no-progress --no-interaction
cd ../../../../..

# Run harness
KH_SMMA_TEST_MODE=ci \
KH_SMMA_GOLDEN_FIXTURE=generate_awareness_ok.json \
php scripts/smoke_harness.php --output artifacts/smoke-output
```

## Artifacts

- `artifacts/smoke-output/smoke-summary.json`
- `artifacts/smoke-output/smoke-telemetry.json`
- `artifacts/smoke-output/smoke-log.txt`
- `artifacts/smoke-output/smoke-diffs.zip`

Telemetry event order must be:

1. `generate.request`
2. `generate.response`
3. `compliance.check`
4. `variant.edit`
5. `schedule.create`
6. `paid_adapter.dry_run`

## Mismatch debugging

1. Open `smoke-summary.json` and inspect `mismatches`.
2. Extract `smoke-diffs.zip` and inspect `*.diff.patch`.
3. Verify fixture contracts in:
   - `app/public/wp-content/plugins/kh-smma/tests/fixtures/golden/generate_awareness_ok.json`
   - `app/public/wp-content/plugins/kh-smma/tests/fixtures/golden/compliance_ok.json`
   - `app/public/wp-content/plugins/kh-smma/tests/fixtures/golden/paid_adapter_dry_run_manifest.json`

## Force a local mismatch

```bash
KHM_SMOKE_FORCE_MISMATCH=generate.response \
php scripts/smoke_harness.php --output artifacts/smoke-output
```

This should fail non-zero and include unified diffs in `smoke-diffs.zip`.
