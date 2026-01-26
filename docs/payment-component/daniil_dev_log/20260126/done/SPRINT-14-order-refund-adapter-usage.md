# SPRINT-14: Fix OrderRefund Adapter Usage

**Priority:** HIGH
**Estimated Effort:** 2h
**Blocking:** Admin refund UI broken
**Decision:** Add `retrieveCharge()` to StripeAdapterInterface (confirmed)

---

## Problem Statement

The `OrderRefund` controller was updated to return `StripeAdapterInterface` instead of `StripeClient`, but still uses direct SDK patterns:

```php
// Line 851 - BROKEN: StripeAdapterInterface has no ->paymentIntents property
$this->_oStripeApiOrder = $this->getStripeApiRequestModel()->paymentIntents->retrieve($transId);

// Line 899 - BROKEN: StripeAdapterInterface has no ->charges property
$this->_oStripeApiCharge = $this->getStripeApiRequestModel()->charges->retrieve($sLastChargeId);
```

**PHPStan Errors:**
- Line 851: `Cannot access property $paymentIntents on StripeAdapterInterface`
- Line 855: `Cannot access property $latest_charge on mixed`
- Line 899: `Access to undefined property StripeAdapterInterface::$charges`
- Lines 901-903: `Cannot access property on mixed`

---

## Requirements

### R1: Add `retrieveCharge()` to StripeAdapterInterface
- Method signature: `retrieveCharge(string $chargeId): Charge`
- Returns Stripe Charge object
- Follows existing pattern (like `retrievePaymentIntent()`)

### R2: Update OrderRefund to use adapter methods
- Replace `->paymentIntents->retrieve()` with `->retrievePaymentIntent()`
- Replace `->charges->retrieve()` with `->retrieveCharge()`
- Maintain existing functionality

### R3: All tests must pass
- Unit tests
- Integration tests
- PHPStan level 6
- PHPCS PSR-12

---

## TDD Implementation

### Step 1: Write failing test for StripeAdapter::retrieveCharge()

```php
// tests/Unit/Stripe/Adapter/StripeAdapterTest.php

public function testRetrieveChargeReturnsCharge(): void
{
    $chargeId = 'ch_test123';
    $expectedCharge = $this->createMock(Charge::class);

    $chargesService = $this->createMock(\Stripe\Service\ChargeService::class);
    $chargesService->expects($this->once())
        ->method('retrieve')
        ->with($chargeId)
        ->willReturn($expectedCharge);

    $client = $this->createMock(StripeClient::class);
    $client->charges = $chargesService;

    $adapter = new StripeAdapter($client);

    $result = $adapter->retrieveCharge($chargeId);

    $this->assertSame($expectedCharge, $result);
}

public function testRetrieveChargeThrowsOnApiError(): void
{
    $chargesService = $this->createMock(\Stripe\Service\ChargeService::class);
    $chargesService->method('retrieve')
        ->willThrowException(new \Stripe\Exception\InvalidRequestException('Not found'));

    $client = $this->createMock(StripeClient::class);
    $client->charges = $chargesService;

    $adapter = new StripeAdapter($client);

    $this->expectException(PaymentAdapterException::class);
    $adapter->retrieveCharge('ch_invalid');
}
```

### Step 2: Add method to interface

```php
// src/Stripe/Adapter/StripeAdapterInterface.php

use Stripe\Charge;

/**
 * Retrieve a Stripe Charge.
 *
 * Used by OrderRefund controller to get charge details for refund display.
 *
 * @param string $chargeId Stripe Charge ID (ch_xxx)
 * @return Charge Stripe Charge object
 * @throws PaymentAdapterException On API errors
 */
public function retrieveCharge(string $chargeId): Charge;
```

### Step 3: Implement in adapter

```php
// src/Stripe/Adapter/StripeAdapter.php

use Stripe\Charge;

/**
 * @inheritDoc
 */
public function retrieveCharge(string $chargeId): Charge
{
    try {
        return $this->stripeClient->charges->retrieve($chargeId);
    } catch (ApiErrorException $e) {
        throw $this->convertStripeException($e);
    }
}
```

### Step 4: Update OrderRefund controller

```php
// src/Stripe/Controller/Admin/OrderRefund.php

// Line 851 - BEFORE:
$this->_oStripeApiOrder = $this->getStripeApiRequestModel()->paymentIntents->retrieve($transId);

// Line 851 - AFTER:
$this->_oStripeApiOrder = $this->getStripeApiRequestModel()->retrievePaymentIntent($transId);

// Line 899 - BEFORE:
$this->_oStripeApiCharge = $this->getStripeApiRequestModel()->charges->retrieve($sLastChargeId);

// Line 899 - AFTER:
$this->_oStripeApiCharge = $this->getStripeApiRequestModel()->retrieveCharge($sLastChargeId);
```

---

## Files to Modify

| File | Action |
|------|--------|
| `src/Stripe/Adapter/StripeAdapterInterface.php` | Add `retrieveCharge()` method |
| `src/Stripe/Adapter/StripeAdapter.php` | Implement `retrieveCharge()` |
| `src/Stripe/Controller/Admin/OrderRefund.php` | Use adapter methods |
| `tests/Unit/Stripe/Adapter/StripeAdapterTest.php` | Add test for `retrieveCharge()` |

---

## Verification

```bash
# Run pre-commit check
./bin/pre-commit-check.sh --full

# Expected: All checks pass
# - PHPStan: No errors for OrderRefund.php
# - PHPUnit: All tests pass
# - PHPCS: No style violations
```

---

## Acceptance Criteria

- [ ] `StripeAdapterInterface` has `retrieveCharge(string $chargeId): Charge` method
- [ ] `StripeAdapter` implements `retrieveCharge()` with proper error handling
- [ ] `OrderRefund` uses `retrievePaymentIntent()` instead of direct SDK call
- [ ] `OrderRefund` uses `retrieveCharge()` instead of direct SDK call
- [ ] Unit test for `retrieveCharge()` passes
- [ ] `./bin/pre-commit-check.sh --full` passes
- [ ] Admin refund UI works correctly (manual verification)
