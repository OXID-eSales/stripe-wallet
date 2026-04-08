# Report: E2E Playwright Test Suite Analysis

**Date:** 2026-03-30
**Branch:** `b-7.4.x`
**Goal:** Achieve working E2E test suite (max 5% skip/fail rate)

---

## Test Run Summary

| Metric | Count | Percentage |
|--------|-------|------------|
| Total tests | 104 | 100% |
| Passed | 57 | 54.8% |
| Failed | 41 | 39.4% |
| Did not run | 6 | 5.8% |

**Target:** Max 5% failures = max 5 tests can be skipped/failing.
**Current gap:** 41 failures need to be resolved, reducing to ~5.

---

## Failure Categories

### Category 1: Stripe Checkout Card Input Timeout (20 failures)

**Pattern:** `TimeoutError: locator.waitFor: Timeout 20000ms exceeded` waiting for `input[aria-label="Card number"]`

**Affected test files:**
- `tests/e2e/Checkout.spec.ts` (3 tests)
- `tests/e2e/GuestOrders.spec.ts` (8 tests)
- `tests/e2e/PaymentEdgeCases.spec.ts` (6 tests)

**Root cause:** After redirecting to `checkout.stripe.com`, the test waits for the card number input field but Stripe Checkout renders the payment form inside iframes. The selector `input[aria-label="Card number"]` is looking in the main frame, but Stripe's card input is inside a nested iframe (`__privateStripeFrame`).

**Fix approach:**
- The `fillStripeCardDetails` and `fillStripeCardDetailsWithCard` methods in `Helper.ts` (lines 1215 and 2388) need to first locate the Stripe iframe and switch context into it before looking for the card input.
- Alternative: Stripe Checkout (hosted page) uses a different DOM structure than Stripe Elements. The card number input may have a different selector on the hosted checkout page.

### Category 2: Coupons Rejected by Shop (13 failures)

**Pattern:** `[Coupon] Coupon "E2E_10PCT" was rejected by the shop`

**Affected coupons:** `E2E_10PCT`, `E2E_50PCT`, `E2E_50FLAT`, `E2E_5FLAT`, `E2E_100PCT`, `E2E_SINGLE`

**Affected test files:**
- `tests/e2e/CouponsDiscounts.spec.ts` (10 tests)
- `tests/e2e/GuestOrders.spec.ts` (3 coupon tests)

**Root cause:** The test coupons (E2E_10PCT, E2E_50PCT, etc.) do not exist in the shop database or have expired/been exhausted. The `assertCouponApplied` method in `Helper.ts:1719` detects a rejection error message on the page.

**Fix approach:**
- Create the test coupon voucher series and vouchers in the OXID database (oxvoucherseries + oxvouchers tables)
- Or: Add a setup script/fixture that seeds the required coupons before the test suite runs

### Category 3: Stripe Checkout Page Load Timeout (2 failures)

**Pattern:** `TimeoutError: page.waitForLoadState: Timeout 60000ms exceeded` at `StripeCheckoutPage.ts:50`

**Affected tests:**
- `tests/checkout/stripe-checkout.spec.ts` (2 instances - checkout-tests + chromium projects)

**Root cause:** `waitForLoadState('networkidle')` never resolves on Stripe Checkout because Stripe continuously polls with XHR requests (analytics, status checks). The page never becomes "network idle."

**Fix approach:**
- Replace `waitForLoadState('networkidle')` with `waitForLoadState('domcontentloaded')` or wait for a specific element (e.g., the email field or pay button) instead.

### Category 4: Admin Transaction ID Empty (2 failures)

**Pattern:** `expect(received).toMatch(/^pi_[a-zA-Z0-9]+$/)` — Received: `""`

**Affected tests:**
- `tests/admin/stripe-admin-order.spec.ts` test #2 (2 instances - admin-tests + chromium)

**Root cause:** The orders in the admin panel don't have a Stripe PaymentIntent ID (transaction ID) stored. This means either:
1. No successful Stripe checkout has been completed recently (checkout tests are failing), so no orders have real transaction IDs
2. The Stripe tab DOM selector for transaction ID is not matching the actual rendered HTML

**Fix approach:**
- This is a dependency on Category 1 — once checkout tests work and create real orders, the transaction ID will be populated
- May also need to verify the admin page selector for extracting the transaction ID

### Category 5: Product Page Not Found (2 failures)

**Pattern:** `Could not find first product link in the product list`

**Affected tests:**
- `tests/checkout/coupon-survives-back-navigation.spec.ts` (2 instances)

**Root cause:** The `ProductPage.openFirstProductDetails()` method can't find a product link. The test navigates to a category page but the product list isn't rendered or uses different selectors than expected.

**Fix approach:**
- Check the product list page selectors in `ProductPage.ts`
- Verify the shop has products in the default category
- May need to navigate to a specific category URL rather than relying on default landing

### Category 6: Cart State Issues (2 failures)

**Tests:**
- `Cart - Add products and verify count`: Expected >=2, got 1
- `Cart - Change item quantity`: Total didn't change after quantity update

**Root cause:**
- Count issue: Badge shows "2" but cart page shows 1 line item (likely 2 units of same product counted as 1 row)
- Quantity issue: `changeItemQuantity` may not be triggering the cart update (missing form submit or AJAX refresh)

**Fix approach:**
- For count: Distinguish between "number of line items" vs "total quantity" — the cart has 1 line item with qty 2
- For quantity: Ensure the update button is clicked / form is submitted after changing the quantity input

---

## Priority Order for Fixes

| Priority | Category | Tests Fixed | Effort |
|----------|----------|-------------|--------|
| 1 | Stripe Checkout card input (iframe) | 20 | Medium — iframe frame switching |
| 2 | Coupon database seeding | 13 | Low — SQL insert fixtures |
| 3 | networkidle timeout | 2 | Low — change wait strategy |
| 4 | Cart state logic | 2 | Low — fix assertion logic |
| 5 | Product page selectors | 2 | Low — fix selectors |
| 6 | Admin transaction ID | 2 | Depends on #1 |

**Fixing priorities 1-3 would resolve 35 of 41 failures (85%).**

---

## Passing Tests (57) — What Works

- Admin authentication setup
- Admin payment date validation (OXTRANSSTATUS checks)
- Admin Stripe dashboard link verification
- Admin capture mode settings (UI tolerance)
- Admin refund flow (UI tolerance)
- Basic login test
- Cart total calculation verification
- Cart remove item / clear cart
- Cart empty message display
- Mini basket item count
- Navigate to checkout from cart
- Price verification (list vs cart, VAT, shipping, discounts)
- Guest user form fill
- Checkout shows same total as cart
- Invalid/expired/future coupon error handling
- No-stack coupon rejection
- Automatic discount verification