# 2026-02-27 Status

## Fixed
- [CRITICAL] Payment amount mismatch between cart and Stripe Checkout → FIXED (TDD)
  - Report: `reports/01-checkout-amount-mismatch-fix.md`
  - Root cause 1: `CheckoutSessionService::buildLineItems()` ignored `snapshot->getDiscounts()`
  - Root cause 2: `ContractService::extractDiscounts()` missed vouchers (OXID separates them from basket discounts)
  - Fix 1: Extended `extractDiscounts()` to also extract vouchers via `basket->getVouchers()`
  - Fix 2: `buildLineItems()` checks `getDiscounts()` explicitly — uses `totalGross` when non-empty
  - TDD: 8 new tests
  - Verified: 723 unit tests pass, 1728 assertions

- [CRITICAL] Corrupted oxorder data (empty billing, no products, all totals 0.00) → FIXED
  - Report: `reports/03-corrupted-oxorder-wrong-payment-id.md`
  - Root cause: `EarlyOrderCreationHandler` hardcoded `paymentId = 'oxidstripe'` instead of `'oe_payments_stripe_wallet'`
  - OXID `finalizeOrder()` returned `ORDER_STATE_INVALIDPAYMENT`, creating empty order shell
  - Fix 1: `StripeOrderController` now passes `paymentId` in event context
  - Fix 2: `EarlyOrderCreationHandler` reads `paymentId` from context (provider-agnostic)
  - Fix 3: `StripeOrderCreationHandler` fallback uses `StripeDefinitions::STRIPE_WALLET_PAYMENT_ID`
  - 2 new tests, 7 updated tests
  - Verified: 723 unit tests pass, 1728 assertions

## Reports
- `reports/01-checkout-amount-mismatch-fix.md` — TDD fix for Stripe amount mismatch
- `reports/02-fulfilled-work-summary.md` — Full summary of all 3 bugs fixed (Feb 26-27)
- `reports/03-corrupted-oxorder-wrong-payment-id.md` — Fix for corrupted oxorder data (wrong payment ID)
