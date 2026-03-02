# Fulfilled Work Summary — 2026-02-26 / 2026-02-27

**Branch:** `b-7.4.x`
**Author:** Daniil
**Period:** 2026-02-26 – 2026-02-27

## Bugs Fixed: 3

### BUG #1: Order Totals Show 0.00 After Refund [HIGH]
**Report:** `20260226/reports/01-refund-zeroed-order-totals-bug.md`

**Problem:** After a full refund, the OXID admin order info block displayed all amounts as 0.00 EUR (gross, net, shipping, total).

**Root cause:** `OxidStockRestorationService::restoreStockForOrder()` marked all order articles as `oxstorno = 1`, then called `$order->recalculateOrder()`. OXID's recalculate rebuilds a virtual basket excluding storno'd articles — resulting in an empty basket with zero totals that overwrote the DB.

**Fix:** Removed `$order->recalculateOrder()` call from `OxidStockRestorationService`. Stock restoration and storno marking still work; order totals remain intact for accounting.

**Files changed:**
- `src/Stripe/Service/OxidStockRestorationService.php` — removed recalculateOrder() call

---

### BUG #2: "Can only add refunded amount in FULFILLED state" Error [HIGH]
**Report:** `20260226/reports/02-refund-contract-state-error.md`

**Problem:** Admin refund showed error "Can only add refunded amount in FULFILLED state" despite the Stripe refund succeeding. On retry: "This order has been refunded completely already."

**Root cause:** `StripeRefundRequestHandler::updateContractState()` called `$contract->addRefundedAmount()` without checking if the contract was in FULFILLED state. When contract was still COMMITTED (webhook hadn't arrived or fulfillment skipped), the DomainException propagated as a false error — but the Stripe API refund had already succeeded.

**Fix:** Added state guard before `addRefundedAmount()`. If contract is not FULFILLED, log a warning and return gracefully instead of throwing.

**Files changed:**
- `src/Stripe/EventSystem/Handler/StripeRefundRequestHandler.php` — added state check
- `tests/Unit/Stripe/EventSystem/Handler/StripeRefundRequestHandlerTest.php` — fixed existing test + added new test

---

### BUG #3: Payment Amount Mismatch Between Cart and Stripe Checkout [CRITICAL]
**Reports:** `20260226/reports/03-checkout-amount-mismatch.md` (analysis), `20260227/reports/01-checkout-amount-mismatch-fix.md` (fix)

**Problem:** The grand total in the cart did not match the amount on the Stripe Checkout page. Customers were overcharged when discounts or vouchers were applied.

**Root cause:** `CheckoutSessionService::buildLineItems()` only read `snapshot->getItems()` (products + shipping) and completely ignored `snapshot->getDiscounts()` (vouchers, basket discounts). Stripe doesn't support negative line item amounts, so discounts could not be represented.

**Fix (TDD):** When discounts are present, replaced itemized line items with a single "Order Total" line item using `snapshot->getTotalGross()` — the authoritative total from OXID's basket engine. When no discounts, kept itemized display.

**Files changed:**
- `src/Stripe/Service/CheckoutSessionService.php` — refactored `buildLineItems()` into 3 methods
- `tests/Unit/Stripe/Service/CheckoutSessionServiceTest.php` — added 6 new TDD tests

---

## Test Results

| Metric | Before | After | Delta |
|--------|--------|-------|-------|
| Unit tests | 714 | 721 | +7 |
| Assertions | 1707 | 1724 | +17 |
| Failures | 0 | 0 | — |

**New tests added:**
1. `testSkipsRefundAmountWhenContractNotInFulfilledState` — guards non-fulfilled refund recording
2. `testBuildLineItemsTotalMatchesTotalGrossWithDiscounts` — multiple discounts
3. `testBuildLineItemsTotalMatchesTotalGrossWithSingleDiscount` — single discount
4. `testBuildLineItemsNoDiscountsStillMatchesTotalGross` — no-discount baseline
5. `testBuildLineItemsIncludesDiscountsAsVisibleItems` — discount visibility
6. `testBuildLineItemsWithMultipleDiscountsAndShipping` — complex basket
7. `testCreateSessionSendsCorrectAmountWithDiscounts` — end-to-end Stripe API

## Files Modified (Total: 5)

| File | Change |
|------|--------|
| `src/Stripe/Service/OxidStockRestorationService.php` | Removed `recalculateOrder()` call |
| `src/Stripe/EventSystem/Handler/StripeRefundRequestHandler.php` | Added FULFILLED state guard |
| `src/Stripe/Service/CheckoutSessionService.php` | Refactored `buildLineItems()` for discount support |
| `tests/Unit/Stripe/EventSystem/Handler/StripeRefundRequestHandlerTest.php` | +1 test, fixed existing |
| `tests/Unit/Stripe/Service/CheckoutSessionServiceTest.php` | +6 tests, updated helper |
