# CIC Observability and Alerting Runbook

## Purpose
Operate CIC dashboards, alerts, and escalation flows; run alert-fire tests and collect evidence for PM sign-off.

## Owner
- Primary: `@observability-owner`
- CIC implementation owner: `@KOldland`
- Ops escalation owner: `@ops-oncall`

## Prerequisites
- Observability platform access to import dashboard JSON and alert YAML.
- Notification integrations configured (PagerDuty/Slack/Email/Ticket webhook).
- `scripts/observability/alert_fire_test.php` available.

## Dashboards (as code)
- `observability/dashboards/cic_health.json`
- `observability/dashboards/membership_health.json`
- `observability/dashboards/paid_reconcile.json`
- `observability/dashboards/release_health.json`

## Alerts (as code)
- `observability/alerts/golden_check_alerts.yaml`
- `observability/alerts/membership_alerts.yaml`
- `observability/alerts/paid_alerts.yaml`

## Severity and escalation
- `P0`: immediate pager.
- `P1`: Slack+email, escalate to pager after 60 minutes unresolved.
- `P2`: create ticket + Slack.

## Required payload fields
- `run_id`
- `build_url`
- `pr_url`
- `owner_hint`
- `runbook_url`
- `artifact_url` (golden diff or gate artifact)

Payloads must not include secret values or PII.

## Commands
Dry-run alert fire test:
```bash
php scripts/observability/alert_fire_test.php \
  --mode=dry-run \
  --alerts=P0,P1,P2 \
  --run-id=cic-observability-dryrun \
  --output-dir=artifacts/observability/alert-fire
```

Live alert fire test:
```bash
php scripts/observability/alert_fire_test.php \
  --mode=live \
  --endpoint=https://<alert-webhook-endpoint> \
  --alerts=P0,P1,P2 \
  --run-id=cic-observability-live \
  --build-url=https://github.com/<org>/<repo>/actions/runs/<run-id> \
  --pr-url=https://github.com/<org>/<repo>/pull/<pr> \
  --output-dir=artifacts/observability/alert-fire
```

Expected output example:
```text
alert fire summary: artifacts/observability/alert-fire/alert-fire-summary.json
alerts triggered: cic_golden_check_failure_rate_p0, cic_golden_check_duration_p1, cic_flaky_tests_detected_p2
```

## Artifacts
- `alert-fire-summary.json`
- `alert-fire-notifications.json`
- `alert-fire-events.json`
- `alert-fire-events.jsonl`
- `alert_fire_run_<run-id>.json`

## Triage commands
Render golden diff HTML:
```bash
python3 scripts/extract_golden_diffs.py artifacts/golden-fast/golden-diff.zip --out artifacts/golden-fast/golden-diff.html
```

Correlate failures:
```bash
php scripts/ci_triage_report.php \
  --golden-summary artifacts/golden-fast/golden-summary.json \
  --flaky-report artifacts/flaky-report.json \
  --golden-telemetry artifacts/golden-fast/golden-telemetry.json \
  --flaky-telemetry artifacts/flaky-telemetry.json \
  --output artifacts/ci-triage-report.json \
  --markdown artifacts/ci-triage-report.md
```

## Failure modes
1. Live alert test fails delivery:
- Check webhook endpoint and network access.
- Confirm integration auth/secret in platform.

2. Alerts fire but no escalation:
- Verify escalation policy mapping by severity.
- Validate pager/email/ticket connectors.

3. Excess alert noise:
- Tune thresholds in alert YAML and re-test in dry-run first.

## PM sign-off checklist
- [ ] All four dashboards deployed and URLs/screenshots attached.
- [ ] P0/P1/P2 alert rules enabled and thresholds match YAML.
- [ ] Live alert-fire evidence attached (`alert_fire_run_<run-id>.json` + channel proofs).
- [ ] Triage artifacts attached (`golden-diff.html`, `ci-triage-report.json`).
- [ ] Confirmed no secret/PII in alert payloads.
