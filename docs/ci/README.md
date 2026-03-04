# CIC Runbooks and Evidence

## Purpose
Authoritative entrypoint for CIC operations: deterministic testing, release gating, reliability triage, secrets handling, and observability.

## Owners
- Primary: `@KOldland`
- CIC/QA owner of fixtures and deterministic checks: `@ci-qa-team`
- Observability owner: `@observability-owner`
- Ops escalation owner: `@ops-oncall`

## Runbooks
- Golden gate: `docs/contracts/CIC_GOLDEN_CHECK.md`
- Smoke harness: `docs/ci/smoke_harness_runbook.md`
- Reliability and flaky triage: `docs/ci/reliability_runbook.md`
- Release playbook: `docs/ci/release_runbook.md`
- Secrets management: `docs/ci/secrets.md`
- Secrets rotation: `docs/ci/secrets_rotation.md`
- Observability and alerting: `docs/observability/alerting_runbook.md`

## PM Templates
- PM gate comment: `docs/ci/PR_PM_GATE_COMMENT_TEMPLATE.md`
- Staging evidence template: `docs/ci/STAGING_E2E_EVIDENCE_TEMPLATE.md`
- Validation log: `docs/ci/runbook_validation.md`
- Quick command sheet: `docs/ci/commands.md`

## Responsibilities
- CIC owner maintains scripts and deterministic fixtures.
- PM/QA use templates to collect release evidence and sign-off.
- Ops configures dashboard/alert platform integrations and escalation paths.

## Mandatory pre-release checks
- `golden-check` passes.
- `secret-scan` passes.
- Release dry-run and gate dry-run evidence attached.
- Alert-fire dry-run evidence attached.

## Current script mapping note
The PM brief references `mem_release_gate_check.php`. The committed script in this repository is `scripts/release_gate_check.php` and is the canonical command used in all CIC runbooks.
