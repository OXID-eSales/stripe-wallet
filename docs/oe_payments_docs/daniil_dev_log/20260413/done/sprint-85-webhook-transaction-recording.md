# Sprint 85: Webhook Transaction Recording + OXPAID Investigation

**Date:** 2026-04-13
**Branch:** `b-7.4.x`

## Problem

Order 610: Partial capture done on Stripe Dashboard, but:
1. OXPAID stays 0000-00-00
2. Transaction history shows only authorization, not capture
3. Contract remains `committed` (not `fulfilled`)

## Root Cause

Webhooks are NOT reaching the dev server. Evidence:
- Zero webhook events in `stripe_events_2026-04-13.log`
- Zero entries in `oe_payments_webhooklogs` since January (test data only)
- `PaymentIntentSucceededHandler` never fires for Stripe Dashboard captures

The webhook URL `https://daniil.oxiddev.de/index.php?cl=StripeWebhookController` is correctly registered in code, but Stripe cannot reach it (infrastructure/config issue).

## Fix (code side)

Add `TransactionRepositoryInterface` to `PaymentIntentSucceededHandler` to record capture transactions when webhook fires.

## Action Required (infrastructure)

1. Verify webhook endpoint URL in Stripe Dashboard → Developers → Webhooks
2. Ensure `https://daniil.oxiddev.de/index.php?cl=StripeWebhookController` is configured
3. Check webhook delivery logs in Stripe for failures
4. Test with `stripe trigger payment_intent.succeeded` CLI tool
