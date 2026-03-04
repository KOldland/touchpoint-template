# CIC Observability and Alerting (CIC-09 Placeholder)

This placeholder captures the implemented CIC-09 observability assets. CIC-10 will finalize operator procedures.

## Dashboards (as code)

- `observability/dashboards/cic_health.json`
- `observability/dashboards/membership_health.json`
- `observability/dashboards/paid_reconcile.json`
- `observability/dashboards/release_health.json`

## Alerts (as code)

- `observability/alerts/golden_check_alerts.yaml`
- `observability/alerts/membership_alerts.yaml`
- `observability/alerts/paid_alerts.yaml`

Severity policy:
- `P0`: immediate pager
- `P1`: Slack + email, escalate to pager after 60 minutes unresolved
- `P2`: ticket + Slack

## Escalation and notification payload requirements

Alert payload must include:
- run/build link
- PR link (if available)
- artifact link (golden diff HTML or gate artifact)
- fixture owner hint
- runbook link
- run id

## Alert-fire test harness

Script:
- `scripts/observability/alert_fire_test.php`

Dry-run example:
```bash
php scripts/observability/alert_fire_test.php \
  --mode=dry-run \
  --alerts=P0,P1,P2 \
  --output-dir=artifacts/observability/alert-fire
```

Emit mode example (webhook sink):
```bash
php scripts/observability/alert_fire_test.php \
  --mode=emit \
  --endpoint=https://example.invalid/webhook \
  --alerts=P0,P1,P2 \
  --output-dir=artifacts/observability/alert-fire
```

Expected evidence artifacts:
- `alert-fire-summary.json`
- `alert-fire-events.json`
- `alert-fire-events.jsonl`
- `alert-fire-notifications.json`

## CI self-test

Workflow:
- `.github/workflows/observability-selftest.yml`

Behavior:
- Runs alert-fire harness in dry-run mode (non-blocking)
- Uploads artifacts for review
- Supports nightly schedule and manual trigger

## Permissions and governance

Only permitted principals may edit observability assets:
- CI owner (`@ci-qa-team`) for CIC dashboard and golden-check alerts
- Observability owner (`@observability-owner`) for escalation routes and notification integrations
- Ops on-call (`@ops-oncall`) for production pager/webhook routing

Change process:
1. Open PR with observability file changes.
2. Attach alert-fire evidence artifact.
3. Get owner approval for changed scope.
4. Merge only with CI green and secret-scan clean.

## Triage helpers

- Diff artifact renderer: `scripts/extract_golden_diffs.py`
- Correlator: `scripts/ci_triage_report.php`

Quick triage command:
```bash
php scripts/ci_triage_report.php \
  --golden-summary artifacts/golden-fast/golden-summary.json \
  --flaky-report artifacts/flaky-report.json \
  --output artifacts/ci-triage-report.json \
  --markdown artifacts/ci-triage-report.md
```
