# Contributing Golden Fixtures

Golden fixtures are deterministic contracts used by CIC to unblock parallel work across SMMA, Compliance, Membership, and Paid Adapter buckets.

## Rules

1. Contract-first: if fixture shape changes, update the related contract JSON in `docs/contracts/` in the same PR.
2. Owner approval required: any fixture change PR must include label `golden-owner-approved`.
3. No secrets/PII in fixtures: CI secret scan blocks merge on violations.
4. Metadata required: every core fixture must have `<fixture>.meta.json` with checksum, prompt hash, version, author, and created date.

## Regenerate workflow

1. Capture a recorded response payload to a local JSON file.
2. Run the interactive preview tool:

```bash
php scripts/regenerate_fixture_ui.php \
  --input /path/to/recorded.json \
  --fixture-name generate_awareness_ok.json \
  --author @your-handle \
  --prompt-version smma_generate_v1 \
  --prompt-file docs/contracts/prompts/smma_generate_v1.txt
```

3. Optional: compute prompt hash directly for validation:

```bash
php scripts/compute_prompt_hash.php --file docs/contracts/prompts/smma_generate_v1.txt
```

4. Review generated fixture and sidecar metadata in `tmp/golden-preview/*`.
5. Copy approved files into `app/public/wp-content/plugins/kh-smma/tests/fixtures/golden/`.
6. Open PR with reason/impact summary and request owner review.
7. Apply label `golden-owner-approved`.

## CI checks

- `golden-check`: validates required fixtures, metadata, checksums, and label policy.
- `secret-scan`: rejects committed secrets.

These checks are merge-blocking once branch protection is configured to require them.

## Local developer ergonomics

- Bootstrap CI-parity environment:

```bash
./scripts/ci_local_env.sh
```

- Fast local wrapper:

```bash
php scripts/dev_golden_check.php --fixture generate_awareness_ok.json --output artifacts/dev-golden-check
```

- Optional pre-commit secret scan hook:

```bash
./tools/install_hooks.sh
```
