# Analysis: Prefilling Customer Email on Stripe Checkout Page

**Date:** 2026-02-06
**Status:** Analysis Complete
**Scope:** Stripe Checkout Session `customer_email` parameter

---

## Problem

When a customer is redirected to the Stripe Checkout page, the email field is empty. The customer must type their email again, even though OXID already knows it (they are logged in). This adds friction to checkout.

---

## Current State

### What Exists

1. **Module setting already defined** in `metadata.php:83`:
   ```php
   ['group' => 'STRIPE_GENERAL', 'name' => 'blStripeProvideCustomerEmailAddress', 'type' => 'bool', 'value' => '0', 'position' => 37]
   ```

2. **Configuration accessor exists** in `ModuleConfigurationService.php:173`:
   ```php
   public function shouldProvideCustomerEmail(): bool
   {
       return (bool) $this->get('blStripeProvideCustomerEmailAddress');
   }
   ```

3. **Admin translations exist** (EN + DE) explaining the feature.

4. **User object is already in EventContext** — the controller puts it there at `StripeOrderController.php:112`:
   ```php
   $context = new EventContext([
       'user' => $user,   // OXID User object with email
       ...
   ]);
   ```

### What's Missing

The **connection** between these pieces. Nobody reads `shouldProvideCustomerEmail()` and nobody passes `customer_email` to the Stripe API. The feature is wired in config but **not in the checkout flow**.

### Current Checkout Session Params (`CheckoutSessionService.php:79-89`)

```php
$params = [
    'mode' => 'payment',
    'line_items' => $lineItems,
    'success_url' => $successUrl,
    'cancel_url' => $cancelUrl,
    'metadata' => $sessionMetadata,
    'payment_intent_data' => [
        'capture_method' => $captureMode,
        'metadata' => $paymentIntentMetadata,
    ],
    // customer_email is NOT here
];
```

---

## Stripe API: Email Prefill Options

### Option A: `customer_email` (string)

```php
$params['customer_email'] = 'user@example.com';
```

| Aspect | Behavior |
|--------|----------|
| Email on checkout page | Prefilled, **read-only** (locked) |
| Creates Stripe Customer | Yes, automatically after payment |
| Prefills saved cards | No |
| Prefills billing address | No |
| Complexity | Minimal — just add one param |

### Option B: `customer` (Stripe Customer ID)

```php
$params['customer'] = 'cus_ABC123';
```

| Aspect | Behavior |
|--------|----------|
| Email on checkout page | Prefilled from Customer object, **read-only** |
| Creates Stripe Customer | No, uses existing |
| Prefills saved cards | Yes (if saved with `allow_redisplay: always`) |
| Prefills billing address | Yes (from card billing_details) |
| Complexity | High — requires managing Stripe Customer lifecycle |

**Important:** `customer` and `customer_email` are **mutually exclusive**. Passing both causes an API error.

### Comparison

| | `customer_email` | `customer` |
|--|---|---|
| Effort | ~1 hour | ~8-16 hours (Customer lifecycle) |
| Risk | None | Customer sync, data consistency |
| UX improvement | Email prefilled | Email + card + address prefilled |
| Prerequisites | None | `oe_payments_customer` table integration |

---

## Recommendation: `customer_email` (Option A)

Use `customer_email` because:

1. **Infrastructure already exists** — setting, accessor, user in context
2. **Minimal change** — add one parameter to the params array
3. **No new dependencies** — no Stripe Customer object management needed
4. **Gated by existing toggle** — admin can enable/disable via `blStripeProvideCustomerEmailAddress`
5. **No risk** — email is already known, read-only on Stripe page is correct behavior

Option B (`customer`) is the right long-term approach for saved cards/vaulting but requires full Customer lifecycle management, which is a separate feature.

---

## Implementation Path

### Data Flow

```
OXID User (oxuser.OXUSERNAME = email)
  → Controller puts user in EventContext
    → StripeCheckoutSessionHandler extracts email from context
      → CheckoutSessionService receives email parameter
        → Stripe API: params['customer_email'] = email
          → Stripe Checkout page shows prefilled, locked email
```

### Files to Modify (4 files)

| # | File | Change |
|---|------|--------|
| 1 | `CheckoutSessionServiceInterface.php` | Add `?string $customerEmail = null` parameter |
| 2 | `CheckoutSessionService.php` | Accept email param, add to `$params` if provided |
| 3 | `StripeCheckoutSessionHandler.php` | Extract user email from context, pass to service |
| 4 | `CheckoutSessionServiceTest.php` | Test: email included when provided, omitted when null |

### Key Code Change (`CheckoutSessionService.php`)

```php
// After building $params array:
if ($customerEmail !== null) {
    $params['customer_email'] = $customerEmail;
}
```

### Key Code Change (`StripeCheckoutSessionHandler.php`)

```php
// Extract email from user in context
$user = $context->get('user');
$customerEmail = null;
if ($user instanceof \OxidEsales\Eshop\Application\Model\User) {
    $email = $user->getFieldData('oxusername');
    if (is_string($email) && $email !== '') {
        $customerEmail = $email;
    }
}
```

### Config Gate

The handler should check `ModuleConfigurationService::shouldProvideCustomerEmail()` before extracting email. If disabled (default), no email is passed.

This requires injecting `ModuleConfigurationService` into the handler (or passing the flag via EventContext from the controller, which already has access to config).

---

## Caveats

1. **Email is locked (read-only)** — customer cannot change it on the Stripe page. If the OXID account has a wrong email, the customer is stuck.

2. **Stripe receipts sent to this email** — per the admin setting description, enabling this means Stripe sends payment confirmation to the customer's email instead of the merchant's Stripe account email.

3. **Guest checkout** — if OXID supports guest checkout, user email may still be available from the basket/delivery address.

4. **Default is OFF** — `'value' => '0'` in metadata.php. Must be explicitly enabled by admin.

---

## Effort Estimate

| Task | Effort |
|------|--------|
| Modify interface + service + handler | ~30 min |
| Unit tests | ~30 min |
| Manual QA (enable setting, checkout, verify email on Stripe page) | ~15 min |
| **Total** | **~1.5 hours** |
