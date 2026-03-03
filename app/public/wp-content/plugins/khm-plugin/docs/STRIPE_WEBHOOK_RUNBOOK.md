# Stripe Webhook Runbook (KHM Membership)

## Scope

Canonical endpoint: `POST /wp-json/khm/v1/webhooks/stripe`

Legacy endpoint (only if legacy membership webhook handler is enabled): `POST /wp-json/kh-membership/v1/webhook/stripe`

This runbook covers:
- signature verification failures
- duplicate/replay events
- failed event reprocessing
- secret rotation
- monitoring and alert thresholds

## Ownership

- Primary owner: `TBD`
- Secondary owner: `TBD`
- Escalation channel: `TBD`

## Security and secrets

Required options/secrets:
- `khm_stripe_webhook_secret`
- `khm_stripe_secret_key`

Never store webhook secrets in code or docs.

## Secret rotation procedure

1. Create/roll webhook secret in Stripe destination settings.
2. Update `khm_stripe_webhook_secret` in WordPress admin settings.
3. Send Stripe test event (`product.updated` or `invoice.paid`) and confirm `200`.
4. Verify new events are written to `wp_khm_webhook_events`.
5. Keep old secret only during brief overlap window if required; remove old value after validation.

## Event processing model

1. Webhook request verifies Stripe signature.
2. Event ID is checked against `wp_khm_webhook_events` (idempotency).
3. Allowed events are processed inline by `KHM\Rest\WebhooksController`.
4. Endpoint returns `200` with `processed`, `duplicate`, or `ignored`.
5. Event is written once to `wp_khm_webhook_events` with metadata.

## Duplicate and replay behavior

- Duplicate Stripe deliveries are safe.
- Existing processed events return `200` (`status=duplicate`) without re-applying side effects.

## Operator actions

Admin page: `Memberships -> Webhook Events` (`page=khm-membership-webhooks`) is tied to the legacy membership webhook store (`wp_khm_processed_webhooks`), not the canonical `khm/v1` idempotency table.

Available actions (legacy handler path):
- `Requeue`
- `Mark Processed`
- `Mark Failed`

## Payload storage and PII policy (legacy handler only)

Payload mode filter: `khm_membership_webhook_payload_mode`
- `excerpt` (default): redacted, truncated payload excerpt
- `hash`: hash-only storage (payload omitted)
- `full`: redacted full payload (truncated to safeguard size)

Retention filter: `khm_membership_webhook_retention_days` (default `30` days)

Policy constants (optional, override defaults):
- `KHM_MEMBERSHIP_WEBHOOK_RATE_LIMIT_MAX_REQUESTS` (default `100`)
- `KHM_MEMBERSHIP_WEBHOOK_RATE_LIMIT_WINDOW` (default `60` seconds)
- `KHM_MEMBERSHIP_WEBHOOK_PAYLOAD_MODE` (default `excerpt`)
- `KHM_MEMBERSHIP_WEBHOOK_RETENTION_DAYS` (default `30`)

## Monitoring and thresholds

Telemetry hook: `khm_membership_webhook_telemetry`

Track:
- `webhook.received`
- `webhook.invalid_signature`
- `webhook.rate_limited`
- `webhook.processed`
- `webhook.failed`
- `webhook.queue_failed`

Suggested alerts:
- invalid signature rate > 5% over 15m
- failed processing rate > 2% over 15m
- queue failures > 0 over 5m
- backlog of `processing` events older than 10m

## Manual triage SQL

Recent failures (legacy table):

```sql
SELECT event_id, event_type, status, attempts, notes, updated_at
FROM wp_khm_processed_webhooks
WHERE status = 'failed'
ORDER BY updated_at DESC
LIMIT 50;
```

Stuck processing (legacy table):

```sql
SELECT event_id, event_type, status, attempts, updated_at
FROM wp_khm_processed_webhooks
WHERE status = 'processing'
  AND updated_at < (UTC_TIMESTAMP() - INTERVAL 10 MINUTE)
ORDER BY updated_at ASC;
```

## Local verification

```bash
stripe listen --forward-to http://<site>/wp-json/khm/v1/webhooks/stripe
stripe trigger checkout.session.completed
```

Then inspect:
- Stripe delivery status `200`
- `wp_khm_webhook_events` contains a new row for the Stripe `event_id`

## Staging UAT script (copy/paste)

### 1) Confirm webhook endpoint is reachable

```bash
curl -i -X POST "https://<staging-domain>/wp-json/khm/v1/webhooks/stripe" \
  -H "Content-Type: application/json" \
  --data '{"id":"evt_probe","type":"invoice.paid","data":{"object":{"customer":"cus_probe"}}}'
```

Expected:
- If unsigned request: `400 Invalid signature`
- Route exists and does not return `404 rest_no_route`

### 2) Forward Stripe events to staging and trigger an event

```bash
stripe listen --forward-to "https://<staging-domain>/wp-json/khm/v1/webhooks/stripe"
stripe trigger checkout.session.completed
```

Expected:
- Stripe dashboard delivery = `200`
- New row appears in `wp_khm_webhook_events`

### 3) Validate idempotency (duplicate replay)

In Stripe dashboard, re-send the same event ID once.

Expected:
- Endpoint still returns `200`
- No duplicate side effects in membership/credits records
- `wp_khm_webhook_events` still contains one record for that `event_id`

### 4) Validate failure retry behavior (canonical route)

1. Trigger an event expected to fail handler logic (staging-only controlled fault).
2. Confirm endpoint returns `500` so Stripe retries delivery.
3. Fix root cause.
4. Re-send event from Stripe dashboard.

Expected:
- Re-delivery succeeds with `200`.
- Event appears once in `wp_khm_webhook_events`.

### 5) Validate cleanup retention

Canonical `wp_khm_webhook_events` cleanup is managed by `DatabaseIdempotencyStore::cleanup()` when invoked by maintenance tooling; legacy cron hook below applies only to `wp_khm_processed_webhooks`:

```bash
wp cron event run khm_membership_webhook_cleanup
```
