# CIC Golden Check

`golden-check` is the deterministic CI gate for fixture parity and fixture governance.

## What it enforces

1. Governance label gate:
- If a PR changes files under `app/public/wp-content/plugins/kh-smma/tests/fixtures/golden/` or `docs/contracts/`, the PR must include label `golden-owner-approved`.

2. Secret policy:
- `scripts/secret_scan.php` fails if obvious credential patterns are found.

3. Golden parity:
- `scripts/golden_check.php` compares canonical normalized runtime output to committed fixtures.
- Mismatches fail CI and produce unified diffs as artifacts.

## Local run

```bash
# Ensure base ref exists

git fetch origin main

# Governance check (fails when fixture/contract changes exist and label is missing)
php scripts/label_check.php --base origin/main --head HEAD

# Golden parity check (skip label gate for local debug)
php scripts/golden_check.php \
  --base origin/main \
  --head HEAD \
  --skip-label-check \
  --output artifacts/golden-summary.json \
  --diff-dir artifacts/golden-diffs \
  --zip artifacts/golden-diff-local.zip
```

## Troubleshooting

### Label gate fails

- Confirm owner ACKs in PR comments.
- Add label `golden-owner-approved`.
- Re-run CI.

### Golden mismatch

- Open `artifacts/golden-summary.json`.
- Inspect mismatch list and owner hints.
- Open corresponding `*.diff.patch` file in `artifacts/golden-diffs/`.
- Confirm fixture metadata (`prompt_hash`, `prompt_version`, `checksum`) and update via approved fixture workflow.

### Secret scan fails

- Remove secrets/PII from fixtures/scripts/contracts.
- Replace with canonical placeholders.

## Simulate mismatch locally

```bash
KHM_GOLDEN_CHECK_FORCE_MISMATCH=generate_awareness_ok.json \
php scripts/golden_check.php \
  --base origin/main \
  --head HEAD \
  --skip-label-check \
  --output artifacts/golden-summary.json \
  --diff-dir artifacts/golden-diffs \
  --zip artifacts/golden-diff-local.zip
```

## Branch protection setup (Ops)

Set `main` protection to require status checks:

- `golden-check`
- `secret-scan`

Example (GitHub CLI):

```bash
gh api --method PUT repos/<owner>/<repo>/branches/main/protection \
  -H "Accept: application/vnd.github+json" \
  -F required_status_checks[strict]=true \
  -f required_status_checks[contexts][]=golden-check \
  -f required_status_checks[contexts][]=secret-scan \
  -F enforce_admins=false \
  -F required_pull_request_reviews=null \
  -F restrictions=null
```
