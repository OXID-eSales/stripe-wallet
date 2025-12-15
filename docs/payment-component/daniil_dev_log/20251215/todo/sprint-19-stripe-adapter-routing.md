# Sprint 19: Route Stripe SDK Calls Through Adapter

**Date:** 2025-12-15
**Priority:** HIGH
**Status:** TODO
**Branch:** b-7.4.x-code-review-STRP-75
**Est. Effort:** 4 hours
**Original Sprint:** 2025-12-09

---

## Development Principles Checklist

| Principle | How Applied |
|-----------|-------------|
| **TDD-FIRST** | Write tests for adapter methods before implementation |
| **SOLID/SRP** | Adapter handles all Stripe SDK calls |
| **SOLID/DIP** | Handlers depend on adapter interface, not SDK |
| **Clean Code** | Small focused methods |
| **No Duplicate Code** | Single location for SDK calls |

---

## Problem Statement

**Documentation states:** Handlers delegate to adapters for all provider-specific operations
**Reality:** Some handlers call Stripe SDK directly, bypassing the adapter layer

### Violations Found (from CODE_REVIEW.md)

| File | Line | Call |
|------|------|------|
| `StripeCheckoutReturnHandler.php` | 154 | `$stripeClient->checkout->sessions->retrieve()` |
| `StripeRefundRequestHandler.php` | 227 | `$stripeClient->refunds->create()` |

### Impact

1. **Testability:** Direct SDK calls are hard to mock in unit tests
2. **Consistency:** Some operations go through adapter, some don't
3. **Maintainability:** SDK version changes require fixes in multiple places
4. **Architecture:** Violates the adapter pattern

---

## Solution

Route all Stripe SDK calls through `StripeAdapter` or dedicated service interfaces.

### Option A: Extend StripeAdapter (Recommended)

Add missing methods to `StripeAdapter`:

```php
interface PaymentAdapterInterface
{
    // Existing methods...

    // NEW: Checkout session retrieval
    public function retrieveCheckoutSession(string $sessionId): CheckoutSessionResponse;
}
```

### Option B: Create Dedicated Services

Create service classes that encapsulate SDK calls:

```php
interface CheckoutSessionServiceInterface
{
    public function retrieve(string $sessionId): CheckoutSessionResult;
    public function create(CheckoutSessionConfig $config): CheckoutSessionResult;
}
```

**Decision:** Option A is simpler since we already have `StripeAdapter`. Option B was partially implemented in Sprint 21 for session creation.

---

## Implementation Plan

### Step 1: Add `retrieveCheckoutSession` to Adapter

**File:** `src/Stripe/Adapter/StripeAdapter.php`

```php
public function retrieveCheckoutSession(string $sessionId): CheckoutSessionResponse
{
    try {
        $session = $this->stripeClient->checkout->sessions->retrieve($sessionId);
        return CheckoutSessionResponse::fromStripeSession($session);
    } catch (ApiErrorException $e) {
        throw PaymentAdapterException::fromStripeException($e);
    }
}
```

### Step 2: Create Response DTO

**File:** `src/Component/Adapter/Response/CheckoutSessionResponse.php`

```php
final readonly class CheckoutSessionResponse
{
    public function __construct(
        public string $id,
        public string $paymentIntentId,
        public string $paymentStatus,
        public ?string $customerEmail,
        public array $metadata,
    ) {}

    public static function fromStripeSession(Session $session): self
    {
        return new self(
            id: $session->id,
            paymentIntentId: $session->payment_intent ?? '',
            paymentStatus: $session->payment_status ?? 'unknown',
            customerEmail: $session->customer_details?->email,
            metadata: $session->metadata?->toArray() ?? [],
        );
    }
}
```

### Step 3: Update Handler to Use Adapter

**File:** `src/Stripe/EventSystem/Handler/StripeCheckoutReturnHandler.php`

```php
// BEFORE:
$session = $this->stripeClient->checkout->sessions->retrieve($sessionId);

// AFTER:
$sessionResponse = $this->adapter->retrieveCheckoutSession($sessionId);
```

### Step 4: Verify RefundService Already Uses Adapter

The `RefundService` created in Sprint 21 should already handle refunds via adapter. Verify this is the case.

---

## Files to Create

| File | Purpose |
|------|---------|
| `src/Component/Adapter/Response/CheckoutSessionResponse.php` | DTO for session data |
| `tests/Unit/Component/Adapter/Response/CheckoutSessionResponseTest.php` | DTO tests |
| `tests/Unit/Stripe/Adapter/StripeAdapterRetrieveSessionTest.php` | Adapter method tests |

## Files to Modify

| File | Change |
|------|--------|
| `src/Component/Adapter/PaymentAdapterInterface.php` | Add `retrieveCheckoutSession()` method |
| `src/Stripe/Adapter/StripeAdapter.php` | Implement `retrieveCheckoutSession()` |
| `src/Stripe/EventSystem/Handler/StripeCheckoutReturnHandler.php` | Use adapter instead of direct SDK |
| `tests/Unit/Stripe/EventSystem/Handler/StripeCheckoutReturnHandlerTest.php` | Update mocks |

---

## TDD Test Cases

### Test 1: CheckoutSessionResponse DTO

```php
public function testFromStripeSessionMapsAllFields(): void
{
    $stripeSession = $this->createMockStripeSession([
        'id' => 'cs_test_123',
        'payment_intent' => 'pi_test_456',
        'payment_status' => 'paid',
    ]);

    $response = CheckoutSessionResponse::fromStripeSession($stripeSession);

    $this->assertEquals('cs_test_123', $response->id);
    $this->assertEquals('pi_test_456', $response->paymentIntentId);
    $this->assertEquals('paid', $response->paymentStatus);
}
```

### Test 2: StripeAdapter retrieveCheckoutSession

```php
public function testRetrieveCheckoutSessionReturnsResponse(): void
{
    $this->stripeClient->checkout->sessions
        ->method('retrieve')
        ->with('cs_test_123')
        ->willReturn($this->createMockStripeSession());

    $response = $this->adapter->retrieveCheckoutSession('cs_test_123');

    $this->assertInstanceOf(CheckoutSessionResponse::class, $response);
}

public function testRetrieveCheckoutSessionWrapsException(): void
{
    $this->stripeClient->checkout->sessions
        ->method('retrieve')
        ->willThrowException(new ApiErrorException('Not found'));

    $this->expectException(PaymentAdapterException::class);

    $this->adapter->retrieveCheckoutSession('cs_invalid');
}
```

### Test 3: Handler Uses Adapter

```php
public function testHandlerUsesAdapterForSessionRetrieval(): void
{
    $this->adapter
        ->expects($this->once())
        ->method('retrieveCheckoutSession')
        ->with('cs_test_123')
        ->willReturn($this->createCheckoutSessionResponse());

    $this->handler->handle($this->createEvent());
}
```

---

## Verification Commands

```bash
# Run specific tests
docker compose exec php php vendor/bin/phpunit -c extensions/stripe/tests/phpunit.xml \
  --filter "CheckoutSessionResponse|StripeAdapterRetrieveSession"

# Run handler tests
docker compose exec php php vendor/bin/phpunit -c extensions/stripe/tests/phpunit.xml \
  tests/Unit/Stripe/EventSystem/Handler/StripeCheckoutReturnHandlerTest.php

# Full pre-commit check
./bin/pre-commit-check.sh
```

---

## Success Criteria

- [ ] `CheckoutSessionResponse` DTO created with tests
- [ ] `PaymentAdapterInterface::retrieveCheckoutSession()` added
- [ ] `StripeAdapter::retrieveCheckoutSession()` implemented with tests
- [ ] `StripeCheckoutReturnHandler` uses adapter (no direct SDK)
- [ ] `StripeRefundRequestHandler` verified to use `RefundService` (from Sprint 21)
- [ ] All unit tests pass
- [ ] PHPStan level 6 passes
- [ ] Pre-commit checks pass

---

## Related Issues

- CODE_REVIEW.md Section 4.3 (HIGH: Direct Stripe SDK Calls in Handlers)

---

**Last Updated:** 2025-12-15
