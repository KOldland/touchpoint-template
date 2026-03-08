# CIC Reliability Runbook

This runbook covers CIC-06 reliability/monitoring for `golden-check`, `smoke-harness`, and `flaky-detect`.

## Signals and Telemetry

Key events emitted by CIC scripts:

- `cic.golden_check.started`
- `cic.golden_check.completed`
- `cic.golden_check.failure.detail`
- `cic.retrial`
- `cic.flaky_tests.detected`
- `cic.weekly_health.started`
- `cic.weekly_health.alert`
- `cic.weekly_health.completed`
- `cic.secret_scan.passed`
- `cic.secret_scan.failed`

Primary CI artifacts:

- `golden-fast-artifacts` / `golden-deep-artifacts`
- `smoke-fast-artifacts`
- `flaky-report`
- `weekly-health-artifacts`

## Alert Thresholds (Ops Baseline)

- `P0`: `golden_check_failure_rate > 10%` in 1 hour
- `P1`: `golden_check_duration_p90 > 10m`
- `P1`: `smoke_harness_failure_rate > 5%`
- `P2`: flaky-detect non-success count `> 5` in 24h

If your observability platform uses different naming, map these thresholds to equivalent metrics.

## Triage: Golden Check Failure

1. Open workflow run and download `golden-fast-artifacts` (and `golden-deep-artifacts` if present).
2. Open `golden-diff.html`; identify fixture owner from mismatch table.
3. Reproduce locally:

```bash
./scripts/ci_local_env.sh
php scripts/dev_golden_check.php --fixture <fixture>.json --output artifacts/dev-golden-check
```

4. If mismatch is expected behavior change:
- Update contract docs first (`docs/contracts/*`)
- Regenerate fixture safely
- Obtain owner ACK and add `golden-owner-approved` label

5. If mismatch is not expected:
- Treat as regression and assign to fixture owner + CI owner

## Retry Wrapper Usage

Use retries only for deep/non-gating steps.

```bash
php scripts/ci_retry_wrapper.php \
  --step golden-check-deep \
  --attempts 2 \
  --backoff 3 \
  --transient-exit-codes "75,137,143,255" \
  --command "php scripts/golden_check.php --output artifacts/golden-summary.json"
```

Outputs:
- attempt log (`--log`)
- telemetry JSON (`--telemetry`)

## Flaky Test Triage and Quarantine

Run detector locally against suspect test/command:

```bash
php scripts/detect_flaky_tests.php \
  --test app/public/wp-content/plugins/kh-smma/tests/Lib/MockLLMClientTest.php \
  --runs 10 \
  --output artifacts/flaky-report.json \
  --telemetry artifacts/flaky-telemetry.json
```

Interpretation:
- exit `0`: stable pass
- exit `1`: stable fail
- exit `2`: flaky

When flaky (`fail_rate >= 0.2`):
- apply `flake-investigate`
- assign owner from test area
- capture first/last failure traces from report

## Smoke Harness Reliability

`smoke-harness-fast` is treated as a fast signal and should not be auto-retried.

If smoke fails:
1. Inspect smoke artifacts/logs first.
2. Reproduce with deterministic env locally.
3. If infrastructure/transient, escalate to CI owner.
4. If deterministic contract mismatch, route to fixture/consumer owner.

## Alert Acknowledge and Temporary Silence

Only silence with explicit owner and ETA:

- Required fields in acknowledgment: alert code, owner, reason, ETA, rollback plan.
- Maximum silence window: 24h for P1/P2, 1h for P0.
- Link silence record to issue or incident thread.

## Weekly Health Reporting

Workflow: `.github/workflows/cic-weekly-health.yml`

It aggregates last-7-day workflow runs and produces:
- `artifacts/weekly-health-report.json`
- `artifacts/weekly-health-report.md`

If SLA alerts are detected, workflow creates an issue with run/artifact links.
