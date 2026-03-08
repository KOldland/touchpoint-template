#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${1:-http://touchpoint5stg.wpenginepowered.com}"
ARTIFACT_DIR="${2:-artifacts/phase4}"

mkdir -p "$ARTIFACT_DIR"

echo "[phase4] probing deploy marker from ${BASE_URL}"
curl -i -sS "${BASE_URL}/wp-json/kh-smma/v1/version" > "${ARTIFACT_DIR}/curl_smma_version.txt"

echo "[phase4] probing landing-success"
curl -i -sS "${BASE_URL}/wp-json/kh-membership/v1/landing-success?session_id=cs_test_canary" > "${ARTIFACT_DIR}/curl_landing_success.txt"

echo "[phase4] probing smma generate"
curl -i -sS -X POST "${BASE_URL}/wp-json/kh-smma/v1/generate" \
  -H 'Content-Type: application/json' \
  --data '{"post_id":1,"blocks_summary":"canary smoke","num_variants":1}' \
  > "${ARTIFACT_DIR}/curl_smma_generate.txt"

echo "[phase4] running k6 webhook throughput"
K6_WEBHOOK_BASE_URL="${BASE_URL}" K6_VUS="${K6_VUS:-50}" K6_DURATION="${K6_DURATION:-2m}" \
  k6 run tests/perf/k6/webhook_throughput.js \
  --out "json=${ARTIFACT_DIR}/k6_webhook_throughput.json" \
  > "${ARTIFACT_DIR}/k6_webhook_throughput.log" 2>&1 || true

echo "[phase4] running k6 canary smoke"
CANARY_BASE_URL="${BASE_URL}" \
  k6 run tests/perf/k6/canary_smoke.js \
  --out "json=${ARTIFACT_DIR}/k6_canary_smoke.json" \
  > "${ARTIFACT_DIR}/k6_canary_smoke.log" 2>&1 || true

echo "[phase4] artifacts written to ${ARTIFACT_DIR}"
