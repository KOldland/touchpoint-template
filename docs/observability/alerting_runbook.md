# CIC Observability and Alerting Runbook (CIC-10)

This runbook covers CIC dashboards, alert rules, escalation actions, and reproduction commands for CIC-09 observability assets.

## Scope

Applies to:
- Golden-check and smoke-harness reliability
- Membership webhook/email/attribution health signals
- Paid adapter and reconciliation health signals
- CIC release orchestration signals

Source assets:
- Dashboards: `observability/dashboards/*.json`
- Alerts: `observability/alerts/*.yaml`
- Alert-fire harness: `scripts/observability/alert_fire_test.php`
- Triage tools: `scripts/extract_golden_diffs.py`, `scripts/ci_triage_report.php`

## Dashboard Inventory

1. CIC Health: `observability/dashboards/cic_health.json`
- golden_check_failure_rate
- golden_check_duration_p90
- golden_check_mismatch_count
- cic_retrial_rate
- cic_flaky_tests_detected

2. Membership Health: `observability/dashboards/membership_health.json`
- membership_attribution_created_total
- webhook_invalid_signature_total
- membership_email_failed_total
- membership_attribution_missing_total

3. Paid/Reconcile Health: `observability/dashboards/paid_reconcile.json`
- paid_adapter_execute_success_total
- paid_adapter_execute_failure_total
- paid_reconciliation_discrepancy_alert_count
- paid_reconciliation_latency_p90_ms

4. Release Health: `observability/dashboards/release_health.json`
- cic_release_started_total
- cic_release_failed_total
- smoke_harness_failure_rate
- feature_flag_rollout_pct for `khm_membership_transactional_emails_enabled`

## Alert Catalog

### P0 alerts (immediate pager)
- `cic_golden_check_failure_rate_p0`
  - condition: `golden_check_failure_rate > 10 for 1h`
- `cic_retrial_rate_p0`
  - condition: `cic_retrial_rate > 50 for 30m`
- `paid_reconciliation_discrepancy_p0`
  - condition: `paid_reconciliation_discrepancy_count > 5 for 1h`

### P1 alerts (Slack/email; escalate to pager after 60m unresolved)
- `cic_golden_check_duration_p1`
  - condition: `golden_check_duration_p90 > 600000 for 1h`
- `membership_email_failed_p1`
  - condition: `membership_email_failed > 5/hr OR membership_email_failed_rate > 1%`
- `webhook_sig_invalid_p1`
  - condition: `webhook_sig_invalid_count > 20 for 15m`
- `membership_attribution_missing_p1`
  - condition: `membership_attribution_missing > 0 for 30m`
- `smoke_harness_failure_rate_p1`
  - condition: `smoke_harness_failure_rate > 5 for 1h`

### P2 alerts (ticket + Slack)
- `cic_flaky_tests_detected_p2`
  - condition: `cic_flaky_tests_detected > 3 for 24h`

## Required Alert Payload Fields

Alert payloads must include:
- `run_id`
- build/PR links (`build_url`, `pr_url`)
- artifact links (`golden-diff.html`, gate artifacts)
- `owner_hint` for responsible area
- `runbook_url`

Do not include secrets or personal data in alert body/payload.

## Escalation Procedure

1. P0:
- Page immediately.
- Acknowledge in incident channel within 10 minutes.
- Attach latest artifact links and triage output.

2. P1:
- Notify Slack/email immediately.
- If unresolved after 60 minutes, escalate to pager.

3. P2:
- Create/assign ticket with owner and ETA.
- Track in weekly CIC reliability review.

## Triage Steps

1. Inspect failure artifacts
- golden diff HTML (`artifacts/golden-fast/golden-diff.html` or deep equivalent)
- gate summaries (`gate-summary.json`)
- flaky report (`flaky-report.json`)

2. Run correlator
```bash
php scripts/ci_triage_report.php \
  --golden-summary artifacts/golden-fast/golden-summary.json \
  --flaky-report artifacts/flaky-report.json \
  --golden-telemetry artifacts/golden-fast/golden-telemetry.json \
  --flaky-telemetry artifacts/flaky-telemetry.json \
  --output artifacts/ci-triage-report.json \
  --markdown artifacts/ci-triage-report.md
```

3. Reproduce mismatch locally
```bash
./scripts/ci_local_env.sh
php scripts/dev_golden_check.php --fixture generate_awareness_ok.json --output artifacts/dev-golden-check
```

4. If flaky behavior suspected
```bash
php scripts/detect_flaky_tests.php \
  --test app/public/wp-content/plugins/kh-smma/tests/Lib/MockLLMClientTest.php \
  --runs 10 \
  --output artifacts/flaky-report.json \
  --telemetry artifacts/flaky-telemetry.json
```

## Alert-Fire Test Procedure

Dry run:
```bash
php scripts/observability/alert_fire_test.php \
  --mode=dry-run \
  --alerts=P0,P1,P2 \
  --output-dir=artifacts/observability/alert-fire
```

Live/emit run (requires webhook endpoint or `OBS_ALERT_TEST_WEBHOOK`):
```bash
php scripts/observability/alert_fire_test.php \
  --mode=live \
  --endpoint=https://<alert-webhook-endpoint> \
  --alerts=P0,P1,P2 \
  --run-id=<run-id> \
  --build-url=<build-url> \
  --pr-url=<pr-url> \
  --output-dir=artifacts/observability/alert-fire
```

Expected evidence files:
- `alert-fire-summary.json`
- `alert-fire-notifications.json`
- `alert-fire-events.json`
- `alert-fire-events.jsonl`
- `alert_fire_run_<run-id>.json`

## CI Self-Test

Workflow:
- `.github/workflows/observability-selftest.yml`

Run manually:
```bash
gh workflow run "CIC Observability Self-Test" --ref chore/cic-08-release-orchestration
```

Monitor run:
```bash
gh run list --workflow "CIC Observability Self-Test" --limit 5
gh run view <run-id> --log
```

## Access Control and Governance

Recommended editors for observability assets:
- `@KOldland` (current repo owner/operator)
- future: `@ci-owner`, `@observability-owner`, `@ops-oncall` once added

Change control:
1. PR required for any dashboard/alert changes.
2. CI must pass (`secret-scan`, golden checks, observability self-test where applicable).
3. Attach alert-fire evidence for rule changes.
4. Require owner review before merge.

## Rollback Actions for Alerting Misconfiguration

If alert noise spikes due to bad thresholds:
1. Disable/rollback affected alert rule file in PR.
2. Re-run alert-fire test in dry-run mode.
3. Re-enable once threshold validation is complete.
