# Compliance Governance Policy (COM-06)

## Policy Controls

Compliance rules are production policy controls. Rule changes must be:

- restricted to authorized roles
- auditable with before/after values
- versioned
- reviewable by operations

## Role-Based Access

### `manage_compliance_rules`

Can:

- add/edit/remove banned phrases
- change rule severity
- view rule history

### `manage_sponsor_claims`

Can:

- edit sponsor `allowed_claims` for owned sponsor IDs
- view sponsor-scoped compliance audit entries

Cannot:

- modify banned phrase corpus
- edit claims for other sponsors

## Required Audit Events

- `compliance.rule_added`
- `compliance.rule_updated`
- `compliance.rule_removed`
- `sponsor.allowed_claims_updated`

Payload includes:

- `trace_id`
- `user_id`
- `rule_id`
- `previous_value`
- `new_value`
- `timestamp`

## Rule Versioning

Metadata fields:

- `compliance_rules_version`
- `updated_at`
- `updated_by`

Version increases when:

- banned phrase added/removed/updated
- sponsor claims changed

When version changes, approved schedules may be flagged for re-review via `ApprovalSafetyService`.

## Security Requirements

- rule mutations require capability checks
- changes must be logged
- historical audit records retained
- raw sensitive content should not be stored when hash references are sufficient

## Documentation Set

- `docs/compliance/runbook.md`
- `docs/compliance/rules-admin.md`
- `docs/compliance/observability.md`
- `docs/compliance/suggested-edits.md`
