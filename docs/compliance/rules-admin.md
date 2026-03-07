# Compliance Rules Admin Controls (COM-01)

## Purpose

This guide documents administration of:

- banned phrase corpus
- sponsor `allowed_claims`
- audit history and corpus versioning

These controls let compliance governance evolve without redeploying code.

## Admin Pages

- `Admin -> KH Social -> Compliance Corpus`
- `Admin -> KH Social -> Sponsor Claims`

## Banned Phrase Corpus

Each phrase record stores:

- `phrase`
- `severity` (`WARN` or `FAIL`)
- `category`
- `created_by`
- `created_at`
- `updated_at`

Supported actions:

- add phrase
- edit phrase
- remove phrase
- change severity

Validation:

- duplicate phrase prevention
- severity required
- sanitized phrase/category input

## Sponsor Allowed Claims

Each sponsor claim record stores:

- `sponsor_id`
- `allowed_claims[]`
- `updated_by`
- `updated_at`

Claim updates are scoped by role permissions:

- administrators can update any sponsor
- sponsor managers can update only sponsors in their `kh_smma_sponsor_ids` user meta
- required capabilities: `manage_compliance_rules`, `manage_sponsor_claims` (or SMMA-prefixed equivalents)

## Versioning and Re-review

Corpus updates increment `compliance_rules_version` (mirrored as `corpus_version`) and can trigger schedule re-review flags for previously approved schedules.

Schedule fields set during re-review:

- `_kh_smma_requires_rereview=1`
- `_kh_smma_rereview_reason=compliance_rules_version_changed`
- `_kh_smma_rereview_corpus_version=<version>`
- `_kh_smma_approval_status=pending`

## Audit Events

Rule changes are written via `AuditLogger::record_event()`:

- `compliance.rule_added`
- `compliance.rule_updated`
- `compliance.rule_removed`
- `sponsor.allowed_claims_updated`

Recommended payload fields:

- `trace_id`
- `user_id`
- `rule_id`
- `previous_value`
- `new_value`
- `timestamp`

## Telemetry Events

Rule changes emit:

- `compliance.rules.updated`
- `compliance.corpus.modified`

Payload:

- `trace_id`
- `user_id`
- `change_type`
- `timestamp`

No PII should be included.

## Testing

Run from plugin root:

```bash
vendor/bin/phpunit tests/ComplianceRulesAdminTest.php
vendor/bin/phpunit tests/ComplianceAdminWorkflowTest.php
```
