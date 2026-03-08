#!/usr/bin/env bash
set -euo pipefail

usage() {
  cat <<'EOF'
Usage: scripts/release_deploy.sh --env=staging|canary|prod --tag=<release-tag> [--artifact-dir=<dir>] [--dry-run]

Environment-driven command templates (optional):
  RELEASE_DEPLOY_CMD_STAGING
  RELEASE_DEPLOY_CMD_CANARY
  RELEASE_DEPLOY_CMD_PROD

Template placeholders:
  {TAG} -> release tag
  {ENV} -> target environment
EOF
}

ENVIRONMENT=""
TAG=""
ARTIFACT_DIR=""
DRY_RUN=0

for arg in "$@"; do
  case "$arg" in
    --env=*) ENVIRONMENT="${arg#*=}" ;;
    --tag=*) TAG="${arg#*=}" ;;
    --artifact-dir=*) ARTIFACT_DIR="${arg#*=}" ;;
    --dry-run) DRY_RUN=1 ;;
    --help|-h) usage; exit 0 ;;
    *) echo "Unknown argument: $arg" >&2; usage; exit 2 ;;
  esac
done

if [[ -z "$ENVIRONMENT" || -z "$TAG" ]]; then
  echo "--env and --tag are required." >&2
  usage
  exit 2
fi

case "$ENVIRONMENT" in
  staging|canary|prod) ;;
  *) echo "Unsupported env: $ENVIRONMENT" >&2; exit 2 ;;
esac

if [[ -z "$ARTIFACT_DIR" ]]; then
  ARTIFACT_DIR="artifacts/release/${ENVIRONMENT}"
fi
mkdir -p "$ARTIFACT_DIR"

RUN_ID="${GITHUB_RUN_ID:-local}"
ACTOR="${GITHUB_ACTOR:-manual}"
STARTED_AT="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
RESULT="success"
COMMAND_EXECUTED=0
MESSAGE=""

render_cmd() {
  local template="$1"
  template="${template//\{TAG\}/$TAG}"
  template="${template//\{ENV\}/$ENVIRONMENT}"
  printf '%s' "$template"
}

CMD_VAR_NAME="RELEASE_DEPLOY_CMD_$(printf '%s' "$ENVIRONMENT" | tr '[:lower:]' '[:upper:]')"
CMD_TEMPLATE="${!CMD_VAR_NAME:-}"

if [[ "$DRY_RUN" -eq 1 ]]; then
  if [[ -n "$CMD_TEMPLATE" ]]; then
    MESSAGE="dry_run: deploy command configured (redacted)"
  else
    MESSAGE="dry_run: no deploy command configured; sequence preview only"
  fi

  echo "[release_deploy] dry-run"
  echo " env: $ENVIRONMENT"
  echo " tag: $TAG"
  echo " actor: $ACTOR"
  echo " artifact_dir: $ARTIFACT_DIR"
  if [[ -n "$CMD_TEMPLATE" ]]; then
    echo " command: configured (redacted)"
  fi
else
  if [[ -z "$CMD_TEMPLATE" ]]; then
    RESULT="failure"
    MESSAGE="Missing $CMD_VAR_NAME. Refusing real deploy without explicit deploy command."
    echo "[release_deploy] $MESSAGE" >&2
  else
    COMMAND_EXECUTED=1
    DEPLOY_CMD="$(render_cmd "$CMD_TEMPLATE")"
    echo "[release_deploy] executing deploy for $ENVIRONMENT tag=$TAG"
    if ! bash -lc "$DEPLOY_CMD"; then
      RESULT="failure"
      MESSAGE="Deploy command failed for env=$ENVIRONMENT"
      echo "[release_deploy] $MESSAGE" >&2
    else
      MESSAGE="deploy command completed"
    fi
  fi
fi

ENDED_AT="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
SUMMARY_PATH="$ARTIFACT_DIR/deploy-summary.json"

cat > "$SUMMARY_PATH" <<EOF
{
  "run_id": "${RUN_ID}",
  "actor": "${ACTOR}",
  "env": "${ENVIRONMENT}",
  "tag": "${TAG}",
  "dry_run": $([[ "$DRY_RUN" -eq 1 ]] && echo true || echo false),
  "result": "${RESULT}",
  "message": "${MESSAGE}",
  "command_configured": $([[ -n "$CMD_TEMPLATE" ]] && echo true || echo false),
  "command_executed": $([[ "$COMMAND_EXECUTED" -eq 1 ]] && echo true || echo false),
  "command_redacted": true,
  "command_source_env": "${CMD_VAR_NAME}",
  "started_at": "${STARTED_AT}",
  "ended_at": "${ENDED_AT}"
}
EOF

if [[ "$RESULT" != "success" ]]; then
  exit 1
fi

exit 0
