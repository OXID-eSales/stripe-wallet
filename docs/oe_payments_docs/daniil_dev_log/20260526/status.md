# 2026-05-26 — Stripe module dev log

_Continues from `../20260522/`._

| # | Task | Scope | Status | Landed |
|---|---|---|---|---|
| 1 | [Previous-day review (2026-05-22)](reports/previous-day-review-20260522.md) — three threads recap: shop unstick, Sprint 109 verdict + 110/111 (manual webhook button, clear-all-webhooks), critical-review + test cleanup. | `stripe` (docs) | ✅ Filed | 2026-05-26 |
| 2 | Live webhook test rig — real Stripe webhook registered on connected account, signature secret `whsec_KGQ2…`, capture mode flipped to `manual`. Monitors armed: webhook log tail, contract row diff (per order), new-order watcher. | `stripe` (test infra) | ✅ Up | 2026-05-26 |
| 3 | Verify capture webhook path against fresh order 90 (231.50 EUR, manual capture). `POST /v1/payment_intents/pi_…VsIxEE3/capture` → 2 webhooks: `payment_intent.succeeded` (SUCCESS: contract_fulfilled), `charge.captured` (SUCCESS: skipped — contract already fulfilled). Contract row: state `committed → fulfilled`, OXCAPTUREDAMOUNT `NULL → 231.50`, OXCAPTUREDAT + OXFULFILLEDAT set. Order row: OXTRANSSTATUS `NOT_FINISHED → OK`, OXPAID set, OXTRANSID = PI. | `stripe` | ✅ Passed | 2026-05-26 |
| 4 | Verify refund webhook path against order 90. `POST /v1/refunds` for 50.00 EUR partial → 1 webhook: `charge.refunded` (SUCCESS: charge_refunded). Contract row: OXREFUNDEDAMOUNT `NULL → 50.00`, OXREFUNDEDAT set, state stays `fulfilled` (refund is amount-tracking only, not a state). | `stripe` | ✅ Passed | 2026-05-26 |
| 5 | Diagnose order 89 webhook miss. `charge.refunded` event arrived (signed, parsed, contract found by PI lookup) but returned `SUCCESS: skipped`. Root cause: order 89 was placed 2026-05-22 16:32:25, BEFORE the webhook endpoint was registered later that day → `payment_intent.succeeded` never reached the controller → contract stuck at `committed`, OXFULFILLEDAT=NULL → refund handler's `isFulfilled()` guard (WebhookContractFulfillmentHandler.php:126) correctly skips. Not a bug; consequence of webhook gap during o89's lifecycle. | `stripe` (diagnosis) | ✅ Filed | 2026-05-26 |
| 6 | Test `payment_intent.canceled` webhook path. Order 91 (262.00 EUR, manual capture), `POST /v1/payment_intents/pi_…SSpkuD1/cancel` → 1 webhook: `payment_intent.canceled` (SUCCESS: contract_cancelled). Contract row: state `committed → cancelled` (terminal). **Surfaced bug** — oxorder row not reverted on cancel (OXTRANSSTATUS stays OK, OXTRANSID still set). | `stripe` | ✅ Passed (with finding) | 2026-05-26 |
| 7 | [Observations & inconsistencies report](reports/webhook-processing-observations.md) — 8 items: transaction audit log gap, `charge.captured` dead code, mis-labelled `payment_intent_id` log field, `OXCONTRACTID` NULL on skipped events, empty `OXPAYLOAD`, compressed state machine (`authorized` never persisted), order-row not reverted on cancel, untested `OXSTATEREASON`. Plus what-we-didn't-test list. | `stripe` (docs) | ✅ Filed | 2026-05-26 |
| 8 | [Sprint 112 plan](done/sprint-112-webhook-processing-hardening.md) + [completion report](done/sprint-112-completion-report.md) — webhook processing hardening. All 5 goals landed (G1 cancelled-order revert, G2 skipped-event contract linkage, G3 transaction audit log writes, G4 correct PI in receipt log, G5 remove `charge.captured` dead code). 4 new test files (15 new unit tests) + 2 new production classes; 1 dead integration-test file + 5 dead unit tests deleted; 3 dead private helpers deleted. `./bin/pre-commit-check.sh --full` ✓. Net: unit tests 881 → 896, combined 1029 → 1034. PHPStan max + PHPMD strict + PHPCS all clean, baselines unchanged. Live browser smoke pending. | `stripe` | ✅ Done | 2026-05-26 |

## Legend
- ⬜ Not started
- 🟡 In progress
- ✅ Done
- 🚫 Blocked

## Summary

Live testing of webhook processing against real Stripe events from the
module's connected account `acct_1TEpLERKy8lrhVfC`. Yesterday's Sprint 110/111
manual-button registered endpoint is in place at
`https://daniil.oxiddev.de/index.php?cl=StripeWebhookController` with secret
`whsec_KGQ2…`.

**Capture path verified** on fresh order 90 (231.50 EUR, manual mode). Real
`POST /v1/payment_intents/{pi}/capture` from CLI → Stripe fires both
`payment_intent.succeeded` and `charge.captured`. Both arrive at the controller
within the same second; the first one wins (`fulfilled` state set), the second
no-ops via the existing "already fulfilled" guard. Contract row diff is exactly
as designed: state, OXCAPTUREDAMOUNT, OXCAPTUREDAT, OXFULFILLEDAT all populate.
Order row also flips correctly (OXPAID, OXTRANSSTATUS, OXTRANSID).

**Refund path verified** on the same order. `POST /v1/refunds` for 50.00 EUR →
`charge.refunded` arrives → contract row gains OXREFUNDEDAMOUNT=50.00 and
OXREFUNDEDAT. Refund is amount-tracking, not a state change; OXSTATE stays
`fulfilled`.

**Order 89 negative result** (skipped refund) confirmed not-a-bug. The contract
never reached `fulfilled` because the webhook endpoint hadn't been registered
when the order was placed. The refund handler's state guard correctly skipped.

**Cancel path also verified** on a separate fresh order 91 (262.00 EUR,
manual capture). `POST /v1/payment_intents/{pi}/cancel` → `payment_intent.canceled`
arrives → contract state `committed → cancelled` (terminal). However: oxorder
row was NOT reverted — OXTRANSSTATUS stays `OK`, OXTRANSID still set
(only OXPAID is correctly never-set). A cancelled order looks paid in admin.
**Genuine bug**, filed in the observations report.

**Eight inconsistencies/findings surfaced** — full details in
[`reports/webhook-processing-observations.md`](reports/webhook-processing-observations.md):

1. `oe_payments_transaction` audit log is NOT written by the webhook path —
   only admin-UI captures/refunds via `OrderActionDispatcher` populate it.
2. `charge.captured` handler is dead code in production — `payment_intent.succeeded`
   always wins the race, leaving `charge.captured` to skip every time.
3. WEBHOOK_RECEIVED log line mis-labels `payment_intent_id` for `charge.*` events
   — it actually contains `data.object.id` (the charge ID), not the PI.
4. `oe_payments_webhooklogs.OXCONTRACTID` stays NULL for skipped events even
   when the contract was found — `mapHandlerResult` only links on success.
5. `oe_payments_webhooklogs.OXPAYLOAD` is always empty (length 0) — post-mortem
   requires re-fetching from Stripe API.
6. Contract state machine compresses `authorized` and `ready_to_commit` into
   `committed` — those documented intermediate states are never persisted.
7. **Order row not reverted on contract cancel** (genuine bug, item 6 in the
   report).
8. `OXSTATEREASON` not populated on cancel — possibly handler doesn't extract
   it; needs follow-up with a reason-bearing cancel.

## Test artifacts

| File | Purpose |
|---|---|
| `reports/previous-day-review-20260522.md` | Yesterday's three-thread recap |
| `reports/snapshot-before.txt` | DB state of order 89 before today's testing |
| `reports/webhook-processing-observations.md` | (To be written after task #6) |

## Files changed today (uncommitted)

```
# Config (test-rig changes — revert before commit if not intended for prod)
M  source/var/configuration/shops/1/modules/oe_payments_stripe_wallet.yaml
   - sStripeCaptureMode: automatic → manual
   - sStripeWebhookEndpoint + sStripeWebhookEndpointSecret: re-registered

# Sprint 112 production
M  source/extensions/stripe/src/Stripe/Webhook/StripeWebhookProcessor.php
M  source/extensions/stripe/src/Stripe/Service/WebhookLogService.php
M  source/extensions/stripe/src/Stripe/Service/WebhookEventCatalog.php
M  source/extensions/stripe/src/Stripe/WebhookHandler/WebhookContractFulfillmentHandler.php
M  source/extensions/stripe/src/Stripe/WebhookHandler/WebhookContractFulfillmentHandlerInterface.php
M  source/extensions/stripe/services.yaml
A  source/extensions/stripe/src/Stripe/Service/ContractLinkedOrderUpdaterInterface.php
A  source/extensions/stripe/src/Stripe/Service/OxidContractLinkedOrderUpdater.php

# Sprint 112 tests
A  source/extensions/stripe/tests/Unit/Stripe/Service/WebhookLogServicePayloadParsingTest.php
A  source/extensions/stripe/tests/Unit/Stripe/Service/WebhookEventCatalogTest.php
A  source/extensions/stripe/tests/Unit/Stripe/Handler/WebhookContractFulfillmentHandlerCancelOrderTest.php
A  source/extensions/stripe/tests/Unit/Stripe/Handler/WebhookContractFulfillmentHandlerAuditTest.php
M  source/extensions/stripe/tests/Unit/Stripe/Webhook/StripeWebhookProcessorTest.php
M  source/extensions/stripe/tests/Unit/Stripe/Handler/WebhookContractFulfillmentHandlerTest.php
D  source/extensions/stripe/tests/Integration/Stripe/Webhook/DelayedCaptureIntegrationTest.php

# Dev log
A  source/extensions/stripe/docs/oe_payments_docs/daniil_dev_log/20260526/
```
