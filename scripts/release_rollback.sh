#!/usr/bin/env bash
set -euo pipefail

usage() {
  cat <<'EOF'
Usage: scripts/release_rollback.sh --tag=<previous-tag> --reason=<text> [--artifact-dir=<dir>] [--actor=<name>] [--dry-run]

Behavior:
- Toggles transactional email flag off and rollout pct=0 via feature_flag_toggle.php
- Executes rollback command template if configured:
  RELEASE_ROLLBACK_CMD  (supports {TAG})
EOF
}

TAG=""
REASON=""
ARTIFACT_DIR="artifacts/release/rollback"
ACTOR="release-bot"
DRY_RUN=0

for arg in "$@"; do
  case "$arg" in
    --tag=*) TAG="${arg#*=}" ;;
    --reason=*) REASON="${arg#*=}" ;;
    --artifact-dir=*) ARTIFACT_DIR="${arg#*=}" ;;
    --actor=*) ACTOR="${arg#*=}" ;;
    --dry-run) DRY_RUN=1 ;;
    --help|-h) usage; exit 0 ;;
    *) echo "Unknown argument: $arg" >&2; usage; exit 2 ;;
  esac
done

if [[ -z "$TAG" || -z "$REASON" ]]; then
  echo "--tag and --reason are required." >&2
  usage
  exit 2
fi

mkdir -p "$ARTIFACT_DIR"
RUN_ID="${GITHUB_RUN_ID:-local}"
STARTED_AT="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
RESULT="success"
DEPLOY_REVERTED=false
ROLLBACK_COMMAND_EXECUTED=0

DRY_ARG=()
if [[ "$DRY_RUN" -eq 1 ]]; then
  DRY_ARG+=(--dry-run)
fi

# Always attempt fast rollback of flags first.
php scripts/feature_flag_toggle.php --flag=khm_membership_transactional_emails_enabled --enabled=0 --pct=0 --actor="$ACTOR" --artifact-dir="$ARTIFACT_DIR" "${DRY_ARG[@]}"

render_cmd() {
  local template="$1"
  template="${template//\{TAG\}/$TAG}"
  printf '%s' "$template"
}

if [[ "$DRY_RUN" -eq 1 ]]; then
  :
else
  if [[ -n "${RELEASE_ROLLBACK_CMD:-}" ]]; then
    ROLLBACK_COMMAND_EXECUTED=1
    ROLLBACK_CMD="$(render_cmd "${RELEASE_ROLLBACK_CMD}")"
    if bash -lc "$ROLLBACK_CMD"; then
      DEPLOY_REVERTED=true
    else
      RESULT="failure"
    fi
  else
    RESULT="failure"
    echo "RELEASE_ROLLBACK_CMD is required for non-dry-run rollback." >&2
  fi
fi

ENDED_AT="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
cat > "$ARTIFACT_DIR/rollback-summary.json" <<EOF
{
  "run_id": "${RUN_ID}",
  "tag": "${TAG}",
  "reason": "${REASON}",
  "actor": "${ACTOR}",
  "dry_run": $([[ "$DRY_RUN" -eq 1 ]] && echo true || echo false),
  "deploy_reverted": ${DEPLOY_REVERTED},
  "rollback_command_configured": $([[ -n "${RELEASE_ROLLBACK_CMD:-}" ]] && echo true || echo false),
  "rollback_command_executed": $([[ "$ROLLBACK_COMMAND_EXECUTED" -eq 1 ]] && echo true || echo false),
  "rollback_command_redacted": true,
  "result": "${RESULT}",
  "started_at": "${STARTED_AT}",
  "ended_at": "${ENDED_AT}"
}
EOF

if [[ "$RESULT" != "success" ]]; then
  exit 1
fi

exit 0
