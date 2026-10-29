# Sprint plan: Coupon stays applied when the customer changes the payment method (OPC, Stripe / Mollie / PayPal)

**Ticket:** OPC-175
**Branch:** `b-7.4.x-coupon-reaplying-OPC-175` in payment-base, stripe, one-page-checkout, mollie-payment, paypal (cut from `b-7.4.x`)
**Engineering rules:** `../../_engineering_requirements.md`
**Language:** ASD-STE100 (Simplified Technical English). Class, method, column and state names stay as in the code.
**Merge order:** payment-base → stripe → one-page-checkout → mollie-payment → paypal. Pin CI to the payment-base branch, then revert the pin after the merge.

**Decisions:**
- 2026-10-29: Keep the same `NOT_FINISHED` order when the customer changes the payment method (section 4, "order adoption"). The retire (cancel + new order) stays as the fallback only.
- 2026-10-29: The OPC Stripe path uses the same principles and methods as all other payment methods (S1, done).
- 2026-10-29: Mitigate the risk "money arrives on the old contract" (section 4.10).

**Definition of Done (sprint level):**
1. The customer applies a coupon in OPC. The customer changes the payment method one or more times (Stripe ↔ Mollie ↔ PayPal). The coupon stays in the basket. The order total stays the same.
2. In one session, a maximum of one order has the state `NOT_FINISHED`. Each earlier order of this session has `OXTRANSSTATUS = CANCELLED` and `OXSTORNO = 1`.
3. An order that ends without a payment (customer cancel, PSP cancel, expiry, failure, admin cancel, cleanup command) releases its voucher rows. The customer can use the coupon again.
4. A paid order never loses its voucher. The system never cancels a paid attempt.

---

## 1. Problem

The customer applies a coupon in OPC. Then the customer changes the payment method, for example from Mollie to Stripe. The coupon disappears from the basket.

## 2. Findings (verified on 2026-10-29)

### 2.1 How the coupon gets lost

1. Each payment attempt creates an early order with `OXTRANSSTATUS = NOT_FINISHED` before the customer pays. Core `Order::finalizeOrder()` calls `Voucher::markAsUsed()`. The voucher row then has `OXORDERID = <early order>`.
2. The customer selects a different payment method. Each recalculation of the basket (`Basket::calculateBasket(true)` → `calcVoucherDiscount()`, core `Basket.php:1071`) checks each voucher again. `Voucher::isAvailable()` (core `Voucher.php:214`) throws an exception when `OXORDERID` is not empty. The catch block removes the voucher from the basket (`Basket.php:1121-1123`). Core does not show an error.
3. Core `Order::validateOrder()` → `validateVouchers()` (core `Order.php:2066`) also refuses a new order with `ORDER_STATE_VOUCHERERROR` when a basket voucher row still has `OXORDERID`.
4. payment-base releases the voucher row only when it "retires" the previous attempt (`PreviousCheckoutAttemptCleaner::clean()` → `OxidShopOrderService::deleteNotFinishedOrder()` → storno + `releaseVouchers()`). This occurs too late and not for all providers. Refer to 2.2.

### 2.2 Gaps in the current code

| # | Gap | Location | Effect |
|---|-----|----------|--------|
| G1 | There are two different "open attempt" memories. Mollie and PayPal use the payment-base `OpenCheckoutAttemptRegistry` (`oepb_open_checkout_contract_id`). Stripe uses its own session key `stripe_contract_id` and `RetryCleanupService`. | `payment-base/src/Checkout/OpenCheckoutAttemptRegistry.php`, `stripe/src/Stripe/Service/RetryCleanupService.php` | A Mollie or PayPal attempt does not retire a Stripe attempt. A Stripe attempt does not retire a Mollie or PayPal attempt. The old order stays `NOT_FINISHED` and keeps the voucher. |
| G2 | The Stripe OPC (session) path calls `ShopOrderServiceInterface::createOrder()` directly. It does not dispatch `ContractDraftCompletedEvent`. Thus `EarlyOrderCreationHandler::retirePreviousAttempt()` does not run, and Stripe does not call `OpenCheckoutAttemptRegistry::remember()`. | `StripePaymentHandler::processPayment()` :126 → `createEarlyOrderAndTransition()` :311 | Same as G1. Also, core `validateVouchers()` can refuse the new Stripe order (`voucher_error`). |
| G3 | The retire step runs after the basket recalculation. Mollie (`MolliePaymentHandler::prepareOxidBasket()` :313), PayPal (`PayPalPaymentHandler` :210) and OPC (`CheckoutService::prepareOxidBasketForFinalize()` :607) call `calculateBasket(true)` before `ContractDraftCompletedEvent` (`CheckoutService` :504). `EarlyOrderCreationHandler` retires the previous attempt only after this event. | as given | The recalculation removes the voucher before the retire releases the row. |
| G4 | OPC-185 round 2 releases only the voucher row on a payment change (`PaymentMethodService::setPaymentMethod()` :307-310 → `OpcVoucherReservationUnwindService::unwindByIds()`). It does not cancel the old order or the old contract. | `one-page-checkout/src/Service/PaymentMethodService.php` | The old order stays `NOT_FINISHED` without a voucher. The data is not consistent. The fix works only when the voucher is still in the basket before this recalculation. Refer to G5. |
| G5 | Other OPC endpoints also recalculate the basket (`BasketService::recalculateBasket()`, `CheckoutStateService` :378, `BasketSummaryController` :194/:740, `ShippingMethodService`). They do not have the OPC-185 restore. | `one-page-checkout/src/Service/*` | When one of these calls runs first, the voucher is already gone. Then `setPaymentMethod()` has nothing to restore. |
| G6 | A Mollie webhook `canceled` / `failed` / `expired` sets only `OXTRANSSTATUS` (`OxidContractLinkedOrderUpdater::markCancelled()` :25, `markFailed()` :36). It does not set `OXSTORNO` and does not release the vouchers. The cleanup command finds only `NOT_FINISHED` orders (`DoctrineNotFinishedOrderRepository::findStaleNotFinishedOrderIds()` :49). | `mollie-payment/src/Mollie/Service/OxidContractLinkedOrderUpdater.php` | The voucher stays used for all time. |
| G7 | The PayPal webhook `PAYMENT.AUTHORIZATION.VOIDED` cancels only the contract. The order stays `NOT_FINISHED`. | `paypal/src/PayPal/WebhookHandler/PaymentAuthorizationVoidedHandler.php:34-46` | The voucher comes back only after the cleanup command runs (after N days). |
| G8 | Nothing dispatches `ContractCancelledEvent`. `ContractCleanupHandler` only saves the contract. A contract cancel does not cascade to the order or the vouchers. | `payment-base/src/EventSystem/Handler/ContractCleanupHandler.php` | Each provider must do the order and voucher work itself. G6 and G7 are the result. |

**Why Stripe does not use `OpenCheckoutAttemptRegistry` (answer to the question about G1, 2026-10-29).** There is no technical reason. The cause is the history of the code:

1. The Stripe **standard** checkout (`cl=order`) already uses the registry. `StripeContractCreationHandler` (:99) dispatches `ContractDraftCompletedEvent`. Then `EarlyOrderCreationHandler` (wired in `stripe/services.yaml:465-471` with `$openAttempts`) retires the previous attempt and remembers the new one.
2. Only the **OPC** path (`StripePaymentHandler`) does not use it. This handler creates the contract and the order itself. Its code comes from v3.1-rc.1 (2026-07-02). The registry came later (payment-base `633dd10`, STRP-171, 2026-09-02). Nobody changed `StripePaymentHandler` after that. Before the registry, Stripe had its own solution: `stripe_contract_id` + `RetryCleanupService` (STRP-100).
3. Two Stripe rules must stay when Stripe uses the registry:
   - **In-flight reuse.** OPC calls `processPayment()` many times. Stripe gives back the open session when it still fits (`PendingCheckoutReuse`, `745a193`, 2026-08-26). Thus the retire must run only after the reuse decision. If it runs on each call, it cancels the session that the customer is about to pay in. S1 follows this rule.
   - **Paid check.** `RetryCleanupService` asks Stripe whether the session is paid before it cancels. The payment-base cleaner does not ask. S3 adds this check.
4. `stripe_contract_id` has a second job: the return and cancel controllers use it to find the contract of the return. Keep it for this job. It is not necessary as a retire memory.
5. **Status 2026-10-29:** S1 and S3 close G1 and G2. Refer to the stories.

These paths are correct (no change necessary): admin cancel and admin delete (payment-base `Order::cancelOrder()` / `delete()`, setting `blPaymentBaseReleaseVouchersOnOrderEnd`, default on); Stripe webhook end (`Stripe\Service\OxidContractLinkedOrderUpdater`); checkout-retry cleanup and the cleanup command (`deleteNotFinishedOrder()` / `NotFinishedOrderCleanupService`).

### 2.3 Data in the local `example` DB (last 14 days)

| Payment | `NOT_FINISHED` | `CANCELLED` |
|---------|---------------:|------------:|
| `oe_payments_stripe_wallet` + `stripe_card` | 245 | 125 |
| `oe_payments_paypal*` | 60 | 88 |
| `oe_payments_mollie` | 13 | 3 |

One voucher row is still linked to a `NOT_FINISHED` order. Most of these rows are test residue. But the number of open `NOT_FINISHED` orders shows that the retire step often does not run (G1, G2).

---

## 3. Assessment of the proposed workflow

**Proposal:** When the customer changes the payment method, set the `NOT_FINISHED` order of this session to `CANCELLED` and release the coupon. The next Place Order creates a new `NOT_FINISHED` order.

**Result of the examination:**

1. The second half exists already. Each Place Order creates a new early order, because each provider needs an order number before the redirect.
2. The first half also exists already: `PreviousCheckoutAttemptCleaner` (STRP-171). But it runs too late (G3), and it does not see the attempts of all providers (G1, G2).
3. The proposal does not add order rows. The old order exists already. The change only cancels it earlier. Each change costs 1 order `UPDATE`, 1 voucher `UPDATE` and 1 contract save.
4. Thus the proposal is correct and efficient enough, on one condition: cancel only when an attempt exists **and** its payment method is different from the new one. Do not cancel on each click in the accordion.

**The part that is not efficient:** OPC prepares a Stripe Checkout Session, a contract and an early order when the customer only **selects** Stripe (before Place Order). Each such order uses an order number and runs a full `finalizeOrder()`. `PendingCheckoutReuse` decreases this. But a Stripe → Mollie → Stripe change still creates a second Stripe order when the system cancels the first one.

**Alternatives:**

| Alternative | Why not now |
|-------------|-------------|
| Keep the same `NOT_FINISHED` order and change only `OXPAYMENTTYPE` | **Requested on 2026-10-29. Refer to section 4 for the design.** |
| Create the Stripe early order only when the customer clicks Pay | This is the most efficient solution. But it changes the Stripe embedded flow (the session needs the order number). Record it as a follow-up. |
| Skip the voucher check during the recalculation (`setSkipVouchersChecking(true)`) | It also hides vouchers that are really not valid (expired, minimum value). |

**Recommended solution (small change):**

1. **Use one memory for the open attempt for all providers.** Stripe also writes its contract into `OpenCheckoutAttemptRegistry`, and it also retires the previous attempt before `createOrder()` (S1).
2. **Retire at the moment of the change, before the recalculation.** `PaymentMethodService::setPaymentMethod()` retires the open attempt when its payment method is different. This replaces the row-only unwind of OPC-185 round 2 (S2).
3. **Ask Stripe before the system cancels a Stripe attempt.** The retire must not cancel a paid Stripe session (lesson from GRAPH-QL / PS3) (S3).
4. **Make each "order ended" path release the vouchers.** Close G6 and G7 (S4).

The current retire in `EarlyOrderCreationHandler` and the OPC-96 snapshot restore stay as safety nets.

---

## 4. Solution: keep the same `NOT_FINISHED` order ("order adoption")

**Decision (2026-10-29): yes, keep the order.**

**Request (2026-10-29):** When the customer changes the payment method, keep the same `NOT_FINISHED` order. Change only the payment data of the order. Do not create a second order.

### 4.1 Principle

- **One order, one active contract.** The order stays. The **contract** changes, because a contract belongs to one provider (`provider`, `providerOrderId`, provider metadata, own state machine). The old contract gets the state `cancelled` (reason `payment_method_changed`). A new contract of the new provider gets the same `OXORDERID`.
- The order does not store a contract id (`OxidShopOrderService::storeOrderMetadata()` only logs). The only link is `oe_payments_contract.OXORDERID`. The index `IDX_ORDER` is not unique. Thus the table accepts a second contract for the same order without a migration.
- **Core already has the tool.** `Order::finalizeOrder($basket, $user, true)` (the "recalculate" mode of the admin) keeps the order id, the order number and the voucher marks. It does not run `validateOrder()`, `executePayment()`, `markVouchers()` or `sendOrderByEmail()`. It writes the new totals, the payment cost, the `oxuserpayments` row and `OXPAYMENTTYPE` (`Order::setPayment()`).

### 4.2 Flow on Place Order with the new method M2

Initial condition: the session has an open attempt: order O, contract C1, method M1 (from `OpenCheckoutAttemptRegistry`, after S1 also for Stripe).

1. Read the open attempt C1 and its order O.
2. Check the preconditions (4.3). If one precondition is false, use the fallback (4.4).
3. Invalidate the PSP attempt of C1 (4.6). If this fails, use the fallback.
4. Set C1 to `cancelled` (reason `payment_method_changed`). Do not cancel O. Do not release the vouchers.
5. The new provider creates contract C2 (no change).
6. Call `ShopOrderServiceInterface::adoptOrder(O, M2, C2)` instead of `createOrder()`. In one DB transaction:
   1. Load O. Put M2 on the session basket (`setPayment()`). Call `setSkipVouchersChecking(true)` (core does the same in `Order::getOrderBasket()`, `Order.php:1431`). Call `calculateBasket(true)`.
   2. Remove `sess_challenge` when it names O. If you do not remove it, `finalizeOrder()` answers `ORDEREXISTS` (`Order.php:474-479`).
   3. Call `finalizeOrder($basket, $user, true)`.
   4. Set `OXTRANSSTATUS` back to `NOT_FINISHED` (core sets `OK`). Set `OXORDERDATE` to now (core keeps the old date in this mode).
   5. Render the order mails again in capture mode (`deferConfirmationMail()` + `sendOrderByEmail()`). Store them on C2. Drop the mails of C1 (STRP-176 store). The old mails show the old payment method and the old total.
7. C2: `NOT_FINISHED(O)` → `PENDING` (no change). The registry remembers C2.
8. The PSP session of C2 gets the same order number as before.

### 4.3 Preconditions for the adoption

| # | Precondition | Why |
|---|--------------|-----|
| P1 | O has `OXTRANSSTATUS = NOT_FINISHED`, `OXSTORNO = 0`, no `OXPAID`. | Never change a paid or ended order. |
| P2 | C1 is not committed and not terminal. | Same rule as `PreviousCheckoutAttemptCleaner::isAbandonable()`. |
| P3 | The basket is the same as at the creation of O, except the payment. Compare a fingerprint: articles + amounts, voucher ids, shipping set, delivery address hash, currency, language. Store the fingerprint in the C1 metadata at order creation. | `finalizeOrder(…, true)` does not check stock, minimum value or vouchers again. This is safe only when the basket did not change. |
| P4 | The PSP attempt of C1 is invalidated (4.6). | The customer must not pay C1 after the adoption. |

### 4.4 Fallback

If a precondition is false, do the retire of S1/S2: cancel O and C1, release the vouchers, create a new order. The fallback is the current behaviour plus the S1–S4 fixes. Thus the adoption never makes a checkout fail.

### 4.5 The coupon between the change and Place Order

With the adoption, the voucher row stays linked to O all the time. Each OPC recalculation then removes the voucher (`Voucher::isAvailable()`; and `isAvailableInOtherOrder()` for a series with `OXALLOWUSEANOTHER = 0`). Thus payment-base must extend core `Voucher` (module `extend`):

- `isAvailable()` and `isAvailableInOtherOrder()` accept a row when its `OXORDERID` is the order of the open attempt of **this** session, and this order is `NOT_FINISHED` and not stornoed.
- All other rows keep the core check.

This extension keeps the coupon in the basket. The OPC-185 unwind and the OPC-96 snapshot restore then become safety nets only.

### 4.6 Invalidate the PSP attempt of C1

| Provider | Action | If not possible |
|----------|--------|-----------------|
| Stripe | Ask the session status. If `paid`: commit C1 (S3), no adoption. If `open`: expire the Checkout Session. | Fallback, do not adopt. |
| Mollie | Read the payment. If `paid`/`authorized`: no adoption. If `open` and `isCancelable`: cancel it. If `canceled`/`expired`/`failed`: nothing to do. | Payment is `open` and not cancelable → fallback. |
| PayPal | Read the order. If `COMPLETED` or an authorization exists: no adoption. If `CREATED`/`APPROVED`: nothing to do. PayPal takes no money without our capture call, and the guard in 4.7 stops this call. | Fallback. |

Add one narrow interface in payment-base: `CheckoutAttemptInvalidatorInterface::invalidate(PaymentContractInterface): bool`. Each provider has one implementation (tagged service, selected by `provider`).

### 4.7 One active contract for each order

- `DoctrineContractRepository::findByOrderId()` has no `ORDER BY` today (`WHERE OXORDERID = :orderId`). 28 files call it. Change only this query: first a contract that is not `cancelled`/`expired`, then the newest. Then all 28 callers get the active contract.
- Superseded-contract guard: a webhook or return for a contract that is not the active contract of its order must not change the order. This applies to `Stripe\Service\OxidContractLinkedOrderUpdater`, the Mollie `mirrorOnLinkedOrder()` and the PayPal approve/capture handlers. If money arrives on a superseded contract: do not change the order, log `ERROR`, and show it in the admin Payment tab. An automatic refund is a follow-up.
- The local DB has 5 orders with more than one contract today (test residue). S0 checks that the new query gives the correct contract for them.

### 4.8 Comparison

| Item | Retire on change (S2) | Order adoption (section 4) |
|------|----------------------|----------------------------|
| New orders for each change | 1 | 0 |
| Gaps in the order numbers | 1 for each change | none |
| Voucher | released, then marked again | stays on the same order |
| Stock | depends on S0 result | no change (same order) |
| Order mail | rendered for the new order | must be rendered again (4.2 step 6.5) |
| Contracts for each order | 1 | 1 active + n cancelled |
| Code | about 4 files, 2 repos | about 12–15 files, 4 repos |
| Main risk | cancel of an attempt that the customer pays | money on a superseded contract (4.7); stale data when P3 is wrong |

### 4.9 Stories (S2 becomes the fallback; S0, S1, S3, S4 stay)

| Story | Content | Repo | Estimate |
|-------|---------|------|----------|
| S2a | `Voucher` extension (4.5). Tests: a voucher of the open order of this session stays in the basket after `calculateBasket(true)`; a voucher of another order is still refused. | payment-base | S |
| S2b | Active contract rule in `findByOrderId()` + superseded-contract guard (4.7). | payment-base, stripe, mollie-payment, paypal | M |
| S2c | Basket fingerprint on the contract at order creation + `ShopOrderServiceInterface::adoptOrder()` (4.2 step 6, without mails). Integration test: same `OXID`, same `OXORDERNR`, new `OXPAYMENTTYPE`, new `OXPAYCOST`/totals, status `NOT_FINISHED`, voucher still linked. | payment-base | M |
| S2d | `CheckoutAttemptInvalidatorInterface` + one implementation for each provider (4.6). | payment-base, stripe, mollie-payment, paypal | M |
| S2e | Use the adoption in `EarlyOrderCreationHandler`, `PayPalOrderCreationHandler` and `StripePaymentHandler` (after the reuse decision). Fallback to the retire when a precondition is false. | payment-base, stripe, paypal | M |
| S2f | Render the order mails again on adoption, move them from C1 to C2. | payment-base | S |
| S2g | Refund money that arrives on a replaced contract; show it in the admin (section 4.10). | payment-base, stripe, mollie-payment, paypal | M |

**Recommendation.** The adoption is possible with the core recalculate mode, and no DB migration is necessary. It is about three times the code of S2. Do S0, S1, S3 and S4 first, because both variants need them. Then do S2a–S2f and S2g (section 4.10).

**Update after the decision.** S2 (retire in `setPaymentMethod()`) is not necessary any more:
- S2a keeps the voucher of the open order valid in each recalculation. This closes G3, G4 and G5.
- The fallback retire already exists in `EarlyOrderCreationHandler`. After S1 it runs for all providers. Thus the fallback needs no new code.

### 4.10 Risk mitigation: money arrives on the old contract

**Risk.** The customer pays the old attempt C1 after the system replaced C1 with C2. Order O then belongs to C2 and shows the new payment method.

**Where this can occur:**

| Provider | Scenario | Money moves without the shop? |
|----------|----------|-------------------------------|
| Stripe | The Stripe page or iframe of C1 is open in a second tab. Or the customer submits the iframe at the same time as the change. | Yes |
| Mollie | The Mollie page of C1 is open in a second tab. Many Mollie methods are not cancelable when `open`. Bank transfer can stay `open` for days. | Yes |
| PayPal | The customer approved C1. PayPal moves money only on our capture call. | No |

**What the code does today (verified 2026-10-29).** Each provider confirms a payment through `ContractCommitService::commit()` (Stripe webhooks and `PaidSessionSettler`, Mollie `WebhookContractFulfillmentHandler`, PayPal `CheckoutOrderApprovedHandler` and `PaymentCaptureCompletedHandler`). For a contract in a terminal state, `refusal()` returns `refused('contract_cancelled')` and logs an `ERROR`. Thus a replaced contract never changes the order. **The gap:** the PSP keeps the money. Nobody gives it back, and the admin sees only a log line. This gap also exists today for the retire (`checkout_retry`).

**Mitigation in four layers:**

| Layer | Action | Where |
|-------|--------|-------|
| L1 Prevent | Close the old payment page before the adoption (4.6). Stripe: if paid, commit C1 and do not adopt (done in S3); else expire the Checkout Session. If the expire fails because the session is complete, ask again: paid → commit C1, no adoption. Mollie: cancel the payment when `isCancelable`. PayPal: nothing to do (L2 stops the capture). | S2d |
| L2 Guard | A replaced contract never changes the order. This exists already in `ContractCommitService::refusal()`. Add one more test for each provider: a "paid" event for a cancelled contract does not change `OXPAID`, `OXTRANSSTATUS` or `OXPAYMENTTYPE` of O. PayPal: do not call capture for a cancelled contract. | S2b (tests), PayPal guard |
| L3 Refund | When `commit()` refuses a confirmation because the contract is cancelled, dispatch a full refund to the provider of the confirmation (payment-base `RefundIntentEventInterface`, the same path as the admin refund). Record the result on C1: metadata `late_payment` = amount, currency, time, refund id or error. Do this once (idempotent by the authorization id). | S2g |
| L4 Show | The admin Payment tab and the Help popup of O show a warning when a replaced contract of O has `late_payment`. A refund error shows "Refund manually" and logs an `ERROR`. The command `oe:payments:not_finished:cleanup` reports the number of open late payments. | S2g |

**Not recommended now:** "late winner" — the late payment of C1 wins, C2 is cancelled, and O goes back to C1. A cancelled contract is terminal in the state machine. This needs a new non-terminal state "superseded". Record it as a follow-up.

**Story S2g — Refund money that arrives on a replaced contract (estimate M, payment-base + each provider refund handler).**
- Tests first: `ContractCommitServiceTest::testCommit_CancelledContract_DispatchesFullRefundOnce`, `…_RefundFails_RecordsLatePaymentAndLogsError`, `…_SecondConfirmation_DoesNotRefundTwice`. One integration test for each provider with a signed webhook replay: the old contract is cancelled, the payment event arrives, the order O does not change, the refund request goes to the PSP sandbox.
- DoD: a sandbox run for Stripe (pay in a second tab after the change) and Mollie (pay on the open Mollie page after the change). Both payments come back as refunds. Order O stays unchanged. The admin sees the warning.


---

## Out of scope

- Create the Stripe early order only on Pay (follow-up, refer to section 3).
- Restock on retire. Do not change it in this sprint. S0 records whether the early order decreases the stock and whether the retire restocks it.
- A restore after the recalculation in each OPC endpoint (G5). S2 makes this unnecessary for a payment change. If S0 shows a different path, record it.
- A `ContractCancelledEvent` cascade in payment-base (G8). S4 closes the two known paths with less code.
- The standard (non-OPC) `cl=order` checkout. S0 includes one regression spec only.
- Removal of the existing test residue in the local DB.

## Risks and unknowns

- **A paid Stripe session.** The customer can pay in the Stripe iframe and click a different method at the same time. S3 asks Stripe first (`RetryCleanupService::settlePaidSession()`). If Stripe reports "paid", the system commits the attempt and does not cancel it.
- **Mollie and PayPal redirect in a second tab.** The customer can pay at Mollie in tab A and change the method in tab B. Then the retire cancels an attempt that the customer pays. The Mollie webhook for a cancelled contract must then commit or refund. S0 checks the current behaviour. If it is not safe, S2 retires only an attempt without an opened redirect (contract state `draft` / `not_finished`, not `pending` with a redirect URL).
- **A gap in the order numbers.** A cancelled order keeps its number. This is the current behaviour (MOL-18).
- **`sess_challenge`.** After the retire, `PreviousCheckoutAttemptCleaner` removes the challenge. If it does not, core answers `ORDEREXISTS`. S2 must use the cleaner, not a direct SQL statement.
- **The paid guard.** `OpcVoucherReservationUnwindService` has a paid-order guard. The cleaner releases by `OXORDERID` without this guard, but it cancels only non-terminal and non-committed contracts. S0 adds a test: a committed contract keeps its voucher.

---

## Story 0 — Proof: show the defect with tests before the change

**Why:** We do not have a reproduction in tests yet. Each later story must change a red test to green.
**Estimate:** M

**Tests first:**
- Playwright (`one-page-checkout/tests/e2e`), with a test voucher series that allows one use for each user (`OXALLOWUSEANOTHER = 0`):
  - `coupon-switch-stripe-to-mollie.spec.ts`: apply coupon → select Stripe (iframe loads, early order exists) → select Mollie. Expect: the coupon is in the basket. The total includes the discount.
  - `coupon-switch-mollie-to-stripe.spec.ts`: apply coupon → Place Order with Mollie → cancel on the Mollie page → return → select Stripe → Place Order. Expect: the coupon is in the basket. The system creates the Stripe order (no `voucher_error`).
  - `coupon-switch-paypal-to-stripe.spec.ts`: the same steps for PayPal.
  - `coupon-switch-roundtrip.spec.ts`: Stripe → Mollie → PayPal → Stripe, then pay with test card `4242…`. Expect: one paid order with the voucher. All other orders of this session are `CANCELLED` + `OXSTORNO = 1`. The voucher row has `OXORDERID = <paid order>`.
- Each spec reads the DB after each step: the number of `NOT_FINISHED` orders for this user (expect ≤ 1) and `oxvouchers.OXORDERID`.
- Integration (payment-base): `VoucherReleaseOnOrderEndTest`
  - `testAdminCancel_ReleasesVoucher` (green today)
  - `testCleanupCommand_ReleasesVoucherOfStaleNotFinishedOrder` (green today)
  - `testRetireOfCommittedContract_KeepsVoucher` (green today, protects the paid guard)
- Integration (mollie-payment): `testWebhookCanceled_StornosOrderAndReleasesVoucher`, the same for `failed` and `expired` (red today, G6).
- Integration (paypal): `testAuthorizationVoided_CancelsOrderAndReleasesVoucher` (red today, G7).
- Record: does the early order decrease the stock? Does the retire restock? Write the answer in the S0 report. Do not change the code.

**DevOps gate:** phpcs ✓ · phpstan ✓ · phpmd ✓ · the new tests are red for the documented reason only.
**DoD:** report `reports/01-OPC-175-proof-2026-10-29.md` with the red tests, the exact core message for each case, and the Playwright traces.

---

## Story 1 — OPC Stripe uses the common chain of all payment methods

**Status:** implemented 2026-10-29 on `b-7.4.x-coupon-reaplying-OPC-175` (stripe), not committed. The e2e part of the DoD is open (S0).

**Why:** G1 and G2. With one memory for each session, each provider retires the attempt of each other provider.
**Estimate:** S

**What was implemented (simpler than the plan below):** a new service `CheckoutAttemptRotation` was not necessary. `StripePaymentHandler` now uses the same chain as Mollie, PayPal and the Stripe `cl=order` checkout:
1. Create the contract (no change).
2. Dispatch `ContractDraftCompletedEvent`. The shared `EarlyOrderCreationHandler` retires the open attempt of this session, creates the `NOT_FINISHED` order, moves the contract `DRAFT → NOT_FINISHED → PENDING` and remembers it in `OpenCheckoutAttemptRegistry`. The headless path uses the same chain with `basketId`.
3. Check that the order exists (`assertEarlyOrderCreated()`). If not, stop before Stripe is called (`STRIPE_PAYMENT_FAILED`).
4. Create the Stripe Checkout Session (no change). The in-flight reuse still runs first and does not dispatch the event.
- `ShopOrderServiceInterface` is no longer a dependency of `StripePaymentHandler`. `$eventDispatcher` is new (`services.yaml`).
- Tests: `StripePaymentHandlerHeadlessTest` now runs the real chain with a mocked shop order service, plus 4 new tests (retire before `createOrder()`, remember the new contract, no order → no Stripe session, reuse → no retire). 13 tests green; `StripePaymentHandlerTest` 17, `…LanguageTest` 3 green.

The original plan follows for reference.

**Tests first:**
- `StripePaymentHandlerTest`
  - `testProcessPayment_SessionPath_RetiresPreviousAttemptBeforeCreateOrder` (the cleaner is called before `createOrder()`)
  - `testProcessPayment_SessionPath_RemembersNewContract`
  - `testProcessPayment_WhenInFlightCheckoutIsReused_DoesNotRetireAndDoesNotRemember`
  - `testProcessPayment_WhenRetireThrows_StillCreatesTheOrder` (best effort, as in `EarlyOrderCreationHandler`)
- Integration: `testMollieAttemptThenStripeAttempt_MollieOrderIsCancelledAndVoucherMovesToStripeOrder`.

**Implementation:**
1. payment-base: move the body of `EarlyOrderCreationHandler::retirePreviousAttempt()` into a small service `CheckoutAttemptRotation` with two methods: `retirePrevious(string $newContractId): void` and `remember(string $contractId): void`. `EarlyOrderCreationHandler` and `PayPalOrderCreationHandler` call it. There is no behaviour change for them (DRY: today there are two copies).
2. stripe: inject `CheckoutAttemptRotation` into `StripePaymentHandler`. In `createEarlyOrderAndTransition()`, on the session path only, call `retirePrevious()` before `createOrder()`. Call `remember()` after the save. The reuse path (`reuseCheckoutInFlight()`) does not call it.
3. stripe: keep `stripe_contract_id` for the return controllers. Do not remove it in this sprint.

**DevOps gate:** `./bin/pre-commit-check.sh` in payment-base, stripe, paypal ✓
**DoD:** the spec `coupon-switch-mollie-to-stripe` is green.

---

## Story 2 — OPC retires the open attempt when the payment method changes

**Status:** not necessary after the decision of 2026-10-29. S2a closes G3–G5. The fallback retire is in `EarlyOrderCreationHandler` (S1). The text stays for reference.

**Why:** G3, G4, G5. When the system releases the voucher row before the recalculation, the voucher stays in the basket. Then no restore is necessary.
**Estimate:** M

**Tests first:**
- `PaymentMethodServiceTest`
  - `testSetPaymentMethod_WhenOpenAttemptHasOtherMethod_RetiresItBeforeRecalculation`
  - `testSetPaymentMethod_WhenOpenAttemptHasSameMethod_DoesNotRetire` (the Stripe reuse stays possible)
  - `testSetPaymentMethod_WithoutOpenAttempt_DoesNotRetire`
  - `testSetPaymentMethod_WhenRetireFails_KeepsTheOpenAttemptAndStillRestoresTheVoucher` (fallback to the OPC-185 unwind)
- Integration: `testSwitchStripeToMollie_StripeOrderCancelledVoucherFreeAndStillInBasket`.

**Implementation:**
1. one-page-checkout: inject `OpenCheckoutAttemptRegistryInterface` and `PreviousCheckoutAttemptCleanerInterface` into `PaymentMethodService`.
2. In `setPaymentMethod()`, before `$basket->calculateBasket(true)`: read `peek()` and load the contract. If its `payment_method_id` is different from the new method, call `clean()`, then `takePrevious()`.
3. Keep `restoreVouchersDroppedByRecalculation()` as a fallback. After a successful retire, it must find nothing to restore. Log at `info` level when it restores a voucher, so that we can see a remaining path.
4. Do not retire in the other OPC endpoints (G5). If S0 shows that a different path still loses the voucher, record it.

**DevOps gate:** `./bin/pre-commit-check.sh --full` in one-page-checkout ✓
**DoD:** the specs `coupon-switch-stripe-to-mollie` and `coupon-switch-paypal-to-stripe` are green. After each step, a session has a maximum of one `NOT_FINISHED` order.

---

## Story 3 — Do not cancel a paid Stripe attempt

**Status:** implemented 2026-10-29 together with S1 (necessary for S1: the shared retire can now reach a Stripe attempt), not committed.
- `PaidSessionSettler` (new): the paid-session check moved out of `RetryCleanupService`. `RetryCleanupService` uses it, with no change of behaviour or wiring.
- `StripeAwareAttemptCleaner` (new): Symfony decorator of `PreviousCheckoutAttemptCleanerInterface`. A Stripe contract with a paid session is committed and not cancelled. Each other contract goes to the payment-base cleaner unchanged.
- When Stripe cannot answer, the retire continues (same rule as `RetryCleanupService` and `CheckoutInFlightGuard`). If it stops, the old order keeps the voucher and core refuses the new order.
- Tests: `PaidSessionSettlerTest` 4, `StripeAwareAttemptCleanerTest` 4, `RetryCleanupServicePaidSessionTest` 4, `RetryCleanupServiceTest` 13 — green. The container resolves `PreviousCheckoutAttemptCleanerInterface` to `StripeAwareAttemptCleaner` (checked 2026-10-29).
- Open: the sandbox DoD below.

**Why:** After S1 and S2, the payment-base cleaner can cancel a Stripe attempt. The payment-base cleaner does not ask Stripe. `RetryCleanupService` asks Stripe (`settlePaidSession()`).
**Estimate:** S

**Tests first:**
- `StripeAwareAttemptCleanerTest`
  - `testClean_StripeContractWithPaidSession_CommitsAndDoesNotCancel`
  - `testClean_StripeContractWithUnpaidSession_DelegatesToBaseCleaner`
  - `testClean_NonStripeContract_DelegatesToBaseCleaner`
  - `testClean_WhenStripeIsNotReachable_DoesNotCancel` (an unknown state keeps the order)

**Implementation:**
1. stripe: add `StripeAwareAttemptCleaner`, a Symfony decorator (`decorates: PreviousCheckoutAttemptCleanerInterface`). For a Stripe contract, it calls the paid-session check of `RetryCleanupService` first. Then it calls the inner cleaner.
2. Make `settlePaidSession()` public, or move it to a small service. Do not copy it.

**DevOps gate:** `./bin/pre-commit-check.sh --full` in stripe ✓
**DoD:** a sandbox run: pay in the iframe, then click Mollie immediately. The paid order stays and keeps its voucher.

---

## Story 4 — Each "order ended" path releases the vouchers (Mollie, PayPal)

**Why:** G6 and G7. Stripe already does this (`Stripe\Service\OxidContractLinkedOrderUpdater`).
**Estimate:** S

**Tests first:**
- The red tests of S0 for Mollie (`canceled`, `failed`, `expired`) and PayPal (`VOIDED`).
- `testMarkCancelled_OnPaidOrder_DoesNotReleaseVoucher` (Mollie).

**Implementation:**
1. mollie-payment: in `OxidContractLinkedOrderUpdater::markCancelled()` and `markFailed()`, also set `OXSTORNO = 1` and call `VoucherReleaseInterface::releaseVouchers()` when the order is not paid. Use the same sequence as the Stripe updater.
2. paypal: in `PaymentAuthorizationVoidedHandler::handle()`, after the contract cancel, call `ShopOrderServiceInterface::deleteNotFinishedOrder()` for the linked order (storno + voucher release; no change for an order that is not `NOT_FINISHED`).

**DevOps gate:** `./bin/pre-commit-check.sh --full` in mollie-payment and paypal ✓
**DoD:** the S0 integration tests for G6 and G7 are green.

---

## Story 5 — Full proof and closing report

**Estimate:** S

1. Run all S0 Playwright specs on the local shop (OPC on, iframe mode for Stripe). Run `coupon-switch-roundtrip` with a real sandbox payment.
2. Run the standard `cl=order` regression spec with a coupon.
3. Run the DB query of section 2.3 for the test user. Expect: the earlier orders are `CANCELLED` + `OXSTORNO = 1`, a maximum of one is `NOT_FINISHED`, and no voucher row is linked to a cancelled order.
4. Write `reports/02-OPC-175-closing-report-<date>.md`: test counts, gate output, traces, open follow-ups.
5. Do the story-done ritual: update `status.md`, write the `done/` report, play the sound.

---

## Follow-ups (not in this sprint)

- Create the Stripe early order only on Pay. This removes the order for each Stripe selection.
- After S1 is stable, remove `stripe_contract_id` and the Stripe-only retire in `RetryCleanupService`. Then one memory remains.
- Decide on a `ContractCancelledEvent` cascade in payment-base (G8).
- Restock on retire, if S0 shows that the early order decreases the stock.
