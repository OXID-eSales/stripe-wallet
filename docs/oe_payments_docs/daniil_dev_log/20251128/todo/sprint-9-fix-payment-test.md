# Sprint 9: Fix PaymentTest

**Status:** COMPLETE
**Estimated Hours:** 0.5h (actual)
**Priority:** MEDIUM (54 test failures → 0)

## Problem Analysis

The `PaymentTest` tests are written for **legacy** payment methods that are no longer supported.

### Important Clarification (2025-11-28)

> **User Note:** `stripecreditcard`, `stripesepa` -- these are **LEGACY** -- Stripe now uses only **digital wallet** (`osc_stripe_wallet`)

### Test Expectations (OUTDATED)
```php
// Tests expect these LEGACY payment IDs:
'stripecreditcard', 'stripesepa', 'stripeideal', 'stripegiropay',
'stripebancontact', 'stripesofort', 'stripeeps', 'stripeprzelewy24'

// Tests expect prefix check:
str_starts_with($paymentId, 'stripe')
```

### Actual Implementation (CURRENT)
```php
// Payment model uses ONLY digital wallet:
private const STRIPE_PAYMENT_METHODS = [
    StripeDefinitions::STRIPE_WALLET_PAYMENT_ID  // 'osc_stripe_wallet'
];

// Prefix check:
return str_starts_with($paymentId, 'osc_stripe');
```

## Decision: Option A - Update Tests to Match Current Implementation

Since legacy payment methods (`stripecreditcard`, `stripesepa`, etc.) are **deprecated** and Stripe now uses only digital wallet, we should:

1. **Remove tests for legacy payment methods**
2. **Update tests to use `osc_stripe_wallet`**
3. **Test only supported features for wallet type**

### Supported Features for `osc_stripe_wallet`
- `saved_cards` ✓
- `refunds` ✓
- `partial_refunds` ✓

### Features NOT Supported (Remove Tests)
- `3ds` (handled by Stripe automatically in wallet)
- `recurring` (not exposed as separate feature)

## Tasks

### Task 9.1: Update PaymentTest Data Providers

**File:** `tests/Unit/Stripe/Model/PaymentTest.php`

Replace legacy payment method data providers:

```php
// OLD (REMOVE):
public static function stripePaymentMethodsProvider(): array
{
    return [
        ['stripecreditcard'],
        ['stripesepa'],
        ['stripeideal'],
        // ...
    ];
}

// NEW:
public static function stripePaymentMethodsProvider(): array
{
    return [
        ['osc_stripe_wallet'],
    ];
}
```

### Task 9.2: Update Feature Tests

Update feature tests to only test supported features:

```php
// Test refunds support
public function testSupportsStripeFeatureReturnsTrueForRefunds(): void
{
    $payment = $this->createPaymentWithId('osc_stripe_wallet');
    $this->assertTrue($payment->supportsStripeFeature('refunds'));
}

// Test partial_refunds support
public function testSupportsStripeFeatureReturnsTrueForPartialRefunds(): void
{
    $payment = $this->createPaymentWithId('osc_stripe_wallet');
    $this->assertTrue($payment->supportsStripeFeature('partial_refunds'));
}

// Test saved_cards support
public function testSupportsStripeFeatureReturnsTrueForSavedCards(): void
{
    $payment = $this->createPaymentWithId('osc_stripe_wallet');
    $this->assertTrue($payment->supportsStripeFeature('saved_cards'));
}
```

### Task 9.3: Remove Legacy Tests

Remove or skip tests for:
- [ ] `stripecreditcard` tests
- [ ] `stripesepa` tests
- [ ] `stripeideal` tests
- [ ] `stripegiropay` tests
- [ ] `stripebancontact` tests
- [ ] `stripesofort` tests
- [ ] `stripeeps` tests
- [ ] `stripeprzelewy24` tests
- [ ] `3ds` feature tests
- [ ] `recurring` feature tests

### Task 9.4: Update isStripePaymentMethod Tests

```php
public function testIsStripePaymentMethodReturnsTrueForWallet(): void
{
    $payment = $this->createPaymentWithId('osc_stripe_wallet');
    $this->assertTrue($payment->isStripePaymentMethod());
}

public function testIsStripePaymentMethodReturnsFalseForLegacy(): void
{
    // Legacy methods should NOT be recognized anymore
    $payment = $this->createPaymentWithId('stripecreditcard');
    $this->assertFalse($payment->isStripePaymentMethod());
}

public function testIsStripePaymentMethodReturnsFalseForNonStripe(): void
{
    $payment = $this->createPaymentWithId('oxidcashondel');
    $this->assertFalse($payment->isStripePaymentMethod());
}
```

### Task 9.5: Update getStripePaymentMethodType Tests

```php
public function testGetStripePaymentMethodTypeReturnsWallet(): void
{
    $payment = $this->createPaymentWithId('osc_stripe_wallet');
    $this->assertEquals('wallet', $payment->getStripePaymentMethodType());
}

public function testGetStripePaymentMethodTypeReturnsNullForNonStripe(): void
{
    $payment = $this->createPaymentWithId('oxidcashondel');
    $this->assertNull($payment->getStripePaymentMethodType());
}
```

## Acceptance Criteria

- [ ] All PaymentTest tests pass
- [ ] No tests reference legacy payment methods (`stripecreditcard`, `stripesepa`, etc.)
- [ ] Tests only test `osc_stripe_wallet` payment method
- [ ] Feature tests cover: `refunds`, `partial_refunds`, `saved_cards`
- [ ] No tests for deprecated features (`3ds`, `recurring`)
- [ ] Tests follow TDD principles

## Migration Note

If legacy payment method support is needed in the future, it should be implemented as a **separate module** or **backward compatibility layer**, not by modifying the current digital wallet implementation.
