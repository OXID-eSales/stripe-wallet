# Sprint 20: Remove $_REQUEST Modification

**Date:** 2025-12-15
**Priority:** HIGH
**Status:** TODO
**Branch:** b-7.4.x-code-review-STRP-75
**Est. Effort:** 2 hours
**Original Sprint:** 2025-12-09

---

## Development Principles Checklist

| Principle | How Applied |
|-----------|-------------|
| **TDD-FIRST** | Write tests for session service before implementation |
| **SOLID/SRP** | Session service handles address hash storage/retrieval |
| **Security** | No superglobal modification |
| **Clean Code** | Explicit session operations |
| **Testability** | Mockable session interface |

---

## Problem Statement

**File:** `src/Stripe/EventSystem/Handler/StripeCheckoutReturnHandler.php`
**Line:** 302

```php
$_REQUEST['sDeliveryAddressMD5'] = $deliveryHash;
```

### Why This Is a Problem

1. **Security Anti-Pattern:** Modifying superglobals can lead to unexpected side effects
2. **Testability:** Unit tests cannot easily mock `$_REQUEST`
3. **Implicit State:** Code behavior depends on hidden state modification
4. **Side Effects:** Other code reading `$_REQUEST` may get unexpected values
5. **Framework Bypass:** Should use OXID's request handling

### Context

The delivery address hash is needed by OXID's order validation during `finalizeOrder()`. The handler stores the hash retrieved from the contract metadata into `$_REQUEST` so that the order validation can find it.

---

## Solution

Replace direct `$_REQUEST` modification with proper session-based approach using `ContractMetadataService` (created in Sprint 21).

### Current Flow (Problematic)

```
StripeCheckoutReturnHandler
    │
    ├── Retrieve deliveryHash from contract metadata
    │
    └── $_REQUEST['sDeliveryAddressMD5'] = $deliveryHash  ❌ BAD
            │
            └── Order::validateDeliveryAddress() reads $_REQUEST
```

### New Flow (Clean)

```
StripeCheckoutReturnHandler
    │
    ├── Retrieve deliveryHash from contract metadata
    │
    └── Session::setVariable('sDeliveryAddressMD5', $deliveryHash)  ✓ GOOD
            │
            └── Order::validateDeliveryAddress() reads from session
```

---

## Implementation Plan

### Step 1: Analyze Current Usage

Check how `sDeliveryAddressMD5` is currently used in order validation.

**File:** `/source/Application/Model/Order.php`

The OXID core checks `$_REQUEST['sDeliveryAddressMD5']` in `validateDeliveryAddress()`. Our `Stripe\Model\Order` extension should override this to also check session.

### Step 2: Update Stripe Order Model

**File:** `src/Stripe/Model/Order.php`

Add session fallback for delivery address hash:

```php
protected function validateDeliveryAddress(): int
{
    // First, check if we have the hash in session (from Stripe return flow)
    $session = Registry::getSession();
    $sessionHash = $session->getVariable('osc_stripe_delivery_hash');

    if ($sessionHash !== null) {
        // Use session hash for validation
        $_REQUEST['sDeliveryAddressMD5'] = $sessionHash;
    }

    return parent::validateDeliveryAddress();
}
```

### Step 3: Update Handler to Use Session

**File:** `src/Stripe/EventSystem/Handler/StripeCheckoutReturnHandler.php`

```php
// BEFORE:
$_REQUEST['sDeliveryAddressMD5'] = $deliveryHash;

// AFTER:
$this->sessionService->setDeliveryAddressHash($deliveryHash);
```

### Step 4: Create Session Service Methods

**File:** `src/Stripe/Service/StripeSessionService.php`

```php
interface StripeSessionServiceInterface
{
    public function setDeliveryAddressHash(string $hash): void;
    public function getDeliveryAddressHash(): ?string;
    public function clearDeliveryAddressHash(): void;
}

class StripeSessionService implements StripeSessionServiceInterface
{
    private const SESSION_KEY_DELIVERY_HASH = 'osc_stripe_delivery_hash';

    public function __construct(
        private readonly Session $session
    ) {}

    public function setDeliveryAddressHash(string $hash): void
    {
        $this->session->setVariable(self::SESSION_KEY_DELIVERY_HASH, $hash);
    }

    public function getDeliveryAddressHash(): ?string
    {
        return $this->session->getVariable(self::SESSION_KEY_DELIVERY_HASH);
    }

    public function clearDeliveryAddressHash(): void
    {
        $this->session->deleteVariable(self::SESSION_KEY_DELIVERY_HASH);
    }
}
```

---

## Files to Create

| File | Purpose |
|------|---------|
| `src/Stripe/Service/StripeSessionServiceInterface.php` | Session abstraction interface |
| `src/Stripe/Service/StripeSessionService.php` | Session service implementation |
| `tests/Unit/Stripe/Service/StripeSessionServiceTest.php` | Service tests |

## Files to Modify

| File | Change |
|------|--------|
| `src/Stripe/Model/Order.php` | Add session hash lookup in `validateDeliveryAddress()` |
| `src/Stripe/EventSystem/Handler/StripeCheckoutReturnHandler.php` | Use session service instead of `$_REQUEST` |
| `services.yaml` | Register `StripeSessionService` |
| `tests/Unit/Stripe/EventSystem/Handler/StripeCheckoutReturnHandlerTest.php` | Update tests |

---

## TDD Test Cases

### Test 1: StripeSessionService

```php
public function testSetDeliveryAddressHashStoresInSession(): void
{
    $session = $this->createMock(Session::class);
    $session->expects($this->once())
        ->method('setVariable')
        ->with('osc_stripe_delivery_hash', 'abc123');

    $service = new StripeSessionService($session);
    $service->setDeliveryAddressHash('abc123');
}

public function testGetDeliveryAddressHashReturnsStoredValue(): void
{
    $session = $this->createMock(Session::class);
    $session->method('getVariable')
        ->with('osc_stripe_delivery_hash')
        ->willReturn('abc123');

    $service = new StripeSessionService($session);
    $this->assertEquals('abc123', $service->getDeliveryAddressHash());
}

public function testClearDeliveryAddressHashRemovesFromSession(): void
{
    $session = $this->createMock(Session::class);
    $session->expects($this->once())
        ->method('deleteVariable')
        ->with('osc_stripe_delivery_hash');

    $service = new StripeSessionService($session);
    $service->clearDeliveryAddressHash();
}
```

### Test 2: Handler Uses Session Service

```php
public function testHandlerUsesSessionServiceForDeliveryHash(): void
{
    $this->sessionService
        ->expects($this->once())
        ->method('setDeliveryAddressHash')
        ->with('expected_hash');

    $this->handler->handle($this->createEventWithDeliveryHash('expected_hash'));
}
```

### Test 3: Order Model Uses Session Hash

```php
public function testValidateDeliveryAddressUsesSessionHash(): void
{
    $session = $this->createMock(Session::class);
    $session->method('getVariable')
        ->with('osc_stripe_delivery_hash')
        ->willReturn('valid_hash');

    Registry::set(Session::class, $session);

    $order = oxNew(Order::class);
    $result = $order->validateDeliveryAddress();

    // Should pass validation using session hash
    $this->assertNotEquals(Order::ORDER_STATE_INVALIDDELADDRESSCHANGED, $result);
}
```

---

## Verification Steps

### Step 1: Verify No $_REQUEST Modification

```bash
# Should return NO results after fix
grep -rn '\$_REQUEST\[' src/Stripe/EventSystem/Handler/
```

### Step 2: Run Tests

```bash
# Session service tests
docker compose exec php php vendor/bin/phpunit -c extensions/stripe/tests/phpunit.xml \
  --filter "StripeSessionService"

# Handler tests
docker compose exec php php vendor/bin/phpunit -c extensions/stripe/tests/phpunit.xml \
  tests/Unit/Stripe/EventSystem/Handler/StripeCheckoutReturnHandlerTest.php

# Full test suite
docker compose exec php php vendor/bin/phpunit -c extensions/stripe/tests/phpunit.xml --testsuite Unit
```

### Step 3: E2E Verification

```bash
# Run checkout E2E test to ensure delivery address validation still works
cd tests/e2e/playwright && npx playwright test tests/checkout/stripe-checkout.spec.ts
```

---

## Success Criteria

- [ ] `StripeSessionService` created with interface
- [ ] All session service tests pass
- [ ] `$_REQUEST` modification removed from handler
- [ ] Handler uses `StripeSessionService`
- [ ] `Stripe\Model\Order` reads from session
- [ ] E2E checkout flow passes
- [ ] No grep results for `$_REQUEST[` in handlers
- [ ] PHPStan level 6 passes
- [ ] Pre-commit checks pass

---

## Risk Mitigation

### Risk: Order validation fails after change

**Mitigation:**
1. The `Stripe\Model\Order::validateDeliveryAddress()` will fallback to session
2. E2E test verifies full checkout flow
3. Session variable uses unique key prefix `osc_stripe_`

### Risk: Session variable not cleared

**Mitigation:**
1. Add `clearDeliveryAddressHash()` call after successful order creation
2. Session variable will expire with session anyway

---

## Related Issues

- CODE_REVIEW.md Section 4.4 (HIGH: Direct $_REQUEST Modification)

---

**Last Updated:** 2025-12-15
