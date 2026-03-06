# Compliance Variant Grid UX (COM-03)

## Purpose

Expose compliance state directly in the variant grid so editors can act quickly:

- see `OK` / `WARN` / `FAIL`
- see rationale and triggered rules
- request approval for `WARN`
- block scheduling for `FAIL`
- edit and resubmit inline

## UI Behavior

For each variant card:

- badge with status color:
  - `OK` -> green
  - `WARN` -> yellow
  - `FAIL` -> red
- rationale block with compliance reason
- suggested edits (from COM-02) rendered inline when present

### WARN actions

- `Request Approval`
- `Edit & Resubmit`

Request approval calls:

- `POST /wp-json/kh-smma/v1/variant/request-approval`

and stores:

- `approval_required=true`
- `approval_status=pending`

### FAIL behavior

- scheduling checkbox disabled
- block message shown
- `Edit & Resubmit` remains available

## API Integration

- `POST /generate`
  - variants include `compliance_ui` payload
- `POST /variant-edit`
  - supports scheduled edits and unscheduled `variant_id` edits for inline resubmit
  - returns refreshed compliance data and `compliance_ui`
- `POST /variant/request-approval`
  - creates pending approval state for WARN variants

## Telemetry

- `variant.compliance.viewed`
- `variant.compliance.edit_requested`
- `variant.compliance.request_approval`
- `sponsor.approval.requested`

## Audit

- `variant.edit`
- `approval.requested`
- `compliance.check`

## Tests

- `tests/Compliance/VariantComplianceUITest.php`
- `tests/Compliance/VariantComplianceWorkflowTest.php`
- fixture: `tests/fixtures/compliance/variant_grid_cases.json`
