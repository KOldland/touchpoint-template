# CIC Golden-Check Runbook

## Purpose
Operate and triage the deterministic golden-check gate that protects cross-bucket contract parity.

## Owner
- Primary: `@ci-qa-team`
- Escalation: `@KOldland`

## Prerequisites
- `KH_SMMA_TEST_MODE=ci`
- `KH_SMMA_GOLDEN_FIXTURE=generate_awareness_ok.json` (or targeted fixture)
- No live LLM keys in env (`OPENAI_*`, `ANTHROPIC_*`, etc. unset/blank)
- Dependencies installed for `kh-smma` plugin.

## Commands
Fast local parity:
```bash
./scripts/ci_local_env.sh
php scripts/dev_golden_check.php --fixture generate_awareness_ok.json --output artifacts/dev-golden-check
```
Expected output example:
```text
Golden check passed.
```

Direct engine check:
```bash
php scripts/golden_check.php \
  --base origin/main \
  --head HEAD \
  --skip-label-check \
  --fixtures generate_awareness_ok.json,compliance_ok.json \
  --output artifacts/golden-fast/golden-summary.json \
  --diff-dir artifacts/golden-fast/diffs \
  --zip artifacts/golden-fast/golden-diff.zip
```
Expected output example on mismatch:
```text
Golden check failed with 1 mismatch(es).
 - generate_awareness_ok.json (owner: @ci-qa-team)
```

Render readable diffs:
```bash
python3 scripts/extract_golden_diffs.py artifacts/golden-fast/golden-diff.zip --out artifacts/golden-fast/golden-diff.html
```

## Artifacts
- `artifacts/golden-fast/golden-summary.json`
- `artifacts/golden-fast/golden-diff.zip`
- `artifacts/golden-fast/golden-diff.html`
- `artifacts/golden-fast/golden-telemetry.json`

Summary snippet example:
```json
{"result":"failure","mismatches":[{"fixture":"generate_awareness_ok.json","owner":"@ci-qa-team"}]}
```

## Telemetry
- `cic.golden_check.started`
- `cic.golden_check.completed`
- `cic.golden_check.failure.detail`

Look in observability UI:
- CIC Health dashboard panel `golden_check_failure_rate`
- CIC Health dashboard panel `golden_check_duration_p90`

## Failure modes and triage
1. Label/gov failure (`golden-owner-approved` missing):
```bash
php scripts/label_check.php --event "$GITHUB_EVENT_PATH" --base origin/main --head HEAD
```
2. Fixture checksum/meta mismatch:
```bash
php scripts/verify_golden_fixtures.php
```
3. Runtime mismatch reproduction:
```bash
./scripts/ci_local_env.sh
php scripts/dev_golden_check.php --fixture <fixture>.json --output artifacts/dev-golden-check
```
4. Correlate with flaky signals:
```bash
php scripts/ci_triage_report.php \
  --golden-summary artifacts/golden-fast/golden-summary.json \
  --flaky-report artifacts/flaky-report.json \
  --output artifacts/ci-triage-report.json \
  --markdown artifacts/ci-triage-report.md
```

## PM sign-off checklist
- [ ] `golden-check` fast gate green or mismatch triaged with owner.
- [ ] If fixtures/contracts changed, `golden-owner-approved` label present.
- [ ] `golden-diff.html` attached for failed runs.
- [ ] `ci-triage-report.json` attached when mismatch persists.
- [ ] Telemetry events observed in CIC Health dashboard.
