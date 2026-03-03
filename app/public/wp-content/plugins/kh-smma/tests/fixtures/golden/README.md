# Golden Fixtures Inventory (CIC-02)

Canonical fixture directory for deterministic CI and cross-bucket contract tests.

## Governance

- Any fixture change requires PR label: `golden-owner-approved`.
- Each fixture must include a `<name>.meta.json` sidecar.
- No secrets or PII are allowed in fixtures.

## Canonical CIC-02 baseline

| Fixture | Owner | Description | Prompt Hash | Checksum |
| --- | --- | --- | --- | --- |
| `generate_awareness_ok.json` | `@smma-owner` | SMMA generator output (`linkedin_variants` + `google_ad_draft`) | `sha256:a907a71fe74be9b7836a7677d7ce906a041cd425e37e1caae10fa7f21057d186` | `sha256:36b189454870e9f31c67b25531acb45b73acd25ca63821835569fa50a6da99b9` |
| `compliance_ok.json` | `@compliance-owner` | Compliance OK response contract | `sha256:fc3932a2d0b70ebb25d8df1b3726f1e166503d62e9fb6f7ca7aecc0d6cea7490` | `sha256:9ab30c220f8f0e3a06ea08081f481cd9fecf0f60196121fad3fdfdf51ba25fb6` |
| `checkout_session_completed.json` | `@mem-owner` | Membership webhook `checkout.session.completed` event | `sha256:00ba1193dcdf22fc23033b187831968ec9259c25246833205f4fb2a670202d68` | `sha256:d9c2167f5dbaf3946f40677e1e450281e2eda9149239822e2889744ecc4f5c62` |
| `paid_adapter_dry_run_manifest.json` | `@paid-owner` | PAID adapter manifest payload (dry-run planning) | `sha256:204239e53218048a87508e272a6bc65db4dbaa8c559b880247b22e61a8916e1a` | `sha256:ca5e68ab21c838be9e7b9e34f3b2b3cb673457d28c0c9763b652b67ec822ebc4` |
| `paid_adapter_dry_run_response.json` | `@paid-owner` | PAID adapter dry-run response payload | `sha256:c572a4c987906c8417db27274beb601a29832d9648af6ed6b0bc8313e61ecdbf` | `sha256:48678d509fc6c6846e9b50705d54e3cf92695765fa3f8f816a9c4215174a4aac` |

## Metadata format

Each sidecar file contains:

- `version`
- `prompt_hash` (`sha256:<hex>`)
- `prompt_version`
- `created_at` (ISO8601 UTC)
- `author` (GitHub handle)
- `checksum` (`sha256:<hex>`)
- `notes`

## Repro commands

```bash
# Fixture checksum
sha256sum tests/fixtures/golden/generate_awareness_ok.json | awk '{print $1}'

# Prompt hash (example)
printf '%s' "SMMA awareness generation canonical prompt v1" | shasum -a 256 | awk '{print $1}'

# Verify full inventory
php scripts/verify_golden_fixtures.php
```
