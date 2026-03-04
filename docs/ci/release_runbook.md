# CIC Release Runbook

CIC-08 provides staged release orchestration: `staging -> canary -> production` with deterministic gates and rollback.

## Preconditions

- PR approvals complete.
- Required checks green: golden-check, secret-scan, secret-preflight.
- Branch protection enforces release policy.
- Ops contacts and on-call path confirmed.

## Workflow

Workflow: `.github/workflows/release.yml`

Triggers:
- `workflow_dispatch` (manual)
- tag push `release/**`

Stages:
1. Staging deploy
2. Staging gate checks (golden + smoke)
3. Canary flag rollout
4. Canary gate checks + observation window
5. Production deploy + full enable
6. Automatic rollback on canary/prod failure

## Required Environment Variables / Commands

Real deploy/rollback commands are injected via environment/runner:

- `RELEASE_DEPLOY_CMD_STAGING`
- `RELEASE_DEPLOY_CMD_CANARY`
- `RELEASE_DEPLOY_CMD_PROD`
- `RELEASE_ROLLBACK_CMD`

Optional:
- `RELEASE_MEM_E2E_CMD`
- `RELEASE_A11Y_CMD`

Command templates support placeholders:
- `{TAG}` release tag
- `{ENV}` target environment

## Manual Commands (local/ops)

Dry-run preview:

```bash
bash scripts/release_deploy.sh --env=staging --tag=release-2026-03-xx --dry-run
```

Staging deploy:

```bash
bash scripts/release_deploy.sh --env=staging --tag=release-2026-03-xx
```

Run release gate checks:

```bash
php scripts/release_gate_check.php --env=staging --artifact-dir=artifacts/staging-check
```

Promote canary flag:

```bash
php scripts/feature_flag_toggle.php --flag=khm_membership_transactional_emails_enabled --pct=5 --enabled=1 --actor=release-bot
```

Rollback:

```bash
bash scripts/release_rollback.sh --tag=previous-tag --reason="canary_failure"
```

## Health Gates

Release gate script checks:
- fast golden parity (`scripts/golden_check.php`)
- smoke harness (if present; otherwise marked skipped when allowed)
- synthetic failure toggle for rollback drills

## Rollback Policy

Rollback triggers automatically when canary or production stage fails.

Rollback actions:
1. Turn off flag and set rollout to `0%`.
2. Execute rollback command (`RELEASE_ROLLBACK_CMD`) to previous tag.
3. Emit `cic.release.failed` event and publish artifacts.

## Artifacts

Expected artifacts include:
- `artifacts/release-summary.json`
- staging/canary/prod deploy summaries
- gate summaries (`golden`, `smoke` references)
- rollback summary (when triggered)
- release event telemetry (`cic.release.started`, `cic.release.completed`, `cic.release.failed`)

## Access Control

- Limit who can run `workflow_dispatch` (repo Actions permissions + environment protection).
- Use protected environments (`staging`, `canary`, `production`) with required reviewers.

## Incident Notes

If release fails:
1. Inspect `release-summary.json` and gate summaries.
2. Verify rollback summary completed successfully.
3. Keep feature flag at `0%` until root cause is resolved.
4. Open incident ticket with run URL + artifacts.
