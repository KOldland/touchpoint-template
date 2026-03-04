# CIC Quick Commands

## Golden
```bash
./scripts/ci_local_env.sh
php scripts/dev_golden_check.php --fixture generate_awareness_ok.json --output artifacts/dev-golden-check
php scripts/verify_golden_fixtures.php
```

## Diff and triage
```bash
python3 scripts/extract_golden_diffs.py artifacts/golden-fast/golden-diff.zip --out artifacts/golden-fast/golden-diff.html
php scripts/ci_triage_report.php --golden-summary artifacts/golden-fast/golden-summary.json --flaky-report artifacts/flaky-report.json --output artifacts/ci-triage-report.json --markdown artifacts/ci-triage-report.md
```

## Reliability
```bash
php scripts/ci_retry_wrapper.php --step golden-check-deep --attempts 2 --backoff 3 --transient-exit-codes "75,137,143,255" --command "php scripts/golden_check.php --skip-label-check --output artifacts/golden-summary.json"
php scripts/detect_flaky_tests.php --test app/public/wp-content/plugins/kh-smma/tests/Lib/MockLLMClientTest.php --runs 10 --output artifacts/flaky-report.json
```

## Release
```bash
bash scripts/release_deploy.sh --env=staging --tag=release-YYYY-MM-DD --artifact-dir=artifacts/release/staging-dryrun --dry-run
php scripts/release_gate_check.php --env=staging --run-smoke=0 --artifact-dir=artifacts/release/staging-gate-dryrun
php scripts/feature_flag_toggle.php --flag=khm_membership_transactional_emails_enabled --pct=5 --enabled=1 --actor=release-bot --env=canary --artifact-dir=artifacts/release/canary
bash scripts/release_rollback.sh --tag=<last-stable-tag> --reason="emergency_rollback"
```

## Secrets
```bash
php scripts/secret_scan.php --strict
php scripts/secret_preflight.php --profile cic-ci --output artifacts/secret-preflight-ci.json
```

## Observability
```bash
php scripts/observability/alert_fire_test.php --mode=dry-run --alerts=P0,P1,P2 --output-dir=artifacts/observability/alert-fire
php scripts/observability/alert_fire_test.php --mode=live --endpoint=https://<alert-webhook-endpoint> --alerts=P0,P1,P2 --run-id=cic-live-test --output-dir=artifacts/observability/alert-fire
```
