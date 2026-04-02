# Sprint 79: E2E Test Suite — Minor Fixes

**Date:** 2026-03-30
**Branch:** `b-7.4.x`
**Priority:** MEDIUM — Fixes 4 of 41 failures + 2 dependent on Sprint 77

---

## Issues

### Issue 1: Cart Item Count (1 failure)

**Test:** `Cart - Add products and verify count`
**Error:** `Expected: >= 2, Received: 1`

**Analysis:** The header badge shows "2" (total quantity), but the cart page shows 1 line item (via `quantity inputs` count). The `getCartItemCount()` method counts input fields in the cart table — when 2 units of the same product are added, there's 1 row with qty=2.

**Fix:** Either:
- Change assertion to check total quantity (sum of all qty inputs) instead of row count
- Or ensure `addIfEmpty` adds 2 **different** products (it does — "Wishbone aluminum" + "Front wheel bearing"), so check why only 1 appears. May be a session/cookie issue where a previous test removed one.

### Issue 2: Cart Quantity Change (1 failure)

**Test:** `Cart - Change item quantity`
**Error:** `expect(newTotal).not.toEqual(initialTotal)` — both are 135.78

**Analysis:** `changeItemQuantity(0, 2)` updates the input value but the cart total doesn't change. The quantity update likely requires clicking an "Update" button or submitting the form, which the helper may not be doing.

**Fix:** After setting the quantity input value, trigger the form update (click update button or submit the cart form).

### Issue 3: Product Page Not Found (2 failures)

**Test:** `coupon-survives-back-navigation.spec.ts`
**Error:** `Could not find first product link in the product list`

**Analysis:** The test uses `ProductPage.openFirstProductDetails()` which looks for product links. The page may not be on a category listing, or the selectors don't match the current theme's product card HTML.

**Fix:** Check `ProductPage.ts:52` selectors. May need to navigate to a specific category first (e.g., `/en/Spares/`) before looking for products.

### Issue 4: Admin Transaction ID (2 failures — depends on Sprint 77)

**Test:** `Verify Stripe tab and transaction ID`
**Error:** Transaction ID is empty string, expected `pi_*` format.

**Analysis:** No successful Stripe checkouts have completed (all card input tests fail), so no orders have real PaymentIntent IDs. Once Sprint 77 fixes checkout, this should auto-resolve.

**Fix:** No code change needed — dependent on Sprint 77 creating successful orders.

## Tests This Fixes (4 + 2 dependent)

Direct fixes:
- CartBasket.spec.ts: Add products and verify count
- CartBasket.spec.ts: Change item quantity
- coupon-survives-back-navigation.spec.ts (x2)

Dependent on Sprint 77:
- stripe-admin-order.spec.ts: Verify Stripe tab and transaction ID (x2)
