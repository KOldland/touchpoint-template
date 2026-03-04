# Staging E2E Evidence Template

Use this checklist for a staging run and attach all artifacts to the PR.

## Run metadata
- Date/time (UTC): `<YYYY-MM-DDTHH:MM:SSZ>`
- Environment: `staging`
- Branch/commit: `<branch>` / `<sha>`
- Operator: `<handle>`

## Command log
- Release dry-run command:
```bash
bash scripts/release_deploy.sh --env=staging --tag=<tag> --artifact-dir=artifacts/release/staging-dryrun --dry-run
```
- Gate dry-run command:
```bash
php scripts/release_gate_check.php --env=staging --run-smoke=0 --artifact-dir=artifacts/release/staging-gate-dryrun
```
- Alert-fire dry-run command:
```bash
php scripts/observability/alert_fire_test.php --mode=dry-run --alerts=P0,P1,P2 --output-dir=artifacts/observability/alert-fire
```

## Required artifacts
- [ ] `deploy-summary.json`
- [ ] `gate-summary.json`
- [ ] `golden-summary.json`
- [ ] `golden-diff.html` (if mismatch)
- [ ] `alert-fire-summary.json`
- [ ] `alert_fire_run_<id>.json`
- [ ] `ci-triage-report.json` (if failures)

## DB evidence (staging-safe sample queries)
Record command + output snippet only (no secrets).

Example queries:
```sql
SELECT COUNT(*) AS webhook_events_1h
FROM wp_khm_webhook_events
WHERE created_at >= (NOW() - INTERVAL 1 HOUR);

SELECT event_type, COUNT(*)
FROM wp_khm_webhook_events
WHERE created_at >= (NOW() - INTERVAL 1 HOUR)
GROUP BY event_type;
```

Paste output snippet:
```text
<db output>
```

## Telemetry evidence
Attach screenshot/log snippet for:
- `cic.golden_check.completed`
- `cic.release.started` / `cic.release.completed` or `cic.release.failed`
- `cic.alert_fire_test.completed`

## UI evidence
- [ ] Checkout flow screenshot/video
- [ ] Success/failure UI evidence
- [ ] Accessibility check screenshot/report

## Outcome
- [ ] Ready for frontend sign-off
- [ ] Blockers present

Blockers (if any):
- `<issue> | owner=<handle> | severity=<sev> | ETA=<date>`
