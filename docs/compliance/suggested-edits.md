# Suggested Edit Automation (COM-02)

## Overview

When compliance returns `WARN` or `FAIL`, SMMA now returns a `suggested_edits` array that editors can apply quickly and re-check.

Affected responses:

- `POST /wp-json/kh-smma/v1/generate`
- `POST /wp-json/kh-smma/v1/variant-edit`

## Suggested Edit Structure

```json
{
  "rule_id": "banned_phrase_guarantee",
  "original_phrase": "guaranteed results",
  "suggested_phrase": "designed to help achieve results",
  "reason": "absolute guarantee claims are not permitted"
}
```

## Rule Mapping Service

Implemented in:

- `src/Compliance/SuggestedEditService.php`

Default mappings include:

- `banned_phrase_guarantee`
- `absolute_claim_best`
- `unverified_performance`

## Editor Behavior

UI updates in `assets/js/smma-admin.js`:

- compliance rationale displayed per generated variant
- suggested edits displayed for WARN/FAIL variants
- `Apply Fix` for single replacement
- `Apply All Suggested Fixes` for all replacements

On apply:

1. Updated text sent to `/variant-edit`
2. Compliance automatically re-runs
3. UI updates to latest compliance result

## Telemetry and Audit

Telemetry events emitted:

- `compliance.suggested_edit_generated`
- `compliance.suggested_edit_applied`

Audit event recorded on suggested-apply path:

- `variant.edit` with `source=suggested_edit`

## Tests

- `tests/SuggestedEditServiceTest.php`
- `tests/SuggestedEditWorkflowTest.php`
- `tests/fixtures/compliance/suggested_edit_cases.json`
