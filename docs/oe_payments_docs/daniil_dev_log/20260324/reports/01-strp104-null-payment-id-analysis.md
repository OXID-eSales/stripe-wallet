# Report: STRP-104 Empty Shipping Triggers 500 on Payment Page

**Date:** 2026-03-24
**Ticket:** STRP-104
**Branch:** `b-7.4.x-empty-shipping-STRP-104`

---

## Bug Summary

`PaymentController::isStripeSelected()` passes `null` to `str_starts_with()`, causing a `TypeError` and 500 error when a user changes their delivery address to a country with no available shipping methods.

## Error

```
[2026-03-24 11:47:31] OXID Logger.ERROR: str_starts_with(): Argument #1 ($haystack)
must be of type string, null given
  at extensions/stripe/src/Stripe/Controller/PaymentController.php:132
```

## Root Cause

`Basket::getPaymentId()` returns `null` when no shipping method is available (despite PHPDoc declaring `@return string`). The Stripe module's `isStripeSelected()` passed this `null` directly to `str_starts_with()` which is strict in PHP 8.

## Fix Applied

### Layer 1 — PHP (Defense)

**File:** `src/Stripe/Controller/PaymentController.php`

```php
// BEFORE:
return str_starts_with($selectedPayment, 'oe_payments_stripe_');

// AFTER:
return is_string($selectedPayment) && str_starts_with($selectedPayment, 'oe_payments_stripe_');
```

### Layer 2 — Frontend (UX)

**File:** `views/twig/extensions/themes/default/page/checkout/payment.html.twig`

Added `{% block checkout_payment_nextstep %}` override:
- When `oView.getAllSets()` or `oView.getPaymentList()` is empty → shows `MESSAGE_NO_SHIPPING_METHOD_FOUND` warning and disables the "Weiter" button (grey, non-clickable).

## Test Coverage

**File:** `tests/Unit/Stripe/Controller/PaymentControllerNullPaymentIdTest.php`

| Test | Purpose |
|------|---------|
| `testStrStartsWithThrowsTypeErrorOnNull` | Documents the PHP 8 strict behavior |
| `testValidatePaymentDoesNotThrowWhenPaymentIdIsNull` | Proves null paymentId is handled |
| `testValidatePaymentStillDetectsStripePayment` | Stripe detection still works |
| `testValidatePaymentDoesNotThrowWhenPaymentIdIsEmptyString` | Empty string edge case |

## Verification

- **Unit tests:** 784 pass, 0 failures
- **Integration tests:** 164 run, 9 pre-existing WebhookEndpointE2ETest failures (unrelated)
- **PHPCS:** 0 errors | **PHPStan:** 0 errors (level max) | **PHPMD:** 0 errors
