# Sprint 19: Route Stripe SDK Calls Through Adapter

**Date:** 2025-12-09
**Priority:** HIGH
**Status:** PENDING
**Branch:** TBD (b-7.4.x-STRP-XX)
**Est. Effort:** 4 hours

---

## Development Principles Checklist

| Principle | How Applied |
|-----------|-------------|
| **TDD-FIRST** | Write adapter method tests first |
| **SOLID-SRP** | Adapter has single responsibility: Stripe SDK operations |
| **SOLID-OCP** | Adapter open for new operations |
| **SOLID-DIP** | Handlers depend on adapter interface, not SDK |
| **SOLID-LSP** | Any adapter implementation can substitute |
| **DI** | Adapter injected into handlers |
| **Clean Code** | Methods ≤ 25 lines |
| **Containerization** | All tests via `docker compose exec` |

---

## Problem Statement

**Documentation states:** Handlers delegate to adapters
**Reality:** Handlers call Stripe SDK directly

| File | Line | Direct SDK Call |
|------|------|-----------------|
| `StripeCheckoutReturnHandler.php` | 154 | `$stripeClient->checkout->sessions->retrieve()` |
| `StripeRefundRequestHandler.php` | 227 | `$stripeClient->refunds->create()` |

**Impact:**
- Handlers tightly coupled to Stripe SDK
- Cannot swap payment provider
- Difficult to test (requires SDK mocking)
- Violates Dependency Inversion Principle

---

## Root Cause Analysis

1. **Adapter incomplete** - Missing methods for all operations
2. **Convenience over design** - Direct SDK access was faster
3. **No enforcement** - No code review caught violations

---

## Solution Design

### Phase 1: TDD - Write Failing Tests First

**Extend Test File:** `tests/Unit/Stripe/Adapter/StripeAdapterTest.php`

```php
/**
 * @test
 * SRP: Retrieves checkout session through adapter
 */
public function retrievesCheckoutSession(): void
{
    // Arrange
    $sessionId = 'cs_test_123';
    $expectedSession = new \Stripe\Checkout\Session($sessionId);

    $this->stripeClient->checkout->sessions
        ->expects($this->once())
        ->method('retrieve')
        ->with($sessionId, ['expand' => ['line_items', 'payment_intent']])
        ->willReturn($expectedSession);

    // Act
    $result = $this->adapter->retrieveCheckoutSession($sessionId);

    // Assert
    $this->assertSame($expectedSession, $result);
}

/**
 * @test
 * SRP: Creates refund through adapter
 */
public function createsRefund(): void
{
    // Arrange
    $chargeId = 'ch_123';
    $amount = 1000;
    $expectedRefund = new \Stripe\Refund('re_123');

    $this->stripeClient->refunds
        ->expects($this->once())
        ->method('create')
        ->with([
            'charge' => $chargeId,
            'amount' => $amount,
        ])
        ->willReturn($expectedRefund);

    // Act
    $result = $this->adapter->createRefund($chargeId, $amount);

    // Assert
    $this->assertSame($expectedRefund, $result);
}

/**
 * @test
 * SRP: Retrieves payment intent through adapter
 */
public function retrievesPaymentIntent(): void
{
    // Arrange
    $paymentIntentId = 'pi_123';
    $expectedIntent = new \Stripe\PaymentIntent($paymentIntentId);

    $this->stripeClient->paymentIntents
        ->expects($this->once())
        ->method('retrieve')
        ->with($paymentIntentId, ['expand' => ['charges']])
        ->willReturn($expectedIntent);

    // Act
    $result = $this->adapter->retrievePaymentIntent($paymentIntentId);

    // Assert
    $this->assertSame($expectedIntent, $result);
}
```

### Phase 2: Extend Adapter Interface

**File:** `src/Component/Adapter/PaymentAdapterInterface.php`

Add new methods:

```php
/**
 * Retrieve checkout session details
 *
 * @param string $sessionId Checkout session ID
 * @param array<string> $expand Fields to expand
 * @return mixed Provider-specific session object
 */
public function retrieveCheckoutSession(string $sessionId, array $expand = []): mixed;

/**
 * Create a refund
 *
 * @param string $chargeId Charge ID to refund
 * @param int|null $amount Amount in cents (null for full refund)
 * @param string|null $reason Refund reason
 * @return mixed Provider-specific refund object
 */
public function createRefund(string $chargeId, ?int $amount = null, ?string $reason = null): mixed;

/**
 * Retrieve payment intent details
 *
 * @param string $paymentIntentId Payment intent ID
 * @param array<string> $expand Fields to expand
 * @return mixed Provider-specific payment intent object
 */
public function retrievePaymentIntent(string $paymentIntentId, array $expand = []): mixed;
```

### Phase 3: Implement in StripeAdapter

**File:** `src/Stripe/Adapter/StripeAdapter.php`

```php
public function retrieveCheckoutSession(string $sessionId, array $expand = []): \Stripe\Checkout\Session
{
    $options = [];
    if (!empty($expand)) {
        $options['expand'] = $expand;
    }

    return $this->client->checkout->sessions->retrieve($sessionId, $options);
}

public function createRefund(string $chargeId, ?int $amount = null, ?string $reason = null): \Stripe\Refund
{
    $params = ['charge' => $chargeId];

    if ($amount !== null) {
        $params['amount'] = $amount;
    }

    if ($reason !== null) {
        $params['reason'] = $reason;
    }

    return $this->client->refunds->create($params);
}

public function retrievePaymentIntent(string $paymentIntentId, array $expand = []): \Stripe\PaymentIntent
{
    $options = [];
    if (!empty($expand)) {
        $options['expand'] = $expand;
    }

    return $this->client->paymentIntents->retrieve($paymentIntentId, $options);
}
```

### Phase 4: Update Handlers to Use Adapter

**File:** `src/Stripe/EventSystem/Handler/StripeCheckoutReturnHandler.php`

```php
// BEFORE (direct SDK call):
private function getCheckoutSession(string $sessionId): \Stripe\Checkout\Session
{
    $stripeClient = $this->stripeClientFactory->create();
    return $stripeClient->checkout->sessions->retrieve($sessionId, [
        'expand' => ['line_items', 'payment_intent'],
    ]);
}

// AFTER (through adapter):
private function getCheckoutSession(string $sessionId): \Stripe\Checkout\Session
{
    return $this->stripeAdapter->retrieveCheckoutSession($sessionId, [
        'line_items',
        'payment_intent',
    ]);
}
```

**File:** `src/Stripe/EventSystem/Handler/StripeRefundRequestHandler.php`

```php
// BEFORE (direct SDK call):
private function executeRefund(string $chargeId, int $amount): \Stripe\Refund
{
    $stripeClient = $this->stripeClientFactory->create();
    return $stripeClient->refunds->create([
        'charge' => $chargeId,
        'amount' => $amount,
    ]);
}

// AFTER (through adapter):
private function executeRefund(string $chargeId, int $amount): \Stripe\Refund
{
    return $this->stripeAdapter->createRefund($chargeId, $amount);
}
```

### Phase 5: Update Constructor Dependencies

For each handler, update constructor:

```php
// BEFORE:
public function __construct(
    private readonly StripeClientFactory $stripeClientFactory,
    // ... other deps
) {
}

// AFTER:
public function __construct(
    private readonly PaymentAdapterInterface $stripeAdapter,
    // ... other deps
) {
}
```

**Update `services.yaml`:**

```yaml
OxidSolutionCatalysts\Payments\Stripe\EventSystem\Handler\StripeCheckoutReturnHandler:
    arguments:
        - '@OxidSolutionCatalysts\Payments\Stripe\Adapter\StripeAdapter'
        # ... other arguments
```

---

## Implementation Steps

### Step 1: Write Tests (TDD - RED)

```bash
# Add tests to StripeAdapterTest
# Run tests - should fail (methods don't exist)
docker compose exec -T php bash -c "cd /var/www/test-module && vendor/bin/phpunit -c tests/phpunit.xml tests/Unit/Stripe/Adapter/StripeAdapterTest.php"
```

### Step 2: Extend Interface and Implementation (TDD - GREEN)

```bash
# Add methods to PaymentAdapterInterface
# Add implementations to StripeAdapter
# Run tests - should pass
docker compose exec -T php bash -c "cd /var/www/test-module && vendor/bin/phpunit -c tests/phpunit.xml tests/Unit/Stripe/Adapter/StripeAdapterTest.php"
```

### Step 3: Update Handlers One by One

```bash
# For each handler:
# 1. Change constructor to accept adapter
# 2. Replace direct SDK calls with adapter calls
# 3. Update services.yaml
# 4. Update handler tests
# 5. Run tests

docker compose exec -T php bash -c "cd /var/www/test-module && vendor/bin/phpunit -c tests/phpunit.xml --testsuite Unit"
```

### Step 4: Quality Checks

```bash
# PHPStan
composer phpstan

# PHPCS
composer phpcs

# Pre-commit check
./bin/pre-commit-check.sh

# E2E test
cd tests/e2e/playwright && npx playwright test tests/checkout/
```

---

## Files to Create/Modify

### Modified Files

| File | Change |
|------|--------|
| `src/Component/Adapter/PaymentAdapterInterface.php` | Add new methods |
| `src/Stripe/Adapter/StripeAdapter.php` | Implement new methods |
| `src/Stripe/EventSystem/Handler/StripeCheckoutReturnHandler.php` | Use adapter |
| `src/Stripe/EventSystem/Handler/StripeRefundRequestHandler.php` | Use adapter |
| `services.yaml` | Update handler dependencies |
| `tests/Unit/Stripe/Adapter/StripeAdapterTest.php` | Add method tests |
| `tests/Unit/Stripe/EventSystem/Handler/*Test.php` | Update to mock adapter |

---

## Verification Checklist

- [ ] PaymentAdapterInterface has all required methods
- [ ] StripeAdapter implements all methods
- [ ] No direct `$stripeClient->` calls in handlers
- [ ] Handlers depend on adapter interface
- [ ] All unit tests pass
- [ ] E2E checkout flow works
- [ ] E2E refund flow works

### Verification Commands

```bash
# Verify no direct SDK calls in handlers
grep -rn "stripeClient->" src/Stripe/EventSystem/Handler/
grep -rn "stripeClient->" src/Stripe/Webhook/Handler/
# Should return: nothing (or only in StripeClientFactory)

# Verify adapter usage
grep -rn "stripeAdapter->" src/Stripe/
# Should show adapter calls in handlers
```

---

## Risk Assessment

| Risk | Impact | Mitigation |
|------|--------|------------|
| Breaking checkout flow | High | E2E tests before/after |
| Breaking refund flow | High | Manual refund test |
| SDK type mismatches | Medium | PHPStan will catch |

---

## Success Criteria

1. ✅ No direct Stripe SDK calls in handlers
2. ✅ All SDK operations go through adapter
3. ✅ Handlers depend on interface, not concrete adapter
4. ✅ All existing tests pass
5. ✅ E2E flows work

---

## Related Issues

- CODE_REVIEW.md Section 4.3 (HIGH: Direct Stripe SDK Calls in Handlers)
- Architecture doc 04-sdk-adapter-layer.md

---

**Last Updated:** 2025-12-09
