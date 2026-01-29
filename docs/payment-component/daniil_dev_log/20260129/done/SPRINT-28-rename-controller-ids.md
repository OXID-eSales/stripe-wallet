# SPRINT-28: Rename Controller IDs

**Status:** TODO
**Priority:** MEDIUM
**Effort:** 1-2h

---

## Objective

Rename controller IDs in `metadata.php` to follow consistent naming convention (class names as IDs).

---

## Core Requirements

| Principle | Description | Application |
|-----------|-------------|-------------|
| **TDD-First** | Write failing tests first, then implementation | Update MetadataTest first |
| **Single Responsibility (S)** | Each controller has one purpose | Clear naming reflects purpose |
| **Liskov Substitution (L)** | Subtypes substitutable for base types | Controller IDs are stable contracts |
| **DRY** | Don't Repeat Yourself | Consistent naming pattern |
| **Clean Code** | Meaningful names | Controller IDs match class names |
| **PSR-12** | PHP coding style standard | Enforced by phpcs |

---

## ID Mapping

| OLD ID | NEW ID | Class |
|--------|--------|-------|
| `osc_stripe_payment` | `StripePaymentController` | `PaymentController` |
| `osc_stripe_webhook` | `StripeWebhookController` | `WebhookController` |
| `StripeConnect` | `StripeConnect` | `StripeConnect` (no change) |
| `stripe_order_refund` | `OrderRefund` | `OrderRefund` |
| `orderController` | `StripeOrderController` | `StripeOrderController` |

---

## Testing Requirements

### Pre-commit Validation (MANDATORY)

```bash
# After EACH file change:
./bin/pre-commit-check.sh --full
```

### Test Files to Update

| Test File | Changes Required |
|-----------|------------------|
| `tests/Integration/Module/MetadataTest.php` | Update controller ID assertions |

---

## Files to Modify

### 1. metadata.php (ALREADY DONE - verify)

**Status:** Already has new IDs

```php
'controllers' => [
    'StripePaymentController' => StripePaymentController::class,
    'StripeWebhookController' => StripeWebhookController::class,
    'StripeConnect' => StripeConnect::class,
    'OrderRefund' => OrderRefund::class,
    'StripeOrderController' => StripeOrderController::class,
],
```

### 2. menu.xml

**File:** `menu.xml`
**Line:** 6

```xml
<!-- BEFORE -->
<TAB id="STRIPE_ORDER_REFUND" cl="stripe_order_refund" />

<!-- AFTER -->
<TAB id="STRIPE_ORDER_REFUND" cl="OrderRefund" />
```

### 3. views/twig/admin/stripe_order_refund.html.twig

**File:** `views/twig/admin/stripe_order_refund.html.twig`
**Lines:** 5, 71, 176, 196, 223

Replace all occurrences:
```twig
<!-- BEFORE -->
<input type="hidden" name="cl" value="stripe_order_refund">

<!-- AFTER -->
<input type="hidden" name="cl" value="OrderRefund">
```

Also update comment on line 5:
```twig
<!-- BEFORE -->
Controller Key: stripe_order_refund

<!-- AFTER -->
Controller Key: OrderRefund
```

### 4. tests/Integration/Module/MetadataTest.php

**File:** `tests/Integration/Module/MetadataTest.php`
**Lines:** 153-174

```php
// BEFORE
$this->assertArrayHasKey(
    'osc_stripe_webhook',
    $controllers,
    'Webhook controller must be registered'
);

$this->assertArrayHasKey(
    'osc_stripe_payment',
    $controllers,
    'Payment controller must be registered'
);

$this->assertTrue(
    class_exists($controllers['osc_stripe_webhook']),
    'Webhook controller class must exist: ' . $controllers['osc_stripe_webhook']
);

$this->assertTrue(
    class_exists($controllers['osc_stripe_payment']),
    'Payment controller class must exist: ' . $controllers['osc_stripe_payment']
);

// AFTER
$this->assertArrayHasKey(
    'StripeWebhookController',
    $controllers,
    'Webhook controller must be registered'
);

$this->assertArrayHasKey(
    'StripePaymentController',
    $controllers,
    'Payment controller must be registered'
);

$this->assertTrue(
    class_exists($controllers['StripeWebhookController']),
    'Webhook controller class must exist: ' . $controllers['StripeWebhookController']
);

$this->assertTrue(
    class_exists($controllers['StripePaymentController']),
    'Payment controller class must exist: ' . $controllers['StripePaymentController']
);
```

### 5. src/Stripe/Service/ModuleConfigurationService.php

**File:** `src/Stripe/Service/ModuleConfigurationService.php`
**Line:** 255

```php
// BEFORE
return rtrim($shopUrl, '/') . '/index.php?cl=osc_stripe_webhook';

// AFTER
return rtrim($shopUrl, '/') . '/index.php?cl=StripeWebhookController';
```

### 6. recipe/var/configuration/shops/1/modules/oe_payments_stripe_wallet.yaml

**File:** `recipe/var/configuration/shops/1/modules/oe_payments_stripe_wallet.yaml`

Update the controllers section to match new IDs.

---

## Files NOT to Modify (Documentation Only)

The following files contain OLD IDs in documentation/comments but should NOT be modified as they are historical records:

- `docs/payment-component/daniil_dev_log/*/` - Historical dev logs
- `docs/payment-component/dev_history_phase_0/` - Historical documentation

---

## Implementation Order

1. **Update test first (TDD)**
   - Modify `MetadataTest.php` with new controller IDs
   - Run test - should FAIL (RED)

2. **Update source files**
   - `menu.xml`
   - `views/twig/admin/stripe_order_refund.html.twig`
   - `src/Stripe/Service/ModuleConfigurationService.php`
   - `recipe/var/configuration/shops/1/modules/oe_payments_stripe_wallet.yaml`

3. **Run tests**
   - Run test - should PASS (GREEN)

4. **Run full pre-commit checks**
   ```bash
   ./bin/pre-commit-check.sh --full
   ```

---

## Verification Checklist

```bash
# 1. Search for OLD controller IDs (should find only docs)
grep -r "osc_stripe_payment\|osc_stripe_webhook\|stripe_order_refund" \
  --include="*.php" --include="*.xml" --include="*.twig" --include="*.yaml" \
  extensions/stripe/src extensions/stripe/tests extensions/stripe/views extensions/stripe/menu.xml extensions/stripe/recipe

# 2. Pre-commit checks
./bin/pre-commit-check.sh --full

# 3. Module activation test
docker compose exec php bin/oe-console oe:module:deactivate oe_payments_stripe_wallet
docker compose exec php rm -rf var/cache/*
docker compose exec php bin/oe-console oe:module:activate oe_payments_stripe_wallet

# 4. Admin panel test
# - Navigate to Orders -> select order -> Stripe tab should load
```

---

## Impact Assessment

### Breaking Changes

**Webhook URL Change:**
- OLD: `index.php?cl=osc_stripe_webhook`
- NEW: `index.php?cl=StripeWebhookController`

**Action Required:** After deployment, update webhook URL in Stripe Dashboard.

### Non-Breaking Changes

- Admin menu tab (uses OXID internal routing)
- Template form submissions (internal to admin)

---

## Acceptance Criteria

1. [ ] All controller IDs use new naming convention
2. [ ] `MetadataTest.php` updated and passes
3. [ ] All templates use new controller IDs
4. [ ] `ModuleConfigurationService.php` returns new webhook URL
5. [ ] Pre-commit checks pass: `./bin/pre-commit-check.sh --full`
6. [ ] Module activates and deactivates correctly
7. [ ] Admin panel Stripe tab loads correctly
