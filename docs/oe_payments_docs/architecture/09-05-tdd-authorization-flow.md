# TDD Strategy - Part 5 of 8: Two-Step Authorization Flow & Webhook Processing

**Version:** 2.1.0
**Date:** 2025-10-16
**Target Platform:** OXID eShop 7.4+ (compatible with 7.5, 8.0+)

**Part of Series:**
- [Part 1](09-01-tdd-overview.md): Overview, Test Organization, Priority Classification, Payment Security
- [Part 2](09-02-tdd-data-persistence.md): Data Persistence & Integrity
- [Part 3](09-03-tdd-event-system.md): Event System & Business Logic, Service Layer
- [Part 4](09-04-tdd-provider-integration.md): Provider Integration, SDK-Adapter Layer
- **Part 5** (This document): Two-Step Authorization Flow, Webhook Processing
- [Part 6](09-06-tdd-checkout-frontend.md): Checkout Frontend, Admin Features
- [Part 7](09-07-tdd-test-pyramid.md): Test Pyramid Strategy, Unit/Integration/E2E Tests, Fixtures
- [Part 8](09-08-tdd-mocking-coverage.md): Mocking Strategy, Coverage Goals, CI/CD, Best Practices

---

#### 5.9.1 SCA Validator Service (P1-C)
**Test Location:** `tests/Component/Unit/Service/`
- **Coverage Required:** 95%
- **Test Types:** Unit + Integration
- **Components:**
  - `SCAValidatorService` - 3D Secure validation
  - `SCAValidatorService::requiresAuthentication()` - Check if 3DS required
  - `SCAValidatorService::getAuthenticationUrl()` - Get 3DS redirect URL
  - `SCAValidatorService::validateAuthenticationResult()` - Verify 3DS result
  - `SCAValidatorService::isCardUsableForPayment()` - Check liability shift

**Test Scenarios:**
```php
// tests/Component/Unit/Service/SCAValidatorServiceTest.php

✅ testRequiresAuthentication_DetectsRequires3DS()
✅ testRequiresAuthentication_NoActionRequired_ReturnsFalse()
✅ testGetAuthenticationUrl_ReturnsProviderUrl()
✅ testValidateAuthenticationResult_SuccessfulAuth_ReturnsTrue()
✅ testValidateAuthenticationResult_FailedAuth_ReturnsFalse()
✅ testIsCardUsableForPayment_LiabilityShifted_ReturnsTrue()
✅ testIsCardUsableForPayment_NoLiabilityShift_ReturnsFalse()
✅ testAlwaysIgnoreSCAResult_ConfigOption_BypassesValidation()
```

**Implementation Order:**
1. Add 3DS tracking fields to `oe_payments_transaction`:
   - `OXREQUIRES_ACTION` (boolean)
   - `OXACTION_URL` (3DS redirect URL)
   - `OXLIABILITY_SHIFTED` (boolean)
2. Write tests for SCA validator
3. Implement `SCAValidatorService`
4. Test 3DS status detection
5. Test authentication result validation
6. Test configuration options (ignore SCA result)

---

#### 5.9.2 Integration with PaymentService (P1-D)
**Test Location:** `tests/Component/Unit/Service/`
- **Coverage Required:** 90%
- **Test Types:** Unit + Integration
- **Components:**
  - `PaymentService::initiate3DSecure()` - Start 3DS flow
  - `PaymentService::verify3DSecureResult()` - Complete 3DS flow
  - Automatic 3DS handling

**Test Scenarios:**
```php
✅ testInitiatePayment_Requires3DS_ReturnsAuthUrl()
✅ testInitiatePayment_No3DSRequired_ProceedsDirectly()
✅ testVerify3DSecureResult_Successful_CompletesPayment()
✅ testVerify3DSecureResult_Failed_RollsBackPayment()
✅ testCapturePayment_Requires3DS_ThrowsException()
```

**Implementation Order:**
1. Write tests for 3DS integration
2. Integrate `SCAValidatorService` into `PaymentService`
3. Test 3DS flow initiation
4. Test 3DS result verification
5. Test error handling

---

### Block 5.10: Partial Refund & Calculation 🟡 MEDIUM (P2)

**Test Organization Note:** Refund logic is a **component concern**. Component tests mock adapter, provider tests test real refund APIs.

---

#### 5.10.1 Refund Service (P2-F)
**Test Location:** `tests/Component/Unit/Service/`
- **Coverage Required:** 95%
- **Test Types:** Unit + Integration
- **Components:**
  - `RefundService` - Refund management
  - `RefundService::calculateMaxRefundAmount()` - Maximum refundable amount
  - `RefundService::partialRefund()` - Refund less than captured
  - `RefundService::fullRefund()` - Refund entire captured amount
  - Refund tracking (prevent over-refund)

**Test Scenarios:**
```php
// tests/Component/Unit/Service/RefundServiceTest.php

✅ testCalculateMaxRefundAmount_CapturedAmount()
✅ testCalculateMaxRefundAmount_WithCompensation_Amazon()
✅ testPartialRefund_ValidAmount()
✅ testPartialRefund_ExceedsCaptured_ThrowsException()
✅ testPartialRefund_ExceedsRemaining_ThrowsException()
✅ testMultiplePartialRefunds_SumDoesNotExceedCaptured()
✅ testFullRefund_RefundsEntireCapturedAmount()
✅ testFullRefund_AlreadyPartiallyRefunded_RefundsRemaining()
✅ testRefund_UncapturedPayment_ThrowsException()
```

**Implementation Order:**
1. Add refund tracking fields to `oe_payments_transaction`:
   - `OXREFUNDED_AMOUNT` (total refunded amount)
   - `OXREFUNDABLE_AMOUNT` (remaining refundable amount)
   - `OXMAX_REFUND_AMOUNT` (provider-specific max with compensation)
2. Write tests for refund service
3. Implement `RefundService` with calculation logic
4. Test partial refund validation
5. Test refund tracking
6. Test provider-specific compensation logic (Amazon Pay)

---

#### 5.10.2 Integration with PaymentService (P2-G)
**Test Location:** `tests/Component/Unit/Service/`
- **Coverage Required:** 90%
- **Test Types:** Unit + Integration
- **Components:**
  - `PaymentService::refundPayment()` - Refund with validation
  - Automatic refund amount validation
  - Refund state tracking

**Test Scenarios:**
```php
✅ testRefundPayment_ValidatesMaxRefundAmount()
✅ testRefundPayment_UpdatesRefundedAmount()
✅ testRefundPayment_UpdatesRefundableAmount()
✅ testRefundPayment_TracksRefundTransaction()
✅ testRefundPayment_UpdatesOrderState()
```

**Implementation Order:**
1. Write tests for refund integration
2. Integrate `RefundService` into `PaymentService`
3. Test refund validation
4. Test refund tracking
5. Test order state updates

---

### Block 6: API Layer & Controllers 🟡 MEDIUM (P2)

#### 6.1 Controllers (P2-E)
- **Coverage Required:** 80%
- **Test Types:** Unit + E2E
- **Components:**
  - Input validation
  - Event emission
  - Response formatting

**Test Scenarios:**
```php
✅ testInvalidInput_Returns400()
✅ testValidInput_EmitsEvent()
✅ testAuthenticationFailure_Returns401()
```

---

### Block 7: User Interface & Experience 🟢 LOW (P3)

#### 7.1 E2E Checkout Flows (P3-A)
- **Coverage Required:** 80%
- **Test Types:** E2E
- **Components:**
  - Complete checkout flow
  - Payment method selection
  - Order confirmation

---

## Implementation Roadmap

### Phase 1: Foundation (Week 1-2) 🔴 CRITICAL
**Focus: Security & Money Handling**

```
Week 1:
□ Day 1-2: Transaction tracking & idempotency (P0-A, P0-B)
  - Implement database schema with constraints
  - Write tests for double-capture prevention
  - Implement idempotency key validation

□ Day 3-4: Order state machine (P0-C)
  - Define all valid state transitions
  - Write tests for invalid transitions
  - Implement state validation

□ Day 5: Webhook signature verification (P0-D)
  - Implement HMAC-SHA256 verification
  - Write tests for replay attacks
  - Test timestamp validation

Week 2:
□ Day 1-3: Repository layer (P0-E)
  - Complete OrderRepository implementation
  - Write integration tests with real database
  - Test concurrent access scenarios

□ Day 4-5: Transaction audit trail (P0-F)
  - Implement immutable transaction logging
  - Write reconciliation queries
  - Test transaction linking
```

**Exit Criteria:**
- ✅ All P0 tests passing
- ✅ 100% coverage on critical components
- ✅ No security vulnerabilities in code scan
- ✅ Manual security review completed

---

### Phase 2: Business Logic (Week 3-4) 🟠 HIGH
**Focus: Event System & Services**

```
Week 3:
□ Event layer implementation (P1-A)
□ Event handlers (P1-B)
□ Domain models (P1-C)

Week 4:
□ Payment service (P1-D)
□ Configuration management (P1-E)
□ Integration tests for event flows
```

**Exit Criteria:**
- ✅ All P1 tests passing
- ✅ 90%+ coverage on service layer
- ✅ Integration tests passing with real database

---

### Phase 3: Provider Integration (Week 5) 🟡 MEDIUM
**Focus: External APIs**

```
Week 5:
□ Request factories (P2-A)
□ Error mapping (P2-B)
□ Provider-specific implementations
```

**Exit Criteria:**
- ✅ All P2 tests passing
- ✅ Provider sandbox tests successful

---

### Phase 4: API & UI (Week 6) 🟡-🟢 MEDIUM-LOW
**Focus: User-facing features**

```
Week 6:
□ Controllers (P2-C)
□ E2E checkout flows (P3-A)
□ Performance optimization
```

**Exit Criteria:**
- ✅ All tests passing
- ✅ 85%+ overall coverage
- ✅ E2E tests successful

---

## Security-First Testing Checklist

### Before Any Code Goes to Production

#### Financial Security ✅
- [ ] Double-capture prevention tested with idempotency keys
- [ ] Amount validation (no negative amounts, refunds ≤ captures)
- [ ] Currency precision tested (no rounding errors)
- [ ] Concurrent transaction handling tested
- [ ] Transaction rollback on errors tested

#### Authentication & Authorization ✅
- [ ] Webhook signature verification tested
- [ ] Replay attack prevention tested
- [ ] Timestamp validation tested (reject old webhooks)
- [ ] API authentication tested
- [ ] Admin permission checks tested

#### Data Integrity ✅
- [ ] Database constraints tested (foreign keys, unique constraints)
- [ ] Transaction history immutability tested
- [ ] Audit trail completeness tested
- [ ] Concurrent access scenarios tested
- [ ] Data consistency across tables tested

#### Error Handling ✅
- [ ] All error paths tested
- [ ] No sensitive data in error messages
- [ ] Provider errors mapped correctly
- [ ] Graceful degradation tested
- [ ] Circuit breaker tested (if implemented)

#### Compliance ✅
- [ ] PCI-DSS requirements validated
- [ ] GDPR data handling tested
- [ ] Audit logging tested
- [ ] Data retention policies implemented

---

## Critical Test Coverage Requirements

### Minimum Coverage by Priority

| Priority | Line Coverage | Branch Coverage | Test Types |
|----------|---------------|-----------------|------------|
| **P0 (Critical)** | 100% | 100% | Unit + Integration + E2E |
| **P1 (High)** | 90-95% | 85-90% | Unit + Integration |
| **P2 (Medium)** | 80-85% | 75-80% | Unit + Integration |
| **P3 (Low)** | 70-80% | 65-75% | E2E |

### Critical Components Must Have

1. **Unit Tests** - Fast, isolated tests for logic
2. **Integration Tests** - Real database, event flow
3. **Security Tests** - Attack scenarios, edge cases
4. **Load Tests** - Concurrent access, race conditions
5. **E2E Tests** - Complete user flows with real providers

---

## Overview

This document provides a comprehensive **Test-Driven Development (TDD) strategy** for the event-driven payment component. The strategy follows the **test pyramid** principle, emphasizing fast, isolated unit tests while ensuring critical integration points and user flows are covered.

### Key Principles

- **Security First:** Critical components (P0) must have 100% coverage
- **Test First:** Write tests before implementation (Red → Green → Refactor)
- **Fast Feedback:** Unit tests run in < 5 seconds
- **Isolation:** Each test is independent and can run in parallel
- **Maintainability:** Tests are clear, self-contained, and easy to debug
- **Coverage:** Target 100% for P0, 90%+ for P1, 85%+ overall

---

## Test Pyramid Strategy

```
        ┌─────────────┐
        │  E2E (10%)  │  ← Slow, Full System, Real APIs
        ├─────────────┤
        │             │
        │ Integration │  ← Medium, Real DB, Mocked APIs
        │    (30%)    │
        │             │
        ├─────────────┤
        │             │
        │             │
        │  Unit Tests │  ← Fast, Isolated, All Mocked
        │    (60%)    │
        │             │
        └─────────────┘
```

### Distribution Rationale

| Test Type | Percentage | Count (~) | Speed | Purpose |
|-----------|------------|-----------|-------|---------|
| **Unit** | 60% | 300 | < 1ms | Verify individual component logic |
| **Integration** | 30% | 100 | 10-100ms | Verify component interactions |
| **E2E** | 10% | 20 | 1-10s | Verify critical user flows |

**Total:** ~420 tests, < 15 minutes full suite execution

---

## Unit Tests (60%)

### Purpose

Unit tests verify **individual component logic in isolation**. All dependencies are mocked to ensure:
- Fast execution (< 1ms per test)
- No external dependencies (DB, APIs, network)
- Predictable results
- Easy debugging

### Coverage by Layer

#### 1. Event Layer (100% coverage)

**Test Files:**
- `tests/Component/Unit/Event/PaymentInitiatedEventTest.php`
- `tests/Component/Unit/Event/PaymentCapturedEventTest.php`
- `tests/Component/Unit/Event/EventContextTest.php`

**Example: EventContext Caching Test**

```php
<?php
// tests/Component/Unit/Event/EventContextTest.php

namespace OxidSolutionCatalysts\Component\Tests\Unit\Event;

use OxidSolutionCatalysts\Component\Event\EventContext;
use OxidSolutionCatalysts\Component\Model\Basket;
use OxidSolutionCatalysts\Component\Model\User;
use PHPUnit\Framework\TestCase;

class EventContextTest extends TestCase
{
    public function testCachesBasketAndUser(): void
    {
        // Arrange
        $basket = $this->createMock(Basket::class);
        $user = $this->createMock(User::class);

        $context = new EventContext([
            'basket' => $basket,
            'user' => $user,
        ]);

        // Act
        $cachedBasket = $context->getBasket();
        $cachedUser = $context->getUser();

        // Assert
        $this->assertSame($basket, $cachedBasket);
        $this->assertSame($user, $cachedUser);
    }

    public function testGetRequestParamWithDefault(): void
    {
        // Arrange
        $context = new EventContext([
            'returnUrl' => '/success',
        ]);

        // Act
        $returnUrl = $context->getRequestParam('returnUrl');
        $cancelUrl = $context->getRequestParam('cancelUrl', '/cancel');

        // Assert
        $this->assertEquals('/success', $returnUrl);
        $this->assertEquals('/cancel', $cancelUrl);
    }

    public function testContextIsImmutable(): void
    {
        // Arrange
        $context = new EventContext(['key' => 'value']);

        // Act & Assert
        $this->expectException(\BadMethodCallException::class);
        $context->set('key', 'newValue');
    }
}
```

**Test Cases:**
- ✓ Event creation with required parameters
- ✓ Event getters return correct values
- ✓ EventContext caches data correctly
- ✓ EventContext handles missing keys with defaults
- ✓ Events are immutable after creation
- ✓ Event serialization for logging

---

#### 2. Domain Layer (95% coverage)

**Test Files:**
- `tests/Component/Unit/Model/OrderTest.php`
- `tests/Component/Unit/Model/PaymentTransactionTest.php`
- `tests/Component/Unit/Model/BasketTest.php`

**Example: Order State Transitions Test**

```php
<?php
// tests/Component/Unit/Model/OrderTest.php

namespace OxidSolutionCatalysts\Component\Tests\Unit\Model;

use OxidSolutionCatalysts\Component\Model\Order;
use PHPUnit\Framework\TestCase;

class OrderTest extends TestCase
{
    public function testMarkAsPaymentInProgress(): void
    {
        // Arrange
        $order = new Order();
        $order->setOxid('test-order-id');

        // Act
        $order->markAsPaymentInProgress();

        // Assert
        $this->assertEquals('IN_PROGRESS', $order->getPaymentState());
        $this->assertTrue($order->isAwaitingPayment());
    }

    public function testMarkAsPaymentCompleted(): void
    {

---

## Related Documentation

- **[Part 4: Provider Integration](09-04-tdd-provider-integration.md)** - SDK-Adapter layer testing
- **[Part 6: Checkout Frontend](09-06-tdd-checkout-frontend.md)** - E2E checkout testing (continues from here)
- **[Test Organization](09-test-organization.md)** - Component vs provider test separation

---

**Version:** 2.1.0
**Last Updated:** 2025-10-16
