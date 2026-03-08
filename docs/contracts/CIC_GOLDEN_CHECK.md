# CIC Golden Check

`golden-check` is the deterministic CI gate for fixture parity and fixture governance.

## CI execution model

- `golden-check` (fast gate): deterministic core fixtures, no automatic retries, merge-blocking.
- `golden-check-deep`: full fixture coverage, retryable via `scripts/ci_retry_wrapper.php`, non-blocking severity for triage.
- `smoke-harness-fast`: deterministic smoke signal when smoke harness script exists.

## Local run (developer wrapper)

```bash
./scripts/ci_local_env.sh
php scripts/dev_golden_check.php --fixture generate_awareness_ok.json --output artifacts/dev-golden-check
```

## CI parity run (direct engine)

```bash
php scripts/golden_check.php \
  --base origin/main \
  --head HEAD \
  --skip-label-check \
  --output artifacts/golden-summary.json \
  --diff-dir artifacts/golden-diffs \
  --zip artifacts/golden-diff.zip
```

## Deep run with retry wrapper

```bash
php scripts/ci_retry_wrapper.php \
  --step golden-check-deep \
  --attempts 2 \
  --backoff 3 \
  --transient-exit-codes "75,137,143,255" \
  --command "php scripts/golden_check.php --output artifacts/golden-summary.json --diff-dir artifacts/golden-diffs --zip artifacts/golden-diff.zip"
```

## Governance

- If files under `app/public/wp-content/plugins/kh-smma/tests/fixtures/golden/` or `docs/contracts/` change, PRs must include label `golden-owner-approved`.
- `scripts/label_check.php` enforces this in CI.

## Diff inspection

```bash
python3 scripts/extract_golden_diffs.py artifacts/golden-diff.zip --out artifacts/golden-diff.html
```

Open `artifacts/golden-diff.html` for readable patch review.

## Troubleshooting

1. Run `php scripts/verify_golden_fixtures.php` first.
2. Ensure `KH_SMMA_TEST_MODE=ci` and real LLM keys are unset.
3. Check fixture `.meta.json` (`prompt_hash`, `prompt_version`, `checksum`).
4. If a fixture update is expected, regenerate in temp:

```bash
php scripts/regenerate_fixture_ui.php --input recorded.json --fixture-name generate_awareness_ok.json
```

The tool writes preview output under `tmp/golden-preview/*` and does not auto-commit.

5. Build a correlated triage report from golden + flaky outputs:

```bash
php scripts/ci_triage_report.php \
  --golden-summary artifacts/golden-summary.json \
  --flaky-report artifacts/flaky-report.json \
  --output artifacts/ci-triage-report.json \
  --markdown artifacts/ci-triage-report.md
```
