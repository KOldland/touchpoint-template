# Staging Refresh Handoff

Staging URL:
- `http://touchpoint5stg.wpenginepowered.com`

Target branch / commit:
- `integration/hardening`
- expected live commit for this refresh: `8bb2fba`

Why this handoff exists:
- raw staging probes still return the pre-fix `kh-smma` bootstrap fatal from `Plugin.php:235`
- both bootstrap fixes are already merged:
  - PR `#66` `fix(smma): skip missing reconciliation bootstrap`
  - PR `#67` `fix(smma): restore missing bootstrap classes and normalize API namespaces`
- staging appears to still be serving code from before those merges

Required Ops action:
1. refresh/redeploy staging from current `integration/hardening`
2. confirm the live site serves the new deploy marker endpoint

Post-refresh verification:
```bash
./scripts/phase4_rerun_staging_checks.sh \
  http://touchpoint5stg.wpenginepowered.com \
  artifacts/phase4
```

Expected first proof of fresh deploy:
- `GET /wp-json/kh-smma/v1/version`
- response header contains:
  - `X-KH-SMMA-Version`
  - `X-KH-SMMA-Build: 8bb2fba`
- response JSON contains:
  - `plugin=kh-smma`
  - `build_sha=8bb2fba`
  - `runtime_ok=true`

Expected route outcomes after refresh:
- `/wp-json/kh-membership/v1/landing-success?session_id=cs_test_canary`
  - no fatal `Plugin.php:235`
- `POST /wp-json/kh-smma/v1/generate`
  - no fatal `Plugin.php:235`

Expected next artifacts:
- `artifacts/phase4/curl_smma_version.txt`
- `artifacts/phase4/curl_landing_success.txt`
- `artifacts/phase4/curl_smma_generate.txt`
- `artifacts/phase4/k6_webhook_throughput.log`
- `artifacts/phase4/k6_webhook_throughput.json`
- `artifacts/phase4/k6_canary_smoke.log`
- `artifacts/phase4/k6_canary_smoke.json`
