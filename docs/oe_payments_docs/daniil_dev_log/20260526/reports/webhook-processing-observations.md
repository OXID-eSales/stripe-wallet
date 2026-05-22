# Webhook processing — observations and inconsistencies

_2026-05-26 — based on live test against real Stripe webhooks from the module's
connected account `acct_1TEpLERKy8lrhVfC`, registered endpoint
`https://daniil.oxiddev.de/index.php?cl=StripeWebhookController`,
secret `whsec_KGQ2…`. Three orders involved:_

| Order | PI | Test |
|---|---|---|
| 89 | `pi_3TZu7TRKy8lrhVfC0x3q9EbG` | Pre-existing — used to diagnose the "skipped" refund (placed before webhook was registered → contract stuck at `committed`, fulfilled never fired). |
| 90 | `pi_3TbFa7RKy8lrhVfC0VsIxEE3` | Fresh manual-capture order — captured 231.50 EUR, then partial refund 50.00 EUR. Capture + refund webhook paths verified. |
| 91 | `pi_3TbFrdRKy8lrhVfC0SSpkuD1` | Fresh manual-capture order — cancelled before capture. Cancel webhook path verified. |

---

## Summary of what works

| Layer | Status |
|---|---|
| HTTP receipt | ✅ |
| Signature verification (`whsec_KGQ2…`) | ✅ |
| Event parse (`Webhook::constructEvent` → `WebhookEvent` DTO) | ✅ |
| Routing in `StripeWebhookProcessor::processEvent` (match on `$event->type`) | ✅ |
| Handler state guards (`isFulfilled`, `isCommitted`, etc.) | ✅ — correct behavior every time |
| Contract row mutation (state, captured/refunded amount, timestamps) | ✅ |
| Order row update (OXTRANSSTATUS / OXPAID / OXTRANSID) on capture path | ✅ |
| `oe_payments_webhooklogs` persistence (event metadata, processing status) | ✅ |
| Idempotency (no duplicate side effects on repeated events — verified informally by Stripe's own resend behavior on order 89's three identical `charge.refunded` events) | ✅ |

---

## Inconsistencies and gaps

### 1. `oe_payments_transaction` audit log is not written by the webhook path

**Observed:** Orders 89 and 90 both have **zero rows** in `oe_payments_transaction`
despite multiple real capture and refund events arriving at the controller and
being processed (status `processed` in `oe_payments_webhooklogs`).

**Cause:** The `StripeWebhookProcessor` → `WebhookContractFulfillmentHandler` chain
writes only to `oe_payments_contract` (state, captured/refunded amounts,
timestamps). It never inserts into `oe_payments_transaction`. The transaction
audit table is populated only by the **admin-UI flows** that go through
`OrderActionDispatcher` (admin "Capture" / "Refund" / "Cancel authorization"
buttons on the Stripe tab).

**Impact:** CLAUDE.md's "Transaction Storage Strategy (B+)" documents this table
as the audit log for auth / capture / refund:

> **Audit log**: DB (`oe_payments_transaction`) — recorded on auth/capture/refund events

In practice, the audit log is **split**. Provider-driven actions (real customer
flows: capture from API, refund from Stripe Dashboard, dispute-driven refund)
are invisible to this table. Only operator-driven actions through the admin
panel land in it. The admin Stripe tab's "Transaction History" table is sourced
from the Stripe API (`getStripeTransactionHistory()`), so it doesn't depend on
this — but anything that queries `oe_payments_transaction` directly (reports,
exports, reconciliation) will under-count.

**Recommendation:** Either (a) write to the audit table from the webhook
handlers as well, so the table reflects all transactional activity regardless
of origin; or (b) update the docs to describe `oe_payments_transaction` as
"operator action log", not "transaction audit log", and define a separate
source of truth.

### 2. `charge.captured` handler is dead code in production

**Observed:** On every capture via `POST /v1/payment_intents/{pi}/capture`,
Stripe fires **both** `payment_intent.succeeded` and `charge.captured` within
the same HTTP-receive-second. The two requests are processed sequentially by
the controller; `payment_intent.succeeded` lands first, advances the contract
to `fulfilled` via `WebhookContractFulfillmentHandler::handlePaymentSucceeded`.
By the time `charge.captured` is dispatched to
`WebhookContractFulfillmentHandler::handleChargeCaptured`, the contract is
already `fulfilled`, the handler's `isFulfilled()` guard hits, and the result
is `false` → `mapHandlerResult` → `WebhookResult::skipped('Contract already fulfilled')`.

**Test evidence (order 90):**

```
09:40:45 payment_intent.succeeded → SUCCESS: contract_fulfilled
09:40:45 charge.captured          → SUCCESS: skipped
```

`charge.captured`'s OXCONTRACTID is `NULL` in `oe_payments_webhooklogs`
because `mapHandlerResult` only links contract on the success branch.

**Impact:** The `charge.captured` branch of `processEvent` (`StripeWebhookProcessor.php:96`)
plus `WebhookContractFulfillmentHandler::handleChargeCaptured` are effectively
unreachable when paired with `payment_intent.succeeded` in the registered event
list. Two paths exist for the same business transition; one always wins.

**Recommendation:** Either (a) remove `charge.captured` from the registered
webhook event list and drop the handler, since `payment_intent.succeeded`
covers the same transition; or (b) keep `charge.captured` as a fallback for the
case where `payment_intent.succeeded` is filtered out of the subscription list
(some integrations register only charge.* events). Document the intent
explicitly. Currently the code reads as if both paths are meaningful, but only
one ever runs.

### 3. WEBHOOK_RECEIVED log mis-labels `payment_intent_id` for charge events

**Observed:** For `charge.*` events, the WEBHOOK_RECEIVED log line contains:

```
"payment_intent_id":"ch_3TZu7TRKy8lrhVfC0Vhtt9BF"
```

That value is a **charge ID**, not a payment-intent ID. The actual PI lives at
`data.object.payment_intent` and is correctly extracted by the handler via
`StripeWebhookEventParser::extractPaymentIntentIdFromCharge()`. But the
controller's receipt-logging code grabs `data.object.id` and writes it under
the misleading field name `payment_intent_id`.

**Impact:** Greppable logs for diagnosis are confusing. Searching the webhook
log for `pi_3TbFa7RKy8lrhVfC0VsIxEE3` finds the `payment_intent.succeeded`
event but **misses** the matching `charge.captured`, even though the latter
is for the same PI — because the log line carries `ch_…` instead.

**Recommendation:** In the WEBHOOK_RECEIVED log line, populate `payment_intent_id`
from `data.object.payment_intent` when event type starts with `charge.`,
falling back to `data.object.id` for `payment_intent.*` events. Or split into
two fields: `object_id` (always present) and `payment_intent_id` (PI when
extractable).

### 4. `oe_payments_webhooklogs.OXCONTRACTID` is NULL for skipped events

**Observed:** When a webhook is `processed` with `skipped` outcome (e.g. state
guard failed), the row in `oe_payments_webhooklogs` has `OXCONTRACTID = NULL`
even though the handler successfully looked up the contract by PI.

**Cause:** `StripeWebhookProcessor::mapHandlerResult` (line 200) only calls
`setContractIdFromProviderOrderId` on the success branch:

```php
if ($result === true) {
    $this->setContractIdFromProviderOrderId($paymentIntentId);
    return WebhookResult::success($successAction);
}
```

Skipped and not-found outcomes never set `lastContractId`. The base class then
writes `OXCONTRACTID = null` to the log row.

**Impact:** Post-mortem and "which webhooks targeted contract X" queries miss
rows where the contract was found but the action was skipped (state guard).
Example: order 89 has three `charge.refunded` events all skipped — none of
them link back to o89's contract in the log, despite all of them having
successfully matched its PI.

**Recommendation:** Set `lastContractId` whenever the contract lookup
succeeds, independent of the action outcome. Move the
`setContractIdFromProviderOrderId` call to before the result-branch decision.

### 5. `oe_payments_webhooklogs.OXPAYLOAD` is empty

**Observed:** All rows in `oe_payments_webhooklogs` have `CHAR_LENGTH(OXPAYLOAD) = 0`.

**Cause:** The schema has the column (`longtext`, nullable), but the
WebhookProcessor doesn't store the raw payload. Probably an intentional GDPR /
PII-avoidance decision, but undocumented.

**Impact:** Post-mortem of a failed/skipped webhook requires fetching the event
from the Stripe API (read-only access to test/live account needed). For events
older than Stripe's event-retention window (~30 days), the payload is gone.

**Recommendation:** Either (a) start persisting payloads with PII redaction
(strip `customer_email`, `billing_details.email`, etc.); or (b) drop the
column and document the trade-off explicitly.

### 6. Contract state machine compresses `authorized` into `committed`

**Observed (manual capture mode):** All three test orders moved directly
`pending → committed` in DB. The documented intermediate states `authorized`
and `ready_to_commit` were never observed, even with 2s polling. For order
90, the time gap between `pending` (09:38:53) and `committed` (09:39:25) was
32 seconds — plenty of resolution to catch any intermediate state.

**Documented state machine (CLAUDE.md):**

> `DRAFT → NOT_FINISHED → PENDING → AUTHORIZED → READY_TO_COMMIT → COMMITTED → FULFILLED`

> Alternative endings: `CANCELLED`, `EXPIRED`, `FAILED`

**Actual observed transitions:**

```
pending → committed → fulfilled         (manual capture: orders 90)
pending → committed → cancelled         (manual capture, cancelled: order 91)
```

**Impact:** The `authorized` state appears to be either unused in this flow or
collapsed into `committed` (where OXCAPTUREDAMOUNT remains NULL until a real
capture happens). This makes the contract's `OXSTATE` field less informative
than the docs suggest: "committed" means both "authorized-but-not-captured"
AND "captured-but-not-fulfilled".

**Recommendation:** Either (a) clarify the docs to reflect that `authorized`
and `ready_to_commit` are not persisted intermediate states under the current
flow; or (b) introduce explicit `authorized` persistence so OXSTATE alone tells
operators whether money has moved.

### 7. Order row not reverted on cancellation

**Observed (order 91):** Contract was cancelled via webhook
(`payment_intent.canceled` → `SUCCESS: contract_cancelled`), contract row is
now `OXSTATE=cancelled`. But the oxorder row remained:

```
OXTRANSSTATUS = OK
OXTRANSID     = pi_3TbFrdRKy8lrhVfC0SSpkuD1
OXPAID        = 0000-00-00 00:00:00
```

`OXPAID` is correctly never-paid, but `OXTRANSSTATUS=OK` makes the order look
successful in admin order lists (which filter and color by OXTRANSSTATUS).

**Impact:** A cancelled order is visually indistinguishable from a paid order
in the admin overview. Customer-facing order-history pages will likely also
show this as a confirmed/successful order.

**Recommendation:** On `payment_intent.canceled` (and `payment_intent.payment_failed`),
the contract-cancellation handler should also update the linked oxorder
(`OXTRANSSTATUS` → `CANCELLED` or similar, clear `OXTRANSID`). This is a
genuine bug, not just a doc/cleanup issue.

### 8. `OXSTATEREASON` not populated on cancel webhook

**Observed:** After `payment_intent.canceled` was processed for order 91, the
contract's `OXSTATEREASON` is `NULL`. The Stripe event's
`cancellation_reason` was also `None` in our test (we cancelled via API
without supplying a reason).

**Status:** Inconclusive — cannot tell from a single test whether the handler
ignores the field entirely or just had nothing to write. Worth a follow-up
test: cancel a PI with `cancellation_reason=requested_by_customer` and check
whether OXSTATEREASON populates.

**Recommendation:** Add a focused test (or just `stripe payment_intents cancel
{pi} -d cancellation_reason=requested_by_customer`) to confirm whether the
field is wired up.

---

## What we did NOT test today

- `payment_intent.payment_failed` (would need a failing-card flow)
- `charge.dispute.created` (would need to file a real test dispute)
- `checkout.session.completed` (only tested PI events; the Checkout session
  flow is a separate code path even though it converges on similar DB writes)
- `checkout.session.expired`
- Idempotency under explicit duplicate delivery (Stripe's automatic retry on
  HTTP 5xx is the only duplicate-delivery surface tested today)
- Multi-partial-refund accumulation (only one 50.00 EUR partial refund tested
  on order 90 — verifying `addRefundedAmount` accumulates correctly across two
  partials would round out the refund test)

## Webhook-event-to-DB cheat sheet

| Stripe event | Handler outcome on `fulfilled` contract | DB effect |
|---|---|---|
| `payment_intent.succeeded` | success: `contract_fulfilled` | OXSTATE→`fulfilled`, OXCAPTUREDAMOUNT, OXCAPTUREDAT, OXFULFILLEDAT |
| `charge.captured` | skipped: "already fulfilled" | none (dead code in current flow) |
| `charge.refunded` | success: `charge_refunded` (only if state=fulfilled) | OXREFUNDEDAMOUNT, OXREFUNDEDAT |
| `payment_intent.canceled` | success: `contract_cancelled` (only if state non-terminal) | OXSTATE→`cancelled` |
| `payment_intent.payment_failed` | NOT TESTED | (expected) OXSTATE→`failed` |
| `charge.dispute.created` | NOT TESTED | (per code) logs only, no contract change |
| `checkout.session.completed` | NOT TESTED | — |
| `checkout.session.expired` | NOT TESTED | — |
