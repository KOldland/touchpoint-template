# CIC Golden Check

`golden-check` is the deterministic CI gate for fixture parity and fixture governance.

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
