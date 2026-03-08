# CIC Smoke Harness Runbook

## Purpose
Run and triage deterministic end-to-end smoke checks used by CIC release and CI pipelines.

## Owner
- Primary: `@ci-qa-team`
- Escalation: `@KOldland`

## Prerequisites
- `KH_SMMA_TEST_MODE=ci`
- Golden fixtures available under `app/public/wp-content/plugins/kh-smma/tests/fixtures/golden/`
- `scripts/smoke_harness.php` present for full harness execution.

## Commands
Check if harness exists:
```bash
if [ -f scripts/smoke_harness.php ]; then echo "smoke harness present"; else echo "smoke harness missing"; fi
```

Run smoke harness (when present):
```bash
KH_SMMA_TEST_MODE=ci php scripts/smoke_harness.php --output artifacts/smoke-fast
```
Expected output example:
```text
Smoke harness completed: success
```

Fallback gate dry-run without smoke (current repository-safe command):
```bash
php scripts/release_gate_check.php --env=staging --run-smoke=0 --artifact-dir=artifacts/staging-gate-dryrun
```
Expected output example:
```text
release gate summary: artifacts/staging-gate-dryrun/gate-summary.json
```

## Artifacts
- `artifacts/smoke-fast/smoke-summary.json`
- `artifacts/smoke-fast/smoke-telemetry.json`
- `artifacts/smoke-fast/smoke-log.txt` (if harness writes logs)
- `artifacts/smoke-fast/smoke-diffs.zip` (on mismatch)

Gate fallback artifact:
- `artifacts/staging-gate-dryrun/gate-summary.json`

## Telemetry
Expected sequence for full harness:
- `generate.request`
- `generate.response`
- `compliance.check`
- `variant.edit`
- `schedule.create`
- `paid_adapter.dry_run`

Look in observability UI:
- Release Health dashboard panel `smoke_harness_failure_rate`

## Failure modes and triage
1. Harness missing:
- Expected result: gate fails when smoke required.
- Action: add `scripts/smoke_harness.php` before production release enforcement.

2. Harness fails deterministic assertions:
```bash
python3 scripts/extract_golden_diffs.py artifacts/smoke-fast/smoke-diffs.zip --out artifacts/smoke-fast/smoke-diff.html
```

3. Correlate with golden/flaky:
```bash
php scripts/ci_triage_report.php \
  --golden-summary artifacts/golden-fast/golden-summary.json \
  --flaky-report artifacts/flaky-report.json \
  --output artifacts/ci-triage-report.json
```

## PM sign-off checklist
- [ ] Smoke harness executed (or missing status explicitly documented).
- [ ] `smoke-summary.json` attached.
- [ ] `smoke-telemetry.json` attached.
- [ ] Any mismatch includes diff artifact + owner assignment.
