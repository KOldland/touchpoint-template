# Compliance Runbook (COM-06)

## Compliance Engine Overview

The SMMA compliance workflow evaluates generated/edited variant text and emits one of:

- `OK`: no blocking rule triggered
- `WARN`: risky language; sponsor/admin approval required
- `FAIL`: policy violation; scheduling blocked

Compliance checks are deterministic for rule-based conditions and fully observable through telemetry and audit logs.

## Severity Definitions

| Severity | Meaning | Behavior |
|---|---|---|
| `OK` | no rule triggered | scheduling allowed |
| `WARN` | content is risky | approval required |
| `FAIL` | policy violation | scheduling blocked |

## Procedure: Update Banned Phrases

1. Open `Admin -> KH Social -> Compliance Corpus`.
2. Add or edit phrase.
3. Set severity (`WARN` or `FAIL`).
4. Save change.

Expected system behavior:

- compliance rules version increments
- audit event recorded (`compliance.rule_added` or `compliance.rule_updated`)
- approved schedules may be marked for re-review
- future compliance checks evaluate against updated rule set

## Procedure: Update Sponsor Allowed Claims

1. Open `Admin -> KH Social -> Sponsor Claims`.
2. Load sponsor by `sponsor_id`.
3. Edit `allowed_claims` list.
4. Save changes.

Expected system behavior:

- compliance rules version increments
- audit event recorded (`sponsor.allowed_claims_updated`)
- approved schedules may be marked for re-review
- subsequent compliance checks use updated claim list

## Reviewing Flagged Variants

- `WARN`: request approval or edit and resubmit
- `FAIL`: edit text, apply suggested edits, rerun compliance

Scheduling should remain blocked for `FAIL` and `WARN` pending approval.

## Troubleshooting

### Variant Blocked by Compliance

Possible causes:

- banned phrase triggered
- unsupported claim wording
- severity set to `FAIL`

Actions:

1. edit variant text
2. apply suggested edit(s)
3. rerun compliance
4. resubmit schedule

### Approval Queue Backlog

Symptoms:

- many `WARN` variants pending approval

Actions:

1. review pending approvals in admin workflow
2. approve/reject quickly based on rationale
3. monitor compliance observability alerts for spikes

## Local CI/Test Commands

From plugin root (`app/public/wp-content/plugins/kh-smma`):

```bash
vendor/bin/phpunit tests/Compliance
vendor/bin/phpunit tests/Compliance/ComplianceEngineTest.php
vendor/bin/phpunit tests/Compliance/ComplianceWorkflowSmokeTest.php
```

Fixture verification:

```bash
php scripts/verify_golden_fixtures.php
```
