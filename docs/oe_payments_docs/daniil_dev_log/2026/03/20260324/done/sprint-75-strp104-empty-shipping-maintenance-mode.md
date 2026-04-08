# Sprint 75: STRP-104 Empty Shipping Triggers 500 Error on Payment Page

**Date:** 2026-03-24
**Ticket:** STRP-104
**Branch:** `b-7.4.x-empty-shipping-STRP-104`

---

## Problem Description

### What Happens

A user on the **order page** (`cl=order`) edits their delivery address, changing it to a country with no available shipping methods. OXID redirects them back through the checkout flow to the **payment page** (`cl=payment`). On the payment page, no shipping methods are available for the new address, so the basket's `paymentId` is `null`. When the user clicks the **"Weiter" (Next) button**, `PaymentController::validatePayment()` is called, which calls `isStripeSelected()`, which passes `null` to `str_starts_with()` — causing a **TypeError** and a **500 error**.

### Error Log

```
[2026-03-24 11:47:31] OXID Logger.ERROR: str_starts_with(): Argument #1 ($haystack) must be of type string, null given
  at /var/www/extensions/stripe/src/Stripe/Controller/PaymentController.php:132
```

### Stack Trace

```
PaymentController->isStripeSelected()                    ← line 132: str_starts_with(null, ...)
PaymentController->validatePayment()                     ← line 97
BaseController->executeFunction('validatepayment')
ShopControl->executeAction(PaymentController, 'validatepayment')
ShopControl->process(...)
ShopControl->start()
```

### Steps to Reproduce

1. Complete checkout up to the order page (`cl=order`) with a valid address and Stripe payment.
2. On the order page, click "Edit" on the shipping address → redirected to `cl=user`.
3. Change the country to one with **no available shipping methods** for the basket.
4. Click "Weiter" on the user page → redirected to `cl=payment`.
5. On the payment page, the shipping method section is empty (no methods for this country).
6. Click "Weiter" (Next) → **500 error**.

### Root Cause

**File:** `src/Stripe/Controller/PaymentController.php`, line 128-133:

```php
private function isStripeSelected(): bool
{
    $selectedPayment = Registry::getSession()->getBasket()->getPaymentId();
    // ↑ Returns null when no shipping method → no payment method available
    return str_starts_with($selectedPayment, 'oe_payments_stripe_');
    // ↑ TypeError: str_starts_with() Argument #1 must be string, null given
}
```

When no shipping method is available for the delivery address, OXID does not assign a payment method to the basket, so `getPaymentId()` returns `null`. PHP 8's `str_starts_with()` is strict — it does not accept `null`.

---

## Fix Strategy: Two Layers

### Layer 1 — PHP Fix (Defense): Null-safe `isStripeSelected()`

**File:** `src/Stripe/Controller/PaymentController.php`

**One-line fix:**
```php
// BEFORE (buggy):
return str_starts_with($selectedPayment, 'oe_payments_stripe_');

// AFTER (fixed):
return is_string($selectedPayment) && str_starts_with($selectedPayment, 'oe_payments_stripe_');
```

This prevents the 500 error. When `paymentId` is null, `isStripeSelected()` returns `false`, the Stripe validation block is skipped, and `validatePayment()` returns normally.

### Layer 2 — Frontend Fix: Disable "Weiter" Button When No Shipping Available

**The problem:** On the payment page (`payment.html.twig`), the "Weiter" button is always active — even when there are no shipping methods and no payment methods available. The user can click it and submit an invalid form.

**The button** (OXID core template, `payment.html.twig:153-156`):
```twig
<button type="button" class="btn btn-highlight btn-lg w-100"
        onclick="document.querySelector('#payment').requestSubmit();">
    {{ translate({ ident: "NEXT" }) }}
</button>
```

This button is NOT in its own Twig block — it's inline inside `{% block checkout_payment %}`. We cannot override just the button via Twig block inheritance.

**Approach: JavaScript injected via `{% block checkout_payment_nextstep %}`**

The Stripe module's `payment.html.twig` already extends the core template. We add a `checkout_payment_nextstep` block override (the block exists but is empty in the core template at line 74). This block is inside the payment form, so we use `DOMContentLoaded` to target the button in the sibling column:

```twig
{% block checkout_payment_nextstep %}
    {% if not oView.getAllSets() %}
        <div class="alert alert-warning mt-3">
            {{ translate({ ident: "MESSAGE_NO_SHIPPING_METHOD_FOUND" }) }}
        </div>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                var btn = document.querySelector('button[onclick*="requestSubmit"]');
                if (btn) {
                    btn.disabled = true;
                    btn.classList.remove('btn-highlight');
                    btn.classList.add('btn-secondary');
                    btn.removeAttribute('onclick');
                }
            });
        </script>
    {% endif %}
{% endblock %}
```

**Result:** When no shipping methods are available:
- The button becomes visually disabled (grey `btn-secondary` style, `disabled` attribute)
- The `onclick` handler is removed (no form submission possible)
- A warning message appears in the payment section: "No shipping method found"

---

## TDD Steps

### Step 1: Failing Test (DONE)

**File:** `tests/Unit/Stripe/Controller/PaymentControllerNullPaymentIdTest.php`

| Test | Status | Assertion |
|------|--------|-----------|
| `testStrStartsWithThrowsTypeErrorOnNull` | PASSES | Documents the PHP 8 behavior that causes the bug |
| `testValidatePaymentDoesNotThrowWhenPaymentIdIsNull` | **FAILS** | Proves the bug: `isStripeSelected()` crashes on null |
| `testValidatePaymentStillDetectsStripePayment` | PASSES | Stripe payment ID is correctly detected |
| `testValidatePaymentDoesNotThrowWhenPaymentIdIsEmptyString` | PASSES | Empty string doesn't crash (str_starts_with handles it) |

Run:
```bash
docker compose exec -T php php vendor/bin/phpunit -c extensions/stripe/tests/phpunit.xml \
  extensions/stripe/tests/Unit/Stripe/Controller/PaymentControllerNullPaymentIdTest.php
```

Result: `Tests: 4, Assertions: 5, Failures: 1`

### Step 2: Apply PHP Fix (Layer 1)

**File:** `src/Stripe/Controller/PaymentController.php`, line 130-132

**Change:**
```php
// BEFORE:
$selectedPayment = Registry::getSession()->getBasket()->getPaymentId();
return str_starts_with($selectedPayment, 'oe_payments_stripe_');

// AFTER:
$selectedPayment = Registry::getSession()->getBasket()->getPaymentId();
return is_string($selectedPayment) && str_starts_with($selectedPayment, 'oe_payments_stripe_');
```

**Verify:** Re-run the 4 tests — all must pass.

### Step 3: Apply Frontend Fix (Layer 2)

**File:** `views/twig/extensions/themes/default/page/checkout/payment.html.twig`

**Change:** Add `{% block checkout_payment_nextstep %}` override to disable the button when `oView.getAllSets()` is empty.

**Verify:** Manual testing — navigate to payment page with no-shipping-country, confirm button is disabled and grey.

### Step 4: Run Full Pre-commit Check

```bash
docker compose exec -w /var/www/extensions/stripe -T php ./bin/pre-commit-check.sh --full
```

---

## Decision Points

1. **Should the warning message use an existing OXID translation key?**
   OXID already has `MESSAGE_NO_SHIPPING_METHOD_FOUND` (used in `iPayError == -2` block on the payment page). We could reuse this. Or should we add a Stripe-specific message?

2. **Should we also disable the button when `oView.getPaymentList()` is empty?**
   If there are shipping methods but no payment methods, the button is still useless. But the core template already handles this case with `oView.getEmptyPayment()` (shows "no payment method" message with `oxempty` hidden input). The form can still be submitted with `paymentid=oxempty`. Should we disable the button in this case too?

3. **Should the JavaScript use OXID's `{{ script() }}` helper instead of inline `<script>`?**
   `{{ script() }}` defers scripts to the page footer, which guarantees the button DOM exists. But it adds the script for ALL pages, not just when shipping is missing. The inline approach with `DOMContentLoaded` is more targeted.

---

## Principles Applied

- **TDD:** Failing test first, then fix
- **Defense in depth:** PHP null-check (prevents crash) + frontend disable (prevents bad UX)
- **Minimal change:** One-line PHP fix, small template addition
- **No overengineering:** No new Stimulus controllers or complex JS — simple inline script
