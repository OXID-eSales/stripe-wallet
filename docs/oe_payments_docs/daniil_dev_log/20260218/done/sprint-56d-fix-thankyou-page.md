# Sprint 56d: Fix ACP ThankYou Page

**Status:** DONE
**Date:** 2026-02-18
**Branch:** `b-7.4.x-mcp-STRP-88`

## Problem

After ACP checkout via Stripe, the user returns to the shop but OXID's ThankYouController redirects to the start page instead of showing the order confirmation.

## Root Cause

ThankYouController requires:
1. `sess_challenge` session variable (order ID) — set by controller, works
2. Basket with `getProductsCount() > 0` — **empty for ACP returns**
3. Order with `oxordernr` in DB — exists, works

The basket was created during the MCP API call and stored in a PHP session via `force_sid`. When the user's browser returns from Stripe, `force_sid` may not properly restore the basket because:
- The API session may have expired
- The basket may have been consumed during order creation
- PHP session storage may differ between API and browser contexts

## Fix

Added `ensureBasketForThankYouPage()` to `StripeOrderController::checkoutSuccess()`:

1. After event chain creates order and sets `redirectTarget='thankyou'`
2. Check if session basket has products
3. If empty, load contract from EventContext (set by `StripeCheckoutReturnHandler`)
4. Rebuild basket from contract's `BasketSnapshot` (always available from DB)
5. Set rebuilt basket on OXID session

Key design decisions:
- **Defensive**: runs for ALL checkout returns, not just ACP — handles any case where basket is missing
- **No-op when basket exists**: skips rebuild if session basket already has products (normal web flow)
- **Skips non-product items**: filters out shipping, payment fees, wrapping, gift cards from snapshot
- **Controller, not handler**: basket restoration requires OXID framework access (`Registry::getSession()->setBasket()`), which belongs in the controller layer

## Files Modified

| File | Changes |
|------|---------|
| `src/Stripe/Controller/StripeOrderController.php` | +`ensureBasketForThankYouPage()`, +`rebuildBasketFromSnapshot()` |
