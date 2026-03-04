# STAGING E2E EVIDENCE TEMPLATE

Use this checklist to collect the mandatory artifacts for PM sign-off. Paste the file names and short snippets / links into the PR.

## Basic info
- Branch: <branch>
- Commit: <sha>
- Date/Time: <UTC time>
- Env: staging

---

## 1) Release dry-run
**Command**

./scripts/release_deploy.sh --env=staging --tag=ci-dryrun --dry-run > artifacts/release_dryrun.log 2>&1

**Required artifacts**
- `artifacts/release_dryrun.log`  — include first/last 20 lines
- `artifacts/release_summary.json` (if produced) — paste JSON snippet

**Expected**
- Dry-run completes without fatal errors.  
- Reports planned deploy steps and no secrets in logs.

---

## 2) Membership release gate
**Command**

php mem_release_gate_check.php --env=staging --out=artifacts/mem_gate

If `mem_release_gate_check.php` is not present in this branch, use canonical replacement:

php scripts/release_gate_check.php --env=staging --run-smoke=0 --artifact-dir=artifacts/mem_gate

**Required artifacts**
- `artifacts/mem_gate/golden-summary.json`
- `artifacts/mem_gate/smoke-telemetry.json`
- `artifacts/mem_gate/retention-sanity.json`
- `artifacts/mem_gate/log.txt` (first/last 50 lines)

**Expected**
- golden-check: result=success (or documented mismatch with owner note)
- smoke: no P0 errors
- retention: sanity check passes (no large errors)

---

## 3) Membership email staged rollout (5% demo)
**Commands**

php bin/khm membership-email-control --enable --pct=5 --actor=<you> > artifacts/email_rollout_enable.log

verify audit log

wp db query "SELECT action, data, created_at FROM audit_log WHERE action LIKE 'membership.email.%' ORDER BY created_at DESC LIMIT 5;" > artifacts/email_audit.log

rollback

php bin/khm membership-email-control --disable --actor=<you> > artifacts/email_rollout_disable.log

**Required artifacts**
- `artifacts/email_rollout_enable.log`
- `artifacts/email_audit.log` (show the toggle audit entry)
- `artifacts/email_rollout_disable.log`

**Expected**
- Audit shows toggle with actor + pct.  
- No duplicate emails queued during demo.

---

## 4) Smoke harness (end-to-end)
**Command**

KH_SMMA_TEST_MODE=ci php scripts/smoke_harness.php --fixture=generate_awareness_ok.json --output=artifacts/smoke

**Required artifacts**
- `artifacts/smoke/smoke-telemetry.json`
- `artifacts/smoke/smoke-log.txt`
- `artifacts/smoke/smoke-diffs.zip` (if mismatch)
- `artifacts/smoke/db_before.sql`, `artifacts/smoke/db_after.sql` (small SELECTs showing attribution row)

**Expected**
- `generate.request/response`, `compliance.check`, `schedule.create`, `paid_adapter.dry_run` present in telemetry.  
- Attribution row inserted: example SQL `SELECT id, schedule_id, sponsor_id, consent, reference FROM promotion_attribution WHERE reference = 'cs_test_...' LIMIT 1;` — paste a one-line result.

---

## 5) DSAR / Anonymize proof
**Commands**
DSAR request (example)

curl -X POST -H "Authorization: Bearer <token>" -H "Content-Type: application/json" -d '{"user_id":123, "type":"export"}' https://staging.example.com/wp-json/kh-membership/v1/dsar/request
 > artifacts/dsar_request.json

Anonymize run

php bin/khm anonymize-attribution --id=123 --dry-run > artifacts/anonymize_dry.log
php bin/khm anonymize-attribution --id=123 --execute > artifacts/anonymize_exec.log

before / after select

wp db query "SELECT utm_source, utm_medium, utm_campaign, anonymized_at FROM promotion_attribution WHERE id=123;" > artifacts/anonymize_beforeafter.sql

**Required artifacts**
- `artifacts/dsar_request.json` (show request id)
- `artifacts/anonymize_*` logs
- `artifacts/anonymize_beforeafter.sql` (small before/after)

**Expected**
- Export ZIP available (if requested) or anonymize run shows fields redacted and `anonymized_at` set.

---

## 6) Retention perf
**Commands**

php scripts/retention_perf_simulator.php --rows=100000 --seed=ci --out=artifacts/retention_perf.log

**Required artifacts**
- `artifacts/retention_perf.log` (timings, batch size)
- short summary: total time, avg time/batch, max lock time

**Expected**
- 100k rows processed without long blocking locks; total time documented; batch size configurable.

---

## 7) Observability & Alerts (alert-fire)
**Command**

php scripts/observability/alert_fire_test.php --mode=dry-run --alerts=P0,P1,P2 > artifacts/alert_fire_dry.log

or live

php scripts/observability/alert_fire_test.php --mode=live --alerts=P0,P1,P2 > artifacts/alert_fire_live.log

**Required artifacts**
- `artifacts/alert_fire_*.log` (run output)
- Evidence of notifications: Pager/SMS/Slack screenshots or incident link (paste text if screenshots not possible)
- `artifacts/golden-diff.html` (example)

**Expected**
- Alerts received by notification channels; evidence attached.

---

## 8) Post-release checklist (24h)
Fill `docs/membership/post_release_checklist.md` during the 24h observation window and paste a short summary:
- `membership.attribution.created` normal?  
- `membership.email.failed` ≤ threshold?  
- DLQ growth stable?  
- Any P0 alerts? If none, mark success.

---

## Final sign-off
I (PM) will sign off once the above artifacts are pasted into the PR and the post-release checklist shows no P0s during the observation window.

---
