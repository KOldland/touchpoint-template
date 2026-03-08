# CIC Reliability Runbook

## Purpose
Operate reliability tooling for CIC checks: retry wrapper, flaky detection, weekly health, and correlated triage.

## Owner
- Primary: `@ci-qa-team`
- Escalation: `@observability-owner`

## Prerequisites
- PHP 8.1+
- `KH_SMMA_TEST_MODE=ci`
- CI artifacts available from latest run for golden/flaky reports

## Commands
Retry wrapper (for deep/non-gating jobs only):
```bash
php scripts/ci_retry_wrapper.php \
  --step golden-check-deep \
  --attempts 2 \
  --backoff 3 \
  --transient-exit-codes "75,137,143,255" \
  --log artifacts/retry-golden.log \
  --telemetry artifacts/retry-golden-telemetry.json \
  --command "php scripts/golden_check.php --skip-label-check --output artifacts/golden-summary.json"
```
Example output:
```text
[retry] step=golden-check-deep attempt=1 exit=75
[retry] step=golden-check-deep attempt=2 exit=0
```

Flaky detection:
```bash
php scripts/detect_flaky_tests.php \
  --test app/public/wp-content/plugins/kh-smma/tests/Lib/MockLLMClientTest.php \
  --runs 10 \
  --output artifacts/flaky-report.json \
  --telemetry artifacts/flaky-telemetry.json \
  --label-signal artifacts/flaky-label-signal.json
```
Example output:
```text
classification: flaky
fail_rate: 0.3
```

Correlated triage:
```bash
php scripts/ci_triage_report.php \
  --golden-summary artifacts/golden-fast/golden-summary.json \
  --flaky-report artifacts/flaky-report.json \
  --golden-telemetry artifacts/golden-fast/golden-telemetry.json \
  --flaky-telemetry artifacts/flaky-telemetry.json \
  --output artifacts/ci-triage-report.json \
  --markdown artifacts/ci-triage-report.md
```

## Artifacts
- `artifacts/retry-golden.log`
- `artifacts/retry-golden-telemetry.json`
- `artifacts/flaky-report.json`
- `artifacts/flaky-telemetry.json`
- `artifacts/flaky-label-signal.json`
- `artifacts/ci-triage-report.json`

## Telemetry
- `cic.retrial`
- `cic.flaky_tests.detected`
- `cic.weekly_health.started`
- `cic.weekly_health.alert`
- `cic.weekly_health.completed`

Look in observability UI:
- CIC Health dashboard panels `cic_retrial_rate` and `cic_flaky_tests_detected`

## Failure modes and triage
1. Retries exhausted:
- Treat as infrastructure/platform issue if transient exit codes repeat.
- Attach retry log + telemetry to incident.

2. Stable fail (exit `1`) from flaky detector:
- This is not flaky; open regression bug immediately.

3. Flaky classification (exit `2`):
- Apply `flake-investigate` workflow label.
- Assign owner and ETA.

4. Mixed golden + flaky signal:
- Prioritize deterministic golden mismatch first.
- Use `ci-triage-report.json` probable cause + alert candidates.

## PM sign-off checklist
- [ ] Retry telemetry attached for deep checks.
- [ ] Flaky report attached for suspect tests.
- [ ] Correlated triage report attached when multiple signals fire.
- [ ] Weekly health artifact reviewed for open SLA alerts.
