# Cleanup of NOT_FINISHED orders from abandoned Stripe authorisations

**Date:** 2026-08-27
**Scope:** `extensions/stripe`, `extensions/payment-base`, OXID core console
**Question:** when a customer starts a payment authorisation and drops out, how is the
resulting NOT_FINISHED order cleaned up — is there an `oe-console` command at the
payment-base or Stripe level, or some other mechanism?

---

## 1. Short answer

**There is no `oe-console` cleanup command — not in Stripe, not in payment-base, not in OXID core.**

Cleanup happens in two places only, both of them *piggybacked on inbound HTTP traffic*:

1. **Synchronously, on the checkout path** — when the same customer comes back and starts
   a new checkout, the previous attempt is cancelled.
2. **A sweep at the end of `WebhookController`** — after every successfully processed
   Stripe webhook, contracts older than 30 minutes in `draft` / `not_finished` / `pending`
   are cancelled and their orders stornoed.

There is no cron entry, no scheduler, no console command, and no admin action that does this.
If no customer returns and no webhook arrives, nothing is ever cleaned.

The two console commands the Stripe module *does* register do something else:

| Command | Purpose |
|---|---|
| `stripe:reconcile-oxpaid` | Backfills `OXPAID` where Stripe says paid but the shop says unpaid (missed webhooks) |
| `stripe:prune-idempotency` | Deletes expired `oe_payments_idempotency` rows |

(Mollie mirrors only the first: `mollie:reconcile-oxpaid`. payment-base registers **no** commands at all.)

---

## 2. How the NOT_FINISHED order is produced

`StripePaymentHandler` (`src/Stripe/PaymentHandler/StripePaymentHandler.php:251-299`) creates
the order **before** the Stripe redirect so an order number exists:

```
Contract DRAFT
  → create oxorder with OXTRANSSTATUS = 'NOT_FINISHED'   (initialStatus in CreateOrderRequest)
  → Contract NOT_FINISHED (order id linked)
  → Contract PENDING
  → redirect to Stripe Checkout
```

If the customer closes the tab at Stripe, the shop is left with:

- `oxorder` row, `OXTRANSSTATUS = 'NOT_FINISHED'`, order number consumed
- `oe_payments_contract` row in `pending` (or `draft`, if it never got that far)
- vouchers marked used (`markVouchers()` ran during `finalizeOrder()`)
- stock decremented

---

## 3. The three real cleanup paths

### 3.1 Checkout-path cleanup (`RetryCleanupService`)

`src/Stripe/Service/RetryCleanupService.php` is the single implementation. It is called from
four places, all of them request-driven:

| Caller | Trigger |
|---|---|
| `PaymentController.php:90` | customer lands back on the payment step |
| `StripeOrderController.php:105` | stale contract detected on the order step |
| `StripeOrderController.php:521, 556-569` | cancel-return from Stripe; falls back to `cleanupForUser($userId)` when the session is gone |
| `OpcExternalReturnCleanupHandler.php:86` | one-page-checkout external return |

What it does (`cancelContractAndDeleteOrder`):

1. skip if the contract is terminal or committed;
2. `deleteNotFinishedOrder($orderId)`;
3. ask `CheckoutInFlightGuard` whether the Stripe session is still live and still matches
   the basket — if yes, **keep** it and return without cancelling;
4. otherwise `$contract->cancel('checkout_retry')` and save.

Note the naming: `deleteNotFinishedOrder()` **does not delete**. Since Sprint 88 (STRP-123)
it resets the vouchers and then sets `OXSTORNO = 1` and `OXTRANSSTATUS = 'CANCELLED'`
(`OxidShopOrderService.php:270-292`), so the order-number sequence keeps no gaps. It also
guards on the current status being exactly `NOT_FINISHED` and returns `false` otherwise.

### 3.2 The webhook sweep (the only "background" job)

`WebhookController.php:166` → `cleanupStaleNotFinishedOrders()` (line 270) →
`RetryCleanupService::cleanupStaleContracts(30)` →
`DoctrineContractRepository::findStaleNotFinished(30)`:

```sql
SELECT * FROM oe_payments_contract
WHERE OXSTATE IN ('draft','not_finished','pending')
  AND OXCREATED < DATE_SUB(NOW(), INTERVAL :minutes MINUTE)
ORDER BY OXCREATED ASC
```

Each hit goes through the same `cancelContractAndDeleteOrder()` as above.

Properties worth knowing:

- **Threshold is hardcoded.** `protected int $staleThresholdMinutes = 30;`
  (`WebhookController.php:37`) — no module setting, only a subclass seam.
- **Unbounded batch.** No `LIMIT`; the sweep runs inline in the webhook request, so a large
  backlog is paid for by one webhook's response time.
- **Runs only on success.** Failures `exit` earlier via `sendErrorResponse()`.
- **The in-flight guard is inert here.** `CheckoutInFlightGuard::readCurrentBasketTotal()`
  reads `Registry::getSession()->getBasket()` — inside a webhook request there is no
  customer session, so it throws, `inspect()` returns `null`, and the sweep proceeds.
  That is the intended outcome, but it is incidental rather than explicit.
- **No webhooks ⇒ no sweep.** This is the crux: on a shop where the webhook endpoint is not
  configured, or where the signing secret is wrong, the *only* GC in the system never runs.

### 3.3 `checkout.session.expired` webhook

`CheckoutSessionExpiredWebhookHandler` → `WebhookContractFulfillmentHandler::handleSessionExpired()`
(line 196). It calls `$contract->expire()` and saves — **and nothing else**.

This is a real gap: the cancel and fail paths in the same class *do* mirror onto the order
(`mirrorCancellationOnLinkedOrder()` → `markCancelled()`, line 169-186;
`mirrorFailureOnLinkedOrder()` → `markFailed()`), but the expire path does not. So a contract
that expires by Stripe's own 24h session timeout leaves its `oxorder` row sitting at
`NOT_FINISHED` with vouchers still consumed — until the 30-minute sweep in 3.2 happens to
catch it. And it will not: `findStaleNotFinished()` only looks at
`draft` / `not_finished` / `pending`, and the contract is now `expired`. **Such an order is
unreachable by every cleanup path in the module.**

---

## 4. Dead code found

`ContractService::cleanupExpiredContracts()` (`payment-base/src/Service/ContractService.php:57`)
iterates `findExpired()` and expires each contract. **It has no production caller** —
`grep` across both repos finds only the definition, the interface, and one unit test
(`payment-base/tests/Unit/Service/ContractServiceTest.php:140`). It looks exactly like the body a
console command would have called; the command was never written.

---

## 5. Evidence from the dev shop (DB `example`, 2026-08-27)

```
oxorder by OXTRANSSTATUS        contracts by OXSTATE
  OK             294              cancelled   197
  NOT_FINISHED   108              draft        90
  (empty)         40              committed    43
  CANCELLED        1              expired      29
                                  fulfilled    26
                                  pending      24
                                  failed       16
```

- **84** of the 108 NOT_FINISHED orders are older than 30 minutes — i.e. they are all past
  the sweep threshold and were never collected.
- **94** contracts sit in `draft`/`not_finished`/`pending` older than 30 minutes — the exact
  result set `findStaleNotFinished(30)` would return. The sweep has not run.
- **72** NOT_FINISHED orders have *no* contract pointing at them via `OXORDERID` at all.
  `RetryCleanupService` reaches an order only through `$contract->getOrderId()`, so these are
  structurally unreachable by any existing cleanup code, whatever triggers it.
- No `Cleaned up N stale NOT_FINISHED order(s)` line appears in `source/log/oxideshop.log`.

(Dev-shop data, so the absolute numbers are noise — but the *shape* is the point: the only
GC in the system is gated on inbound webhooks, and on this shop it has never fired.)

A side observation, not chased down here: 18 `committed` and 18 `fulfilled` contracts point
at orders still at `OXTRANSSTATUS = 'NOT_FINISHED'`. That is a separate suspected leak in the
commit path (`ContractCommitmentHandler` should flip the order to `OK`), worth its own ticket.

---

## 6. Recommendation

> **Status update 2026-08-28 —** recommendation 1 is implemented:
> `bin/oe-console oe:payments:not_finished:cleanup` now exists in **payment-base**
> (STRP-168, merged to `b-7.4.x` as `f31daf7`), driven by a
> new "Cleanup period" module setting (`iPaymentBaseCleanupPeriod`, days, default 7)
> and keyed on `oxorder`, which also covers the 72 contract-less orders of item 5.
> Item 2 is implemented too: `handleSessionExpired()` now mirrors onto the linked
> order via the same path the cancel and fail branches use, and that mirror was
> given storno + voucher release so an ended order lands exactly where the cleanup
> command would have left it. That second half fixed a leak this report had not
> spotted — `markCancelled()`/`markFailed()` set `OXTRANSSTATUS` only, and since
> the cleanup command collects only orders still at `NOT_FINISHED`, moving the
> status was what put the row beyond its reach, stranding the customer's vouchers
> permanently. Items 3, 4 and 6 are still open.


The mechanism that exists is sound; what is missing is a trigger that does not depend on a
customer returning or Stripe calling us. Concretely:

1. **Add `stripe:cleanup-stale-orders`** — a thin `Command` over the existing
   `RetryCleanupService::cleanupStaleContracts()`, with `--minutes=` (default 30),
   `--limit=` and `--dry-run`, following the shape of `PruneIdempotencyCommand`
   (which was added in Sprint 133 for exactly this reason: a repository method with no
   production caller). Schedule from cron next to `stripe:prune-idempotency`.
   Better still, put it in **payment-base**, since the service only depends on
   `ContractRepositoryInterface` + `ShopOrderServiceInterface` — both agnostic — and Mollie
   has the identical early-order problem.
2. **Close the expired-contract gap** — have `handleSessionExpired()` mirror onto the linked
   order the way the cancel/fail paths already do (`markCancelled()`), *or* add `expired` to
   the state list in `findStaleNotFinished()`. The first is more correct; the second is the
   safety net.
3. **Make the threshold configurable** — promote `staleThresholdMinutes` to a module setting
   rather than a hardcoded protected property, so shops with long Klarna/bank-transfer flows
   can raise it.
4. **Bound the sweep** — add a `LIMIT` to `findStaleNotFinished()` so a backlog cannot stall
   a webhook response; the cron command can then loop.
5. **Reachability for contract-less orders** — the 72 unreferenced NOT_FINISHED orders need a
   cleanup keyed on `oxorder` itself (age + `OXTRANSSTATUS='NOT_FINISHED'` + Stripe payment
   id), not on the contract table. Worth confirming first *how* they lost their contract link.
6. **Delete or wire up** `ContractService::cleanupExpiredContracts()` — right now it is a
   trap for the next reader, who will assume expiry is handled.
