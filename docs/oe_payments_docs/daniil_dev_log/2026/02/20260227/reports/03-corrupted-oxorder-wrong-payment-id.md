# FIX: Corrupted oxorder Data — Wrong Payment ID

**Date:** 2026-02-27
**Severity:** CRITICAL
**Status:** FIXED

## Problem

After checkout, the oxorder record was created with empty/corrupted data:
- Empty billing address (OXBILLFNAME, OXBILLLNAME, OXBILLSTREET, etc.)
- No products in order articles
- All totals 0.00 (OXTOTALORDERSUM, OXTOTALBRUTSUM, OXDELCOST, etc.)
- Empty payment type (OXPAYMENTTYPE)
- Empty shipping method (OXDELTYPE)

The order shell existed but contained no meaningful data.

## Root Cause

`EarlyOrderCreationHandler` (payment-component) hardcoded `paymentId = 'oxidstripe'` at line 107. The correct payment ID is `'oe_payments_stripe_wallet'` (`StripeDefinitions::STRIPE_WALLET_PAYMENT_ID`).

When OXID's `Order::finalizeOrder()` receives an invalid payment ID, it returns `ORDER_STATE_INVALIDPAYMENT` (state 5). This causes the order to be created as an empty shell — the order record exists in the database but `finalizeOrder()` skips populating billing address, articles, totals, shipping, and payment data.

Same wrong fallback `'oxidstripe'` existed in `StripeOrderCreationHandler` line 169.

## Why This Happened

`EarlyOrderCreationHandler` is in `payment-component` (provider-agnostic package), so it cannot import `StripeDefinitions`. The payment ID should come from the event context, but the controller wasn't passing it.

## Solution (3 Files)

### 1. `src/Stripe/Controller/StripeOrderController.php`
Added `'paymentId' => StripeDefinitions::STRIPE_WALLET_PAYMENT_ID` to the event context when creating the `StripeCheckoutSessionRequestEvent`. This makes the correct payment ID available to all downstream handlers.

### 2. `payment-component/src/EventSystem/Handler/EarlyOrderCreationHandler.php`
Changed hardcoded `$paymentId = 'oxidstripe'` to read from context:
```php
$contextPaymentId = $context->get('paymentId');
$paymentId = is_string($contextPaymentId) ? $contextPaymentId : 'unknown_payment';
```
Now the handler is truly provider-agnostic — it uses whatever payment ID the provider module passes in the context.

### 3. `src/Stripe/EventSystem/Handler/StripeOrderCreationHandler.php`
Changed fallback from `'oxidstripe'` to `StripeDefinitions::STRIPE_WALLET_PAYMENT_ID`:
```php
$paymentId = $basket->getPaymentId() ?? StripeDefinitions::STRIPE_WALLET_PAYMENT_ID;
```

## Tests

### New tests added to `EarlyOrderCreationHandlerTest.php`:
- `testHandlerUsesPaymentIdFromContext` — verifies `CreateOrderRequest` receives `'oe_payments_stripe_wallet'` from context
- `testHandlerFallsBackToUnknownWhenNoPaymentIdInContext` — verifies fallback to `'unknown_payment'` (safe failure)

### Updated existing tests:
- All 7 existing tests updated to pass `paymentId` in `EventContext`

## Verification

- payment-component: 9 tests, 28 assertions — all pass
- Stripe unit suite: 723 tests, 1728 assertions — all pass
- No regressions

## Historical Note

This was a known issue from Sprint 56b (documented in MEMORY.md): "Use StripeDefinitions constants for payment IDs — never hardcode payment ID strings." The hardcoded `'oxidstripe'` was left from early development before the payment ID constant was established.
