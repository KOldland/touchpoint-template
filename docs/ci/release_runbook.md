# CIC Release Runbook

## Immediate Rollback (first response)

```bash
bash scripts/release_rollback.sh --tag=<last-stable-tag> --reason="emergency_rollback"
```

Expected rollback artifacts:
- `artifacts/release/rollback/feature-flag-toggle-khm_membership_transactional_emails_enabled.json`
- `artifacts/release/rollback/feature-flag-audit.jsonl`
- `artifacts/release/rollback/rollback-summary.json`

## Purpose
Execute safe staged release orchestration: dry-run -> staging -> canary -> full enable -> rollback if needed.

## Owner
- Primary: `@KOldland`
- Release operations escalation: `@ops-oncall`

## Prerequisites
- Workflow file: `.github/workflows/release.yml`
- Required checks green: `golden-check`, `secret-scan`, `secret-preflight`
- Feature flag default in production: `khm_membership_transactional_emails_enabled=0`
- Release command env vars configured in runner:
  - `RELEASE_DEPLOY_CMD_STAGING`
  - `RELEASE_DEPLOY_CMD_CANARY`
  - `RELEASE_DEPLOY_CMD_PROD`
  - `RELEASE_ROLLBACK_CMD`

Note: PM brief may reference `mem_release_gate_check.php`; canonical committed command is `php scripts/release_gate_check.php`.

## Commands
Dry-run deploy preview:
```bash
bash scripts/release_deploy.sh --env=staging --tag=release-YYYY-MM-DD --artifact-dir=artifacts/release/staging-dryrun --dry-run
```
Example output:
```text
[release_deploy] dry-run
 env: staging
 tag: release-YYYY-MM-DD
```

Staging gate dry-run (current repo-safe validation):
```bash
php scripts/release_gate_check.php --env=staging --run-smoke=0 --artifact-dir=artifacts/release/staging-gate-dryrun
```

Canary toggle:
```bash
php scripts/feature_flag_toggle.php \
  --flag=khm_membership_transactional_emails_enabled \
  --pct=5 \
  --enabled=1 \
  --actor=release-bot \
  --env=canary \
  --artifact-dir=artifacts/release/canary
```

Manual rollback:
```bash
bash scripts/release_rollback.sh --tag=<last-stable-tag> --reason="canary_failure"
```

## Release stages
1. Dry-run preview
2. Staging deploy
3. Staging gate checks
4. Canary 5% enable
5. Monitoring window (60-120 min)
6. Full production enable
7. Rollback on failure

## Artifacts
- Deploy: `artifacts/release/<env>/deploy-summary.json`
- Gate: `artifacts/release/<env>/gate/gate-summary.json`
- Flag: `artifacts/release/<env>/feature-flag-toggle-*.json`
- Release summary: `artifacts/release-summary.json`
- Rollback: `artifacts/release/rollback/rollback-summary.json`

Sample snippet (redacted command policy):
```json
{"command_redacted":true,"command_source_env":"RELEASE_DEPLOY_CMD_STAGING"}
```

## Telemetry
- `cic.release.started`
- `cic.release.completed`
- `cic.release.failed`
- `cic.feature_flag.toggle`

Look in observability UI:
- Release Health dashboard panel `cic_release_failed`
- Release Health dashboard panel `smoke_harness_failure_rate`

## Failure modes and triage
1. Gate fails due to missing smoke harness:
- Expected in strict mode when `scripts/smoke_harness.php` is absent.
- Use `--run-smoke=0` for dry-run validation only; do not bypass in production rollout.

2. Canary alert breach:
- Roll back immediately using command at top of runbook.
- Attach gate summary + alert evidence.

3. Deploy command missing in non-dry-run:
- Script fails safely with explicit message.
- Configure missing `RELEASE_DEPLOY_CMD_*` variable and rerun.

## PM sign-off checklist
- [ ] Dry-run deploy artifact attached.
- [ ] Staging gate artifact attached.
- [ ] Canary toggle artifact attached.
- [ ] Rollback command and artifacts validated.
- [ ] Release telemetry events visible in dashboard.
