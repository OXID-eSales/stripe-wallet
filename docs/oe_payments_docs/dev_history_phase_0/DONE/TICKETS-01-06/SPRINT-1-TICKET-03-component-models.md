[← Previous: TICKET-002](SPRINT-1-TICKET-02-event-layer.md) | [Back to Sprint Overview](SPRINT-1-overview.md) | [Back to Index](SPRINT-1-index.md) | [Next: TICKET-004 →](SPRINT-1-TICKET-04-repositories.md)

---

# TICKET-003: Component Models (PaymentTransaction + Component-Level Models)

## Summary
Implement domain models in `src/Component/Model/` that reference OXID core tables via foreign keys without extending them.

## Priority
**P1 - High**

## Story Points
**8 points** (2 days)

## Business Value
Provides core data models for transaction tracking and order lifecycle management with minimal coupling to OXID core.

---

## Description

Create Component models with FK references to OXID core:
- PaymentTransaction (core transaction model)
- PaymentOrderState (order payment state, 1:1 with oxorder)
- PaymentCustomer (customer payment data, 1:1 with oxuser)
- PaymentBasketSnapshot (basket state at payment time)
- PaymentOrderStates (state constants interface)

**Architecture Principle:** Models reference oxorder/oxuser via OXID field, NOT via class extension.

---

## Acceptance Criteria

### Must Have
- [ ] PaymentTransaction model in `src/Component/Model/`
- [ ] PaymentOrderState model in `src/Component/Model/`
- [ ] PaymentCustomer model in `src/Component/Model/`
- [ ] PaymentBasketSnapshot model in `src/Component/Model/`
- [ ] PaymentOrderStates interface in `src/Component/Model/`
- [ ] State machine logic with validation
- [ ] 100% test coverage
- [ ] Database migrations tested
- [ ] NO OXID class extensions in metadata.php

### Should Have
- [ ] State transition diagram
- [ ] Model builders for tests

---

## Technical Details

### PaymentTransaction Model (unchanged)

```php
<?php
// src/Component/Model/PaymentTransaction.php

namespace Osc\Payment\Component\Model;

use DateTimeImmutable;

/**
 * Payment Transaction
 *
 * Represents a single payment transaction.
 * Provider-agnostic - works with Stripe, Paymenter, etc.
 */
final class PaymentTransaction
{
    private ?string $id = null;
    private string $shopId;
    private string $orderId;        // FK to oxorder.OXID
    private string $providerOrderId;
    private ?string $transactionId = null;
    private string $status;
    private string $paymentMethodId;
    private string $transactionType;
    private ?array $providerData = null;
    private DateTimeImmutable $createdAt;
    private DateTimeImmutable $updatedAt;

    public function __construct(
        string $shopId,
        string $orderId,
        string $providerOrderId,
        string $status,
        string $paymentMethodId,
        string $transactionType
    ) {
        $this->validateTransactionType($transactionType);

        $this->shopId = $shopId;
        $this->orderId = $orderId;
        $this->providerOrderId = $providerOrderId;
        $this->status = $status;
        $this->paymentMethodId = $paymentMethodId;
        $this->transactionType = $transactionType;
        $this->createdAt = new DateTimeImmutable();
        $this->updatedAt = new DateTimeImmutable();
    }

    // Getters
    public function getId(): ?string { return $this->id; }
    public function getOrderId(): string { return $this->orderId; }
    public function getProviderOrderId(): string { return $this->providerOrderId; }
    public function getStatus(): string { return $this->status; }

    // Setters
    public function setId(string $id): void { $this->id = $id; }
    public function setStatus(string $status): void { $this->status = $status; }

    // State checks
    public function isCompleted(): bool { return $this->status === 'completed'; }
    public function isRefunded(): bool { return $this->status === 'refunded'; }
    public function isPending(): bool { return $this->status === 'pending'; }

    private function validateTransactionType(string $type): void
    {
        $valid = ['capture', 'authorization', 'refund', 'void'];
        if (!in_array($type, $valid, true)) {
            throw new \InvalidArgumentException("Invalid type: $type");
        }
    }
}
```

### PaymentOrderState Model (NEW - replaces extending oxorder)

```php
<?php
// src/Component/Model/PaymentOrderState.php

namespace Osc\Payment\Component\Model;

use DateTimeImmutable;

/**
 * Payment Order State
 *
 * Component-level order payment state tracking (1:1 with oxorder).
 * Replaces extending oxorder table.
 */
final class PaymentOrderState implements PaymentOrderStates
{
    private ?string $id = null;
    private string $orderId;                // FK to oxorder.OXID (1:1)
    private string $paymentState;
    private ?string $providerOrderId = null;
    private ?\DateTime $webhookWaitSince = null;
    private ?int $webhookTimeout = null;
    private ?\DateTime $lastPaymentAttempt = null;
    private int $paymentAttemptCount = 0;
    private DateTimeImmutable $createdAt;
    private DateTimeImmutable $updatedAt;

    public function __construct(string $orderId, string $paymentState = self::STATE_NOT_FINISHED)
    {
        $this->validateState($paymentState);
        $this->orderId = $orderId;
        $this->paymentState = $paymentState;
        $this->createdAt = new DateTimeImmutable();
        $this->updatedAt = new DateTimeImmutable();
    }

    // State machine methods
    public function markAsPaymentInProgress(): void
    {
        $this->validateStateTransition(self::STATE_PAYMENT_IN_PROGRESS);
        $this->paymentState = self::STATE_PAYMENT_IN_PROGRESS;
        $this->lastPaymentAttempt = new \DateTime();
        $this->paymentAttemptCount++;
    }

    public function markAsWaitingForWebhook(): void
    {
        $this->validateStateTransition(self::STATE_WAITING_FOR_WEBHOOK);
        $this->paymentState = self::STATE_WAITING_FOR_WEBHOOK;
        $this->webhookWaitSince = new \DateTime();
        $this->webhookTimeout = 300; // 5 minutes default
    }

    public function markAsCompleted(): void
    {
        $this->validateStateTransition(self::STATE_OK);
        $this->paymentState = self::STATE_OK;
        $this->webhookWaitSince = null;
    }

    public function markAsFailed(string $reason): void
    {
        $this->paymentState = self::STATE_ERROR;
    }

    // Getters
    public function getOrderId(): string { return $this->orderId; }
    public function getPaymentState(): string { return $this->paymentState; }
    public function isWaitingForWebhook(): bool {
        return $this->paymentState === self::STATE_WAITING_FOR_WEBHOOK;
    }

    private function validateState(string $state): void
    {
        if (!in_array($state, self::VALID_STATES, true)) {
            throw new \InvalidArgumentException("Invalid payment state: $state");
        }
    }

    private function validateStateTransition(string $newState): void
    {
        $validTransitions = $this->getValidTransitions();
        if (!in_array($newState, $validTransitions, true)) {
            throw new \InvalidArgumentException(
                "Invalid transition from {$this->paymentState} to $newState"
            );
        }
    }

    private function getValidTransitions(): array
    {
        return match($this->paymentState) {
            self::STATE_NOT_FINISHED => [self::STATE_PAYMENT_IN_PROGRESS],
            self::STATE_PAYMENT_IN_PROGRESS => [
                self::STATE_WAITING_FOR_WEBHOOK,
                self::STATE_OK,
                self::STATE_ERROR
            ],
            self::STATE_WAITING_FOR_WEBHOOK => [self::STATE_OK, self::STATE_ERROR],
            default => [],
        };
    }
}
```

### PaymentCustomer Model (NEW - replaces extending oxuser)

```php
<?php
// src/Component/Model/PaymentCustomer.php

namespace Osc\Payment\Component\Model;

use DateTimeImmutable;

/**
 * Payment Customer
 *
 * Component-level payment customer data (1:1 with oxuser).
 * Replaces extending oxuser table.
 */
final class PaymentCustomer
{
    private ?string $id = null;
    private string $userId;                  // FK to oxuser.OXID (1:1)
    private ?string $paymentCustomerId = null; // Provider customer ID (e.g., cus_xxx for Stripe)
    private ?string $defaultPaymentMethod = null;
    private array $savedPaymentMethods = [];
    private bool $billingAgreement = false;
    private ?\DateTime $lastPaymentDate = null;
    private DateTimeImmutable $createdAt;
    private DateTimeImmutable $updatedAt;

    public function __construct(string $userId)
    {
        $this->userId = $userId;
        $this->createdAt = new DateTimeImmutable();
        $this->updatedAt = new DateTimeImmutable();
    }

    // Getters
    public function getUserId(): string { return $this->userId; }
    public function getPaymentCustomerId(): ?string { return $this->paymentCustomerId; }

    // Setters
    public function setPaymentCustomerId(string $customerId): void {
        $this->paymentCustomerId = $customerId;
    }

    public function addSavedPaymentMethod(string $methodId): void {
        if (!in_array($methodId, $this->savedPaymentMethods, true)) {
            $this->savedPaymentMethods[] = $methodId;
        }
    }
}
```

### PaymentBasketSnapshot Model (NEW)

```php
<?php
// src/Component/Model/PaymentBasketSnapshot.php

namespace Osc\Payment\Component\Model;

use DateTimeImmutable;

/**
 * Payment Basket Snapshot
 *
 * Stores basket state at payment initiation time for reconciliation.
 */
final class PaymentBasketSnapshot
{
    private ?string $id = null;
    private string $orderId;         // FK to oxorder.OXID
    private ?string $userId = null;  // FK to oxuser.OXID
    private array $basketData;       // JSON snapshot
    private float $total;
    private string $currency;
    private ?float $discount = null;
    private ?float $shipping = null;
    private ?float $tax = null;
    private DateTimeImmutable $createdAt;

    public function __construct(
        string $orderId,
        array $basketData,
        float $total,
        string $currency,
        ?string $userId = null
    ) {
        $this->orderId = $orderId;
        $this->basketData = $basketData;
        $this->total = $total;
        $this->currency = $currency;
        $this->userId = $userId;
        $this->createdAt = new DateTimeImmutable();
    }

    // Getters
    public function getOrderId(): string { return $this->orderId; }
    public function getBasketData(): array { return $this->basketData; }
    public function getTotal(): float { return $this->total; }

    // Validation
    public function matchesTotal(float $providedTotal, float $tolerance = 0.01): bool {
        return abs($this->total - $providedTotal) <= $tolerance;
    }
}
```

### PaymentOrderStates Interface

```php
<?php
// src/Component/Model/PaymentOrderStates.php

namespace Osc\Payment\Component\Model;

/**
 * Payment Order States Interface
 *
 * Defines payment lifecycle states for orders.
 */
interface PaymentOrderStates
{
    const STATE_NOT_FINISHED = 'NOT_FINISHED';
    const STATE_PAYMENT_IN_PROGRESS = '500';
    const STATE_WAITING_FOR_WEBHOOK = '600';
    const STATE_OK = 'OK';
    const STATE_ERROR = 'ERROR';

    const VALID_STATES = [
        self::STATE_NOT_FINISHED,
        self::STATE_PAYMENT_IN_PROGRESS,
        self::STATE_WAITING_FOR_WEBHOOK,
        self::STATE_OK,
        self::STATE_ERROR,
    ];
}
```

---

## TDD Workflow

### 📘 Complete Test Examples Available

**See: [SPRINT-1-TICKET-03-TDD-EXAMPLES.md](SPRINT-1-TICKET-03-TDD-EXAMPLES.md)**

This companion document provides **65+ complete test examples** with full implementation:

- ✅ **PaymentTransaction Tests** (20+ tests)
  - Basic construction & validation
  - Transaction type validation with data providers
  - State management (completed, pending, refunded)
  - Status changes with timestamp updates
  - ID immutability
  - Provider data storage
  - Edge cases

- ✅ **PaymentOrderState Tests** (30+ tests)
  - Initial state creation
  - All valid state transitions
  - Invalid transition prevention
  - Retry logic from error state
  - Payment attempt tracking
  - Webhook timeout detection
  - Complete state machine flows

- ✅ **PaymentCustomer Tests** (8+ tests)
  - Customer creation with user FK
  - Provider customer ID management
  - Saved payment methods
  - Duplicate prevention
  - Default payment method

- ✅ **PaymentBasketSnapshot Tests** (5+ tests)
  - Basket snapshot creation
  - Total matching with tolerance
  - Discount, shipping, tax tracking

### Quick Start TDD Process

```bash
# 1. Read the test examples
cat docs/payment-component/SPRINT-1-TICKET-03-TDD-EXAMPLES.md

# 2. Copy test file template
cp tests/Component/Unit/Model/PaymentTransactionTest.php.example \
   tests/Component/Unit/Model/PaymentTransactionTest.php

# 3. Run tests (RED - they should fail)
vendor/bin/phpunit tests/Component/Unit/Model/PaymentTransactionTest.php

# 4. Implement model (GREEN - make tests pass)
# Edit: src/Component/Model/PaymentTransaction.php

# 5. Verify tests pass
vendor/bin/phpunit tests/Component/Unit/Model/PaymentTransactionTest.php

# 6. Refactor and repeat for next model
```

### Test Organization

Write tests in `tests/Component/Unit/Model/` for:
- `PaymentTransactionTest.php` - Transaction creation, validation, state changes
- `PaymentOrderStateTest.php` - State machine transitions (all 30+ scenarios)
- `PaymentCustomerTest.php` - Customer payment method management
- `PaymentBasketSnapshotTest.php` - Basket snapshot and total matching

### Coverage Requirements

- **PaymentTransaction**: 100% coverage
- **PaymentOrderState**: 100% coverage (critical state machine)
- **PaymentCustomer**: 95%+ coverage
- **PaymentBasketSnapshot**: 95%+ coverage

---

## Tasks Breakdown

1. **PaymentTransaction Model** (1 hour)
   - Write model tests
   - Implement model
   - Test validation

2. **PaymentOrderState Model** (3 hours)
   - Define PaymentOrderStates interface
   - Write state transition tests
   - Implement PaymentOrderState
   - Test all transitions

3. **PaymentCustomer & BasketSnapshot** (2 hours)
   - Implement PaymentCustomer
   - Implement PaymentBasketSnapshot
   - Write tests

4. **Integration with DB** (2 hours)
   - Test models persist correctly to new tables
   - Test state machine with real DB
   - Verify FK constraints work

---

## Definition of Done

- [ ] All models in `src/Component/Model/`
- [ ] 100% test coverage
- [ ] State machine fully tested
- [ ] Integration tests pass
- [ ] PHPStan passes
- [ ] NO class extensions in metadata.php

---


---

[← Previous: TICKET-002](SPRINT-1-TICKET-02-event-layer.md) | [Back to Sprint Overview](SPRINT-1-overview.md) | [Back to Index](SPRINT-1-index.md) | [Next: TICKET-004 →](SPRINT-1-TICKET-04-repositories.md)
