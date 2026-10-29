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
- 2026-10-29: **No fallbacks.** Each step has one straightforward path. A failure is a failure, not a switch to another behaviour.
- 2026-10-29: **One order — one contract.** An order never has more than one contract, and a contract never has more than one order. Thus "keep the order" means: keep the order **and** its contract. Section 4 is rewritten for this; S2b (several contracts for each order) is reverted.

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
| G9 | Each early order takes the stock (`finalizeOrder()`). The retire (`deleteNotFinishedOrder()`, SQL storno) does not restock. Found in S0 (100 → 98 after one retired and one open attempt). | `payment-base/src/Repository/DoctrineNotFinishedOrderRepository.php::cancelOrder()` | Each retired attempt loses one unit of stock for good. The adoption avoids it; the fallback retire must restock (S4). |

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

## 4. Solution: keep the order and its contract (rewritten 2026-10-29)

**Decisions:** keep the order; no fallbacks; one order — one contract.

### 4.1 Principle

- The customer has one checkout attempt for each session: one order and one contract. When the customer changes the payment method, or retries, **the same contract and the same order** continue. The contract is moved to the new payment attempt; the order is rewritten in place.
- The contract keeps its id, its order id and its order number. These are replaced: provider, provider order id (`OXPROVIDERORDERID`), `payment_method_id`, the provider data. The replaced provider attempt goes into the contract's history (metadata `replaced_attempts`: provider, provider order id, time).
- A PSP event for a replaced provider attempt finds no contract by its provider order id. Thus it cannot change the order. Money that arrives on it is refunded (4.6).
- The order always follows the current basket. There is no "basket unchanged" precondition and no alternative path.

### 4.2 The 1:1 rule is enforced (S2b, new)

1. Migration: unique index on `oe_payments_contract.OXORDERID`. When rows with the same order id exist, the migration stops and lists them; it does not choose a row. (Local DB today: one real order with two cancelled Mollie contracts, plus test rows `order-a`…`order-d`.)
2. `PaymentContract`: the order id is set once (`transitionToNotFinished()`). `commitToOrder()` with a different order id throws. No method changes the order id.
3. Tests: migration up/down; a second contract for the same order id is refused by the DB; `commitToOrder()` with another id throws.

### 4.3 Flow on Place Order while the session has an open attempt (contract C, order O)

1. Read C from `OpenCheckoutAttemptRegistry`. C is in `not_finished` or `pending`, O is `NOT_FINISHED`. (Without an open attempt the normal flow creates C and O, as today.)
2. Ask the current provider of C whether it is paid. Paid: commit C (S3); the checkout is complete; the customer goes to the thank-you page. This is the fact "the customer has paid", not an alternative path.
3. Close the current payment page of C at the provider: Stripe — expire the Checkout Session; Mollie — cancel the payment; PayPal — nothing (PayPal moves money only on our capture call). A provider refusal is recorded in the history entry; money that arrives later is refunded (4.6).
4. Move C to the new attempt: `PaymentContract::rebindProvider(provider, paymentMethodId)` — allowed in `not_finished` and `pending` only; moves the current provider data into `replaced_attempts`; clears the provider order id.
5. Rewrite O from the session basket in one DB transaction (`ShopOrderServiceInterface::rewriteOrder(O, paymentId)`): core `finalizeOrder($basket, $user, true)` keeps order id and number; remove `sess_challenge` first when it names O; set `OXTRANSSTATUS` back to `NOT_FINISHED`; `OXORDERDATE` = now. Order articles, stock and vouchers follow the basket (S2c spike verifies how core's recalculate mode treats them and closes each gap). Render the order mails again and store them on C (STRP-176 store).
6. The new provider creates its payment page for C with the same order number. The registry keeps C.

### 4.4 What goes away (no fallbacks, no second path)

| Code | Why it goes |
|------|-------------|
| Retire of the previous attempt in `EarlyOrderCreationHandler` / `PayPalOrderCreationHandler` for the session's open attempt | Replaced by 4.3. The cleaner stays only for the ending of an attempt (cleanup command). |
| Retire on the return from a cancelled PSP page: OPC `ExternalReturnAttemptCleanupHandler`, Stripe `OpcExternalReturnCleanupHandler`, Mollie `AbandonedAttemptCleanup`, PayPal `CheckoutCancellation` | The attempt stays open; the next Place Order continues it (4.3). An attempt the customer leaves for good is ended by the not-finished cleanup command (with voucher release and restock, S4). |
| OPC-96 voucher snapshot / unwind / restore, OPC-185 unwind + re-add in `setPaymentMethod()` | The voucher stays on O (S2a); nothing is dropped, nothing must be restored. |
| S2b "active contract" rule and `ContractOrderOwnership` | Reverted 2026-10-29 (payment-base `5b1ed6a`, stripe `4b4e86c`, mollie `efd9ca5`): one order has one contract. |
| Fallbacks in S1/S2a/S3 code | Removed 2026-10-29: payment-base `6da11f1` (Voucher fails instead of falling back to core), stripe `566e24b` (required dispatcher, settler and commit service; a Stripe error is not "unpaid"). |

### 4.5 Coupon (S2a, done)

The voucher row of O stays on O for the whole attempt. The `Voucher` extension accepts it in each recalculation. When the basket changes its vouchers, step 4.3.5 marks the new ones and releases the removed ones on O.

### 4.6 Money on a replaced provider attempt (S2g)

- `ContractCommitService` and the provider webhook handlers find the contract by the **current** provider order id only. An event for a replaced provider attempt does not match: the order cannot change.
- New: before "contract not found" is answered, the handler looks up `replaced_attempts`. A "paid" event for a replaced attempt → full refund through the provider (`RefundIntentEventInterface`), recorded in the history entry (refund id or error). Once for each provider payment id.
- Admin: the Payment tab of O shows each replaced attempt with money and its refund state. A refund error shows "Refund manually" and logs `ERROR`.

### 4.7 Stories

| Story | Content | Repo | Estimate |
|-------|---------|------|----------|
| S2b | 1:1 rule enforced (4.2) | payment-base | S |
| S2c | Spike + `rewriteOrder()`: order articles, stock, vouchers, totals, mails follow the basket (4.3.5) | payment-base | M |
| S2d | `rebindProvider()` + history; close the payment page for each provider (4.3.3–4) | payment-base, stripe, mollie-payment, paypal | M |
| S2e | Flow 4.3 in the shared chain (`EarlyOrderCreationHandler`, `PayPalOrderCreationHandler`, Stripe via S1); remove the code in 4.4 | payment-base, one-page-checkout, stripe, mollie-payment, paypal | M |
| S2g | Refund of money on a replaced attempt + admin (4.6) | payment-base, stripe, mollie-payment, paypal | M |

S2f (mails) is part of S2c.

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

**Status:** done 2026-10-29 (not committed). Report `../reports/01-OPC-175-proof-2026-10-29.md`.
- Playwright `opc-175-coupon-survives-payment-switch.spec.ts` (Stripe→Mollie, Mollie→Stripe, PayPal→Stripe): the retire checks are green (S1); the coupon checks are red — the coupon is lost already at the **first** Place Order, so the second order has no discount.
- Integration: payment-base `RetireReleasesVoucherTest` green; mollie `LinkedOrderEndReleasesVoucherTest` 2 red (G6); paypal `AuthorizationVoidedReleasesVoucherTest` red (G7).
- New gap **G9**: each early order takes the stock; the retire does not restock (100 → 98 after one retired + one open attempt).
- Not done: the roundtrip spec with a real payment (the card form in the iframe is not automatable here) and the standard `cl=order` regression spec — moved to S5.

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

**Status:** dropped 2026-10-29. S2a closes G3–G5; section 4.3 continues the same contract and order. There is no fallback retire. The text stays for reference.

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
- ~~When Stripe cannot answer, the retire continues.~~ Changed 2026-10-29 (no fallbacks, stripe `566e24b`): a Stripe error reaches the caller; nothing is cancelled on a guess. The settler and the commit service are required.
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
