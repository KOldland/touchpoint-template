#!/usr/bin/env bash
set -euo pipefail

export KH_SMMA_TEST_MODE="ci"
export CI="true"
export KH_SMMA_GOLDEN_FIXTURE="${KH_SMMA_GOLDEN_FIXTURE:-generate_awareness_ok.json}"

unset OPENAI_API_KEY || true
unset OPENAI_KEY || true
unset ANTHROPIC_API_KEY || true
unset ANTHROPIC_KEY || true
unset DUAL_GPT_API_KEY || true
unset LLM_API_KEY || true

cat <<EOF
Local CI parity environment exported:
- KH_SMMA_TEST_MODE=${KH_SMMA_TEST_MODE}
- CI=${CI}
- KH_SMMA_GOLDEN_FIXTURE=${KH_SMMA_GOLDEN_FIXTURE}

Real LLM key env vars were unset for deterministic test safety.

Next commands:
  php scripts/dev_golden_check.php --fixture "${KH_SMMA_GOLDEN_FIXTURE}" --output artifacts/dev-golden-check
  php scripts/smoke_harness.php --output artifacts/smoke-output
EOF
