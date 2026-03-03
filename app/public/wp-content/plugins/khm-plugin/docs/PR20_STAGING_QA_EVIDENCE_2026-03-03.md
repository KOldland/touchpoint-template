# PR20 Staging QA Evidence - 2026-03-03

Environment:
- Branch deployed: `chore/remote-local-baseline-20260302`
- Commit deployed: `54d2f87` (`fix(stripe): close promo injection and webhook email/opt-in gaps`)
- URL: `https://touchpoint5stg.wpenginepowered.com`
- Date: `2026-03-03`
- Tester: `Codex + Kris`

## 1) Webhook reliability
- [ ] Stripe destination configured to staging webhook URL
- [ ] Fresh `product.updated` sent (not resend)
- [ ] Stripe delivery status = `200 OK`
- [x] Endpoint route exists and rejects unsigned payloads with signature error
- [x] Webhook audit/ops tables exist
- [ ] WP audit/dead-letter check shows no new failures after fresh Stripe delivery

Evidence:
- `POST /wp-json/khm/v1/webhooks/stripe` with unsigned probe returns `400`
- Body: `{"code":"khm_invalid_signature","message":"Invalid Stripe webhook signature.","data":{"status":400}}`
- Tables present:
  - `wp_khm_webhook_events`
  - `wp_khm_membership_webhook_audit`
  - `wp_khm_membership_webhook_operations`
- `wp_khm_processed_webhooks` is currently missing on staging.

Notes:
- Legacy route in runbook (`/wp-json/kh-membership/v1/webhook/stripe`) returns `404` on staging.
- Active route is `/wp-json/khm/v1/webhooks/stripe`.

## 2) Membership checkout (Stripe promo codes)
- [x] Server no longer trusts raw `stripe_promotion_code` from request payload
- [x] Promo mapping now comes from validated code path (`DiscountCodeService::validate_code`)
- [ ] Modal membership checkout opens and creates Stripe Checkout session (manual UI)
- [ ] Valid Stripe promo code applies in hosted checkout (manual UI)
- [ ] Checkout succeeds; membership/order created in WP (manual UI)

Code evidence deployed:
- `src/Frontend/MembershipCheckoutHandler.php`
- `src/Rest/CheckoutController.php`

## 3) Legacy hardening
- [ ] Legacy discount widget not visible on `[khm_checkout]` pages (manual UI)
- [ ] Admin warning appears for published legacy `[khm_checkout]` pages (manual UI)
- [ ] `Create Draft Replacements` creates draft pages successfully (manual UI)
- [ ] Draft page with `[khm_membership_checkout_button level_id="X"]` works (manual UI)

## 4) Transactional promo/voucher flow (WP codes, commerce/social-strip)
- [ ] Apply WP promo code succeeds in transactional flow (manual flow)
- [ ] Cart totals reflect discount (`discount`, `total`) (manual flow)
- [ ] Payment intent/charge uses discounted total (manual flow)
- [ ] Finalized order stores `discount_code`, `discount_amount`, `subtotal`, `total` (manual flow)
- [ ] Promo usage tracked and cleared after success (manual flow)

## 5) Regression checks
- [x] Staging site and plugin stack load
- [ ] Member portal/account pages load (manual UI)
- [ ] Gift voucher redeem endpoint still works (manual/API)
- [x] No deployment fatals observed during command/test window

## Additional fixes included in this deploy
- [x] Explicit marketing opt-out persistence added (`profile_marketing_optin` now set to `1` or `0` when field provided)
- [x] Webhook email source priority corrected (Stripe customer email now preferred over metadata `guest_email`)

## Result
- QA status: `PASS with notes (automation subset)`
- Blocking issues:
  - Fresh Stripe destination delivery evidence (`200`) not yet captured in this run.
  - Manual UI checklist items still pending execution.
- Follow-ups:
  - Update runbook endpoint references from `kh-membership/v1/webhook/stripe` to `khm/v1/webhooks/stripe` where applicable.
  - Confirm whether `wp_khm_processed_webhooks` is expected in this environment or superseded by `wp_khm_webhook_events`.
