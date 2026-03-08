# CIC Developer Quickstart

## 1. Bootstrap deterministic local env

```bash
./scripts/ci_local_env.sh
```

Optional local secrets (untracked file):

```bash
cp ci/example.env .env.local.secrets
chmod 600 .env.local.secrets
./scripts/load_local_secrets.sh
```

## 2. Run local golden-check

```bash
php scripts/dev_golden_check.php --fixture generate_awareness_ok.json --output artifacts/dev-golden-check
```

## 3. Run smoke harness locally

```bash
# Requires CIC-04 smoke harness files in branch/main.
php scripts/smoke_harness.php --output artifacts/smoke-output
```

## 4. Regenerate fixture preview (safe, no auto-commit)

```bash
php scripts/regenerate_fixture_ui.php \
  --input recorded_response.json \
  --fixture-name generate_awareness_ok.json \
  --author @your-handle \
  --prompt-version cic-05
```

Output is written into `tmp/golden-preview/<timestamp>_*`.

## 5. Run flaky detector

```bash
php scripts/detect_flaky_tests.php --test app/public/wp-content/plugins/kh-smma/tests/Lib/MockLLMClientTest.php --runs 10
```

Exit codes:
- `0` stable pass
- `1` stable fail
- `2` flaky

## 5b. Retry a transient CI step locally

```bash
php scripts/ci_retry_wrapper.php \
  --step golden-check-deep \
  --attempts 2 \
  --backoff 2 \
  --transient-exit-codes "75,137,143,255" \
  --command "php scripts/golden_check.php --output artifacts/golden-summary.json --diff-dir artifacts/golden-diffs --zip artifacts/golden-diff.zip"
```

## 6. Render diff artifact HTML

```bash
python3 scripts/extract_golden_diffs.py artifacts/golden-diff.zip --out artifacts/golden-diff.html
```

## 7. Docker Compose parity stack

```bash
docker compose -f ci/dev-compose.yml up --build -d

docker compose -f ci/dev-compose.yml exec php bash -lc \
  "cd /workspace && php scripts/smoke_harness.php --output artifacts/smoke-output"
```

## 8. Install optional local pre-commit secret hook

```bash
./tools/install_hooks.sh
```

This installs both `pre-commit` and `pre-push` secret scan hooks.

To run scan manually:

```bash
php scripts/secret_scan.php --strict
```

## 9. Build combined CI triage report

```bash
php scripts/ci_triage_report.php \
  --golden-summary artifacts/golden-summary.json \
  --flaky-report artifacts/flaky-report.json \
  --output artifacts/ci-triage-report.json \
  --markdown artifacts/ci-triage-report.md
```

## Common golden-check failures

- Prompt mismatch:
  - Recompute hash: `php scripts/compute_prompt_hash.php --file docs/contracts/prompts/<prompt>.txt`
- Placeholder mismatch:
  - Ensure volatile IDs/timestamps are canonical placeholders or normalized in test pipeline.
- Missing governance label:
  - Add `golden-owner-approved` after fixture owner ACK.
