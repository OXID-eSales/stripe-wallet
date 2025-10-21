# Database Implementation Guide - Sprint 1
## TDD Approach for Migrations, Models, and Repositories

**Version:** 1.0.0
**Date:** 2025-10-17
**Target:** OXID eShop 7.4+
**Philosophy:** Test-First Development with Clean Architecture
**Related Docs:**
- [02-database-and-models.md](02-database-and-models.md) - Database schema and architecture
- [09-tdd-strategy.md](09-tdd-strategy.md) - Complete TDD strategy
- [10-test-organization.md](10-test-organization.md) - Component vs Provider tests
- [IMPLEMENTATION-TICKETS-SPRINT-1.md](IMPLEMENTATION-TICKETS-SPRINT-1.md) - Sprint tickets

---

## Table of Contents

1. [Overview](#overview)
2. [TDD Philosophy for Database Layer](#tdd-philosophy-for-database-layer)
3. [Migration Strategy](#migration-strategy)
4. [Component Models Implementation](#component-models-implementation)
5. [Repository Pattern Implementation](#repository-pattern-implementation)
6. [Test-First Workflow](#test-first-workflow)
7. [Database Testing Strategy](#database-testing-strategy)
8. [Implementation Checklist](#implementation-checklist)

---

## Overview

### Goals for Sprint 1 Database Work

**Primary Objectives:**
1. ✅ Create component tables using **FK references** (NOT ALTER TABLE on OXID core)
2. ✅ Implement domain models with proper state management
3. ✅ Build repository layer with full test coverage
4. ✅ Ensure 100% data integrity through constraints
5. ✅ Achieve 95%+ test coverage on database layer

**Architecture Principles:**
- **NO class extensions** in metadata.php
- **Component tables reference OXID core** via FK (OXORDERID → oxorder.OXID)
- **Clean isolation** - can drop component tables without affecting core
- **Test-first approach** - write tests before implementation

---

## TDD Philosophy for Database Layer

### The Red-Green-Refactor Cycle

```
┌──────────────────────────────────────────────────────────┐
│  1. RED: Write failing test                              │
│     ↓                                                     │
│  2. GREEN: Write minimal code to pass                    │
│     ↓                                                     │
│  3. REFACTOR: Improve code without breaking tests        │
│     ↓                                                     │
│  4. REPEAT: Next feature                                 │
└──────────────────────────────────────────────────────────┘
```

### Why TDD for Database Layer?

| Benefit | Impact |
|---------|--------|
| **Data Integrity** | Constraints tested before implementation |
| **FK Relationships** | Verify cascade behaviors work correctly |
| **State Transitions** | Test all valid/invalid state changes |
| **Concurrent Access** | Test race conditions and locks |
| **Performance** | Benchmark queries during development |
| **Regression Prevention** | Catch schema changes that break logic |

### Test Priority for Database Layer

**🔴 CRITICAL (P0)** - Must test FIRST:
- Foreign key constraints (CASCADE, SET NULL)
- Unique constraints (prevent duplicates)
- Required fields validation (NOT NULL)
- Data integrity (refund ≤ captured amount)
- State machine transitions (invalid states rejected)
- Concurrent access (pessimistic locking)

**🟠 HIGH (P1)** - Test SECOND:
- Repository CRUD operations
- Query performance
- Transaction rollback
- Audit trail immutability

**🟡 MEDIUM (P2)** - Test THIRD:
- Complex queries (JOINs)
- Computed columns
- Index efficiency

---

## Migration Strategy

### Migration Files Structure

**Location:** `migration/` directory in component

```
migration/
├── 001_create_payment_transaction_table.sql
├── 002_create_payment_authorization_details_table.sql
├── 003_create_payment_3ds_details_table.sql
├── 004_create_payment_refund_details_table.sql
├── 005_create_payment_delivery_tracking_table.sql
├── 006_create_payment_provider_data_table.sql
├── 007_create_payment_order_state_table.sql
├── 008_create_payment_customer_table.sql
├── 009_create_payment_idempotency_table.sql
├── 010_create_payment_saved_methods_table.sql
└── 011_create_payment_sessions_table.sql
```

### TDD Approach for Migrations

#### Step 1: Write Migration Test FIRST

```php
<?php
// tests/Component/Integration/Migration/PaymentTransactionMigrationTest.php

namespace Tests\Component\Integration\Migration;

use Tests\Component\Support\IntegrationTestCase;

/**
 * Test migration creates correct schema
 *
 * @group migration
 * @group database
 */
class PaymentTransactionMigrationTest extends IntegrationTestCase
{
    /** @test */
    public function migration_001_creates_payment_transaction_table(): void
    {
        // Arrange
        $this->dropAllTables();

        // Act
        $this->runMigration('001_create_payment_transaction_table.sql');

        // Assert - Table exists
        $this->assertTableExists('osc_payment_transaction');

        // Assert - Required columns exist
        $this->assertColumnExists('osc_payment_transaction', 'OXID');
        $this->assertColumnExists('osc_payment_transaction', 'OXSHOPID');
        $this->assertColumnExists('osc_payment_transaction', 'OXORDERID');
        $this->assertColumnExists('osc_payment_transaction', 'OXPROVIDER');
        $this->assertColumnExists('osc_payment_transaction', 'OXTYPE');
        $this->assertColumnExists('osc_payment_transaction', 'OXSTATUS');
        $this->assertColumnExists('osc_payment_transaction', 'OXAMOUNT');
        $this->assertColumnExists('osc_payment_transaction', 'OXCURRENCY');

        // Assert - Column types correct
        $this->assertColumnType('osc_payment_transaction', 'OXID', 'CHAR', 32);
        $this->assertColumnType('osc_payment_transaction', 'OXAMOUNT', 'DECIMAL', [10, 2]);

        // Assert - Primary key exists
        $this->assertPrimaryKeyExists('osc_payment_transaction', 'OXID');

        // Assert - Indexes exist
        $this->assertIndexExists('osc_payment_transaction', 'IDX_ORDER');
        $this->assertIndexExists('osc_payment_transaction', 'IDX_PROVIDER_ORDER');
        $this->assertIndexExists('osc_payment_transaction', 'IDX_TYPE_STATUS');
    }

    /** @test */
    public function migration_001_creates_foreign_key_to_oxorder(): void
    {
        // Arrange
        $this->runMigrations(['001_create_payment_transaction_table.sql']);

        // Assert - FK exists
        $this->assertForeignKeyExists(
            'osc_payment_transaction',
            'FK_ORDER',
            'OXORDERID',
            'oxorder',
            'OXID'
        );

        // Assert - FK has correct ON DELETE CASCADE
        $this->assertForeignKeyOnDelete('osc_payment_transaction', 'FK_ORDER', 'CASCADE');
    }

    /** @test */
    public function transaction_deleted_when_order_deleted_due_to_fk_cascade(): void
    {
        // Arrange
        $this->runMigrations(['001_create_payment_transaction_table.sql']);

        $orderId = $this->createTestOrder(['OXID' => 'test-order-123']);
        $transactionId = $this->insertTransaction([
            'OXID' => 'test-tx-123',
            'OXORDERID' => 'test-order-123',
            'OXPROVIDER' => 'stripe',
            'OXTYPE' => 'capture',
            'OXSTATUS' => 'completed',
            'OXAMOUNT' => 99.99,
            'OXCURRENCY' => 'EUR'
        ]);

        // Act - Delete order
        $this->deleteOrder('test-order-123');

        // Assert - Transaction was cascade deleted
        $this->assertTransactionNotExists('test-tx-123');
    }
}
```

#### Step 2: Create Migration SQL

```sql
-- migration/001_create_payment_transaction_table.sql

CREATE TABLE IF NOT EXISTS osc_payment_transaction (
    OXID CHAR(32) NOT NULL PRIMARY KEY COMMENT 'Primary key',
    OXSHOPID INT(11) NOT NULL COMMENT 'Shop ID',
    OXORDERID CHAR(32) NOT NULL COMMENT 'FK to oxorder.OXID',

    -- Provider identification
    OXPROVIDER VARCHAR(32) NOT NULL COMMENT 'Provider name (stripe, paypal, unzer)',
    OXPROVIDERORDERID VARCHAR(128) NULL COMMENT 'Provider order ID (pi_xxx for Stripe)',
    OXTRANSACTIONID VARCHAR(128) NULL COMMENT 'Provider transaction/charge ID',

    -- Transaction basics
    OXTYPE VARCHAR(32) NOT NULL COMMENT 'Transaction type (authorization, capture, refund, void)',
    OXSTATUS VARCHAR(32) NOT NULL COMMENT 'Status (pending, completed, failed, cancelled)',
    OXAMOUNT DECIMAL(10,2) NOT NULL COMMENT 'Transaction amount',
    OXCURRENCY VARCHAR(3) NOT NULL COMMENT 'Currency code (ISO 4217)',

    -- Payment method
    OXPAYMENTMETHODID VARCHAR(64) NULL COMMENT 'Payment method identifier',
    OXPAYMENTMETHODTYPE VARCHAR(32) NULL COMMENT 'Payment method type',

    -- Relationships
    OXPARENTTRANSACTIONID CHAR(32) NULL COMMENT 'Parent transaction ID',

    -- Timestamps
    OXCREATED DATETIME NOT NULL COMMENT 'Created timestamp',
    OXUPDATED DATETIME NOT NULL COMMENT 'Updated timestamp',

    -- Indexes for performance
    INDEX IDX_ORDER (OXORDERID),
    INDEX IDX_PROVIDER_ORDER (OXPROVIDERORDERID),
    INDEX IDX_TRANSACTION_ID (OXTRANSACTIONID),
    INDEX IDX_TYPE_STATUS (OXTYPE, OXSTATUS),
    INDEX IDX_PARENT (OXPARENTTRANSACTIONID),

    -- Foreign key constraints (CASCADE on order delete)
    FOREIGN KEY FK_ORDER (OXORDERID)
        REFERENCES oxorder(OXID)
        ON DELETE CASCADE,

    FOREIGN KEY FK_PARENT_TX (OXPARENTTRANSACTIONID)
        REFERENCES osc_payment_transaction(OXID)
        ON DELETE SET NULL

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Payment transactions - master table';
```

#### Step 3: Run Test → See it PASS

```bash
vendor/bin/phpunit --filter PaymentTransactionMigrationTest
```

### Testing Foreign Key Constraints

**Critical Test Scenarios:**

```php
/** @test */
public function cannot_insert_transaction_with_invalid_order_id(): void
{
    $this->expectException(\PDOException::class);
    $this->expectExceptionMessage('FOREIGN KEY constraint failed');

    // Try to insert transaction with non-existent order
    $this->insertTransaction([
        'OXID' => 'tx-123',
        'OXORDERID' => 'nonexistent-order', // Invalid FK
        'OXPROVIDER' => 'stripe',
        'OXTYPE' => 'capture',
        'OXSTATUS' => 'completed',
        'OXAMOUNT' => 99.99,
        'OXCURRENCY' => 'EUR'
    ]);
}

/** @test */
public function cascade_deletes_all_related_transactions_when_order_deleted(): void
{
    $orderId = $this->createTestOrder(['OXID' => 'order-123']);

    // Create multiple transactions for same order
    $this->insertTransaction(['OXID' => 'tx-1', 'OXORDERID' => 'order-123', ...]);
    $this->insertTransaction(['OXID' => 'tx-2', 'OXORDERID' => 'order-123', ...]);
    $this->insertTransaction(['OXID' => 'tx-3', 'OXORDERID' => 'order-123', ...]);

    // Delete order
    $this->deleteOrder('order-123');

    // All transactions should be deleted
    $this->assertTransactionNotExists('tx-1');
    $this->assertTransactionNotExists('tx-2');
    $this->assertTransactionNotExists('tx-3');
}
```

---

## Component Models Implementation

### TDD Workflow for Models

**Workflow:**
1. ✅ Write model test FIRST (RED)
2. ✅ Implement minimal model code (GREEN)
3. ✅ Refactor for clean code (REFACTOR)
4. ✅ Repeat for next feature

### Model 1: PaymentTransaction (Master Table)

#### Step 1: Write Test FIRST

```php
<?php
// tests/Component/Unit/Model/PaymentTransactionTest.php

namespace Tests\Component\Unit\Model;

use PHPUnit\Framework\TestCase;
use PaymentComponent\Model\PaymentTransaction;

/**
 * Unit tests for PaymentTransaction model
 *
 * @group unit
 * @group model
 */
class PaymentTransactionTest extends TestCase
{
    /** @test */
    public function it_creates_transaction_with_required_fields(): void
    {
        // Arrange & Act
        $transaction = new PaymentTransaction(
            shopId: '1',
            orderId: 'order-123',
            provider: 'stripe',
            providerOrderId: 'pi_123',
            type: 'authorization',
            status: 'completed',
            amount: 99.99,
            currency: 'EUR'
        );

        // Assert
        $this->assertEquals('1', $transaction->getShopId());
        $this->assertEquals('order-123', $transaction->getOrderId());
        $this->assertEquals('stripe', $transaction->getProvider());
        $this->assertEquals('pi_123', $transaction->getProviderOrderId());
        $this->assertEquals('authorization', $transaction->getType());
        $this->assertEquals('completed', $transaction->getStatus());
        $this->assertEquals(99.99, $transaction->getAmount());
        $this->assertEquals('EUR', $transaction->getCurrency());
        $this->assertNull($transaction->getId()); // Not persisted yet
    }

    /** @test */
    public function it_marks_transaction_as_completed(): void
    {
        $transaction = new PaymentTransaction(
            shopId: '1',
            orderId: 'order-123',
            provider: 'stripe',
            providerOrderId: 'pi_123',
            type: 'capture',
            status: 'pending',
            amount: 99.99,
            currency: 'EUR'
        );

        // Act
        $transaction->markAsCompleted();

        // Assert
        $this->assertEquals('completed', $transaction->getStatus());
    }

    /** @test */
    public function it_marks_transaction_as_failed(): void
    {
        $transaction = new PaymentTransaction(
            shopId: '1',
            orderId: 'order-123',
            provider: 'stripe',
            providerOrderId: 'pi_123',
            type: 'capture',
            status: 'pending',
            amount: 99.99,
            currency: 'EUR'
        );

        // Act
        $transaction->markAsFailed();

        // Assert
        $this->assertEquals('failed', $transaction->getStatus());
    }

    /** @test */
    public function it_sets_transaction_id(): void
    {
        $transaction = new PaymentTransaction(...);

        $this->assertNull($transaction->getTransactionId());

        $transaction->setTransactionId('ch_123');

        $this->assertEquals('ch_123', $transaction->getTransactionId());
    }

    /** @test */
    public function it_validates_transaction_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid transaction type');

        new PaymentTransaction(
            shopId: '1',
            orderId: 'order-123',
            provider: 'stripe',
            providerOrderId: 'pi_123',
            type: 'invalid_type', // Invalid
            status: 'pending',
            amount: 99.99,
            currency: 'EUR'
        );
    }

    /** @test */
    public function it_validates_amount_is_positive(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Amount must be positive');

        new PaymentTransaction(
            shopId: '1',
            orderId: 'order-123',
            provider: 'stripe',
            providerOrderId: 'pi_123',
            type: 'capture',
            status: 'pending',
            amount: -50.00, // Negative
            currency: 'EUR'
        );
    }

    /** @test */
    public function it_validates_currency_format(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Currency must be 3-letter ISO code');

        new PaymentTransaction(
            shopId: '1',
            orderId: 'order-123',
            provider: 'stripe',
            providerOrderId: 'pi_123',
            type: 'capture',
            status: 'pending',
            amount: 99.99,
            currency: 'EURO' // Invalid (must be 3 letters)
        );
    }
}
```

#### Step 2: Implement Model

```php
<?php
// src/Component/Model/PaymentTransaction.php

namespace PaymentComponent\Model;

final class PaymentTransaction
{
    private const VALID_TYPES = ['authorization', 'capture', 'refund', 'void'];
    private const VALID_STATUSES = ['pending', 'completed', 'failed', 'cancelled'];

    private ?string $id = null;
    private string $shopId;
    private string $orderId;
    private string $provider;
    private string $providerOrderId;
    private ?string $transactionId = null;
    private string $type;
    private string $status;
    private float $amount;
    private string $currency;
    private ?string $paymentMethodId = null;
    private ?string $paymentMethodType = null;
    private ?string $parentTransactionId = null;
    private ?\DateTimeImmutable $createdAt = null;
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct(
        string $shopId,
        string $orderId,
        string $provider,
        string $providerOrderId,
        string $type,
        string $status,
        float $amount,
        string $currency
    ) {
        $this->validateType($type);
        $this->validateStatus($status);
        $this->validateAmount($amount);
        $this->validateCurrency($currency);

        $this->shopId = $shopId;
        $this->orderId = $orderId;
        $this->provider = $provider;
        $this->providerOrderId = $providerOrderId;
        $this->type = $type;
        $this->status = $status;
        $this->amount = $amount;
        $this->currency = $currency;
    }

    // State management
    public function markAsCompleted(): void
    {
        $this->status = 'completed';
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function markAsFailed(): void
    {
        $this->status = 'failed';
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function markAsCancelled(): void
    {
        $this->status = 'cancelled';
        $this->updatedAt = new \DateTimeImmutable();
    }

    // Setters (used by repository after persistence)
    public function setId(string $id): void
    {
        if ($this->id !== null) {
            throw new \LogicException('ID is immutable once set');
        }
        $this->id = $id;
    }

    public function setTransactionId(string $transactionId): void
    {
        $this->transactionId = $transactionId;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function setPaymentMethodId(string $paymentMethodId): void
    {
        $this->paymentMethodId = $paymentMethodId;
    }

    public function setPaymentMethodType(string $paymentMethodType): void
    {
        $this->paymentMethodType = $paymentMethodType;
    }

    public function setParentTransactionId(string $parentTransactionId): void
    {
        $this->parentTransactionId = $parentTransactionId;
    }

    // Getters
    public function getId(): ?string { return $this->id; }
    public function getShopId(): string { return $this->shopId; }
    public function getOrderId(): string { return $this->orderId; }
    public function getProvider(): string { return $this->provider; }
    public function getProviderOrderId(): string { return $this->providerOrderId; }
    public function getTransactionId(): ?string { return $this->transactionId; }
    public function getType(): string { return $this->type; }
    public function getStatus(): string { return $this->status; }
    public function getAmount(): float { return $this->amount; }
    public function getCurrency(): string { return $this->currency; }
    public function getPaymentMethodId(): ?string { return $this->paymentMethodId; }
    public function getPaymentMethodType(): ?string { return $this->paymentMethodType; }
    public function getParentTransactionId(): ?string { return $this->parentTransactionId; }
    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }

    // State checks
    public function isCompleted(): bool { return $this->status === 'completed'; }
    public function isFailed(): bool { return $this->status === 'failed'; }
    public function isPending(): bool { return $this->status === 'pending'; }
    public function isCancelled(): bool { return $this->status === 'cancelled'; }

    // Validation
    private function validateType(string $type): void
    {
        if (!in_array($type, self::VALID_TYPES, true)) {
            throw new \InvalidArgumentException(
                sprintf('Invalid transaction type: %s. Valid types: %s',
                    $type,
                    implode(', ', self::VALID_TYPES)
                )
            );
        }
    }

    private function validateStatus(string $status): void
    {
        if (!in_array($status, self::VALID_STATUSES, true)) {
            throw new \InvalidArgumentException(
                sprintf('Invalid status: %s', $status)
            );
        }
    }

    private function validateAmount(float $amount): void
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Amount must be positive');
        }
    }

    private function validateCurrency(string $currency): void
    {
        if (strlen($currency) !== 3) {
            throw new \InvalidArgumentException('Currency must be 3-letter ISO code');
        }
    }
}
```

#### Step 3: Run Tests → See GREEN

```bash
vendor/bin/phpunit tests/Component/Unit/Model/PaymentTransactionTest.php
```

### Model 2: PaymentOrderState (State Machine)

#### Step 1: Write Test FIRST

```php
<?php
// tests/Component/Unit/Model/PaymentOrderStateTest.php

namespace Tests\Component\Unit\Model;

use PHPUnit\Framework\TestCase;
use PaymentComponent\Model\PaymentOrderState;
use PaymentComponent\Model\PaymentOrderStates;

class PaymentOrderStateTest extends TestCase
{
    /** @test */
    public function it_creates_order_state_with_default_not_finished(): void
    {
        $state = new PaymentOrderState('order-123');

        $this->assertEquals('order-123', $state->getOrderId());
        $this->assertEquals(PaymentOrderStates::STATE_NOT_FINISHED, $state->getPaymentState());
        $this->assertNull($state->getProviderOrderId());
    }

    /** @test */
    public function it_transitions_from_not_finished_to_payment_in_progress(): void
    {
        $state = new PaymentOrderState('order-123');

        $state->markAsPaymentInProgress();

        $this->assertEquals(PaymentOrderStates::STATE_PAYMENT_IN_PROGRESS, $state->getPaymentState());
    }

    /** @test */
    public function it_transitions_from_payment_in_progress_to_waiting_for_webhook(): void
    {
        $state = new PaymentOrderState('order-123');
        $state->markAsPaymentInProgress();

        $state->markAsWaitingForWebhook();

        $this->assertEquals(PaymentOrderStates::STATE_WAITING_FOR_WEBHOOK, $state->getPaymentState());
        $this->assertInstanceOf(\DateTimeImmutable::class, $state->getWebhookWaitSince());
    }

    /** @test */
    public function it_transitions_from_waiting_to_ok(): void
    {
        $state = new PaymentOrderState('order-123');
        $state->markAsPaymentInProgress();
        $state->markAsWaitingForWebhook();

        $state->markAsCompleted();

        $this->assertEquals(PaymentOrderStates::STATE_OK, $state->getPaymentState());
    }

    /** @test */
    public function it_prevents_invalid_state_transition(): void
    {
        $state = new PaymentOrderState('order-123');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Invalid state transition');

        // Cannot go from NOT_FINISHED directly to OK
        $state->markAsCompleted();
    }

    /** @test */
    public function it_tracks_payment_attempt_count(): void
    {
        $state = new PaymentOrderState('order-123');

        $this->assertEquals(0, $state->getPaymentAttemptCount());

        $state->incrementPaymentAttempt();
        $state->incrementPaymentAttempt();

        $this->assertEquals(2, $state->getPaymentAttemptCount());
    }

    /** @test */
    public function it_checks_if_webhook_timed_out(): void
    {
        $state = new PaymentOrderState('order-123');
        $state->markAsPaymentInProgress();
        $state->markAsWaitingForWebhook(timeoutSeconds: 300); // 5 minutes

        // Not timed out immediately
        $this->assertFalse($state->isWebhookTimedOut());

        // Simulate time passing (would need to mock time in real implementation)
        // For testing, we can expose a method to set webhook wait time
        $state->setWebhookWaitSince(new \DateTimeImmutable('-10 minutes'));

        $this->assertTrue($state->isWebhookTimedOut());
    }
}
```

#### Step 2: Implement PaymentOrderState Model

```php
<?php
// src/Component/Model/PaymentOrderState.php

namespace PaymentComponent\Model;

final class PaymentOrderState implements PaymentOrderStates
{
    private ?string $id = null;
    private string $orderId;
    private string $paymentState;
    private ?string $providerOrderId = null;
    private ?\DateTimeImmutable $webhookWaitSince = null;
    private ?int $webhookTimeout = null;
    private ?\DateTimeImmutable $lastPaymentAttempt = null;
    private int $paymentAttemptCount = 0;
    private ?\DateTimeImmutable $createdAt = null;
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct(
        string $orderId,
        string $paymentState = self::STATE_NOT_FINISHED
    ) {
        $this->orderId = $orderId;
        $this->paymentState = $paymentState;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    // State machine transitions
    public function markAsPaymentInProgress(): void
    {
        $this->validateStateTransition(self::STATE_PAYMENT_IN_PROGRESS);
        $this->paymentState = self::STATE_PAYMENT_IN_PROGRESS;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function markAsWaitingForWebhook(int $timeoutSeconds = 600): void
    {
        $this->validateStateTransition(self::STATE_WAITING_FOR_WEBHOOK);
        $this->paymentState = self::STATE_WAITING_FOR_WEBHOOK;
        $this->webhookWaitSince = new \DateTimeImmutable();
        $this->webhookTimeout = $timeoutSeconds;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function markAsCompleted(): void
    {
        $this->validateStateTransition(self::STATE_OK);
        $this->paymentState = self::STATE_OK;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function markAsError(): void
    {
        $this->validateStateTransition(self::STATE_ERROR);
        $this->paymentState = self::STATE_ERROR;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function incrementPaymentAttempt(): void
    {
        $this->paymentAttemptCount++;
        $this->lastPaymentAttempt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function isWebhookTimedOut(): bool
    {
        if ($this->webhookWaitSince === null || $this->webhookTimeout === null) {
            return false;
        }

        $now = new \DateTimeImmutable();
        $elapsed = $now->getTimestamp() - $this->webhookWaitSince->getTimestamp();

        return $elapsed > $this->webhookTimeout;
    }

    // Setters
    public function setId(string $id): void
    {
        if ($this->id !== null) {
            throw new \LogicException('ID is immutable once set');
        }
        $this->id = $id;
    }

    public function setProviderOrderId(string $providerOrderId): void
    {
        $this->providerOrderId = $providerOrderId;
        $this->updatedAt = new \DateTimeImmutable();
    }

    // For testing only
    public function setWebhookWaitSince(\DateTimeImmutable $time): void
    {
        $this->webhookWaitSince = $time;
    }

    // Getters
    public function getId(): ?string { return $this->id; }
    public function getOrderId(): string { return $this->orderId; }
    public function getPaymentState(): string { return $this->paymentState; }
    public function getProviderOrderId(): ?string { return $this->providerOrderId; }
    public function getWebhookWaitSince(): ?\DateTimeImmutable { return $this->webhookWaitSince; }
    public function getWebhookTimeout(): ?int { return $this->webhookTimeout; }
    public function getLastPaymentAttempt(): ?\DateTimeImmutable { return $this->lastPaymentAttempt; }
    public function getPaymentAttemptCount(): int { return $this->paymentAttemptCount; }
    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }

    // Validation
    private function validateStateTransition(string $newState): void
    {
        $validTransitions = [
            self::STATE_NOT_FINISHED => [self::STATE_PAYMENT_IN_PROGRESS],
            self::STATE_PAYMENT_IN_PROGRESS => [self::STATE_WAITING_FOR_WEBHOOK, self::STATE_OK, self::STATE_ERROR],
            self::STATE_WAITING_FOR_WEBHOOK => [self::STATE_OK, self::STATE_ERROR],
            self::STATE_OK => [],
            self::STATE_ERROR => [self::STATE_PAYMENT_IN_PROGRESS], // Allow retry
        ];

        $allowedNextStates = $validTransitions[$this->paymentState] ?? [];

        if (!in_array($newState, $allowedNextStates, true)) {
            throw new \LogicException(
                sprintf(
                    'Invalid state transition from %s to %s',
                    $this->paymentState,
                    $newState
                )
            );
        }
    }
}
```

```php
<?php
// src/Component/Model/PaymentOrderStates.php

namespace PaymentComponent\Model;

interface PaymentOrderStates
{
    public const STATE_NOT_FINISHED = 'NOT_FINISHED';
    public const STATE_PAYMENT_IN_PROGRESS = '500';
    public const STATE_WAITING_FOR_WEBHOOK = '600';
    public const STATE_OK = 'OK';
    public const STATE_ERROR = 'ERROR';
}
```

---

## Repository Pattern Implementation

### TDD Workflow for Repositories

#### Step 1: Write Repository Test FIRST

```php
<?php
// tests/Component/Integration/Repository/PaymentTransactionRepositoryTest.php

namespace Tests\Component\Integration\Repository;

use Tests\Component\Support\IntegrationTestCase;
use PaymentComponent\Repository\PaymentTransactionRepository;
use PaymentComponent\Model\PaymentTransaction;

/**
 * Integration tests for PaymentTransactionRepository
 *
 * @group integration
 * @group repository
 * @group database
 */
class PaymentTransactionRepositoryTest extends IntegrationTestCase
{
    private PaymentTransactionRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new PaymentTransactionRepository($this->getDatabase());
        $this->runMigrations();
    }

    /** @test */
    public function it_persists_new_transaction(): void
    {
        // Arrange
        $orderId = $this->createTestOrder();

        $transaction = new PaymentTransaction(
            shopId: '1',
            orderId: $orderId,
            provider: 'stripe',
            providerOrderId: 'pi_123',
            type: 'authorization',
            status: 'completed',
            amount: 99.99,
            currency: 'EUR'
        );

        // Act
        $this->repository->save($transaction);

        // Assert
        $this->assertNotNull($transaction->getId());
        $this->assertDatabaseHas('osc_payment_transaction', [
            'OXID' => $transaction->getId(),
            'OXORDERID' => $orderId,
            'OXPROVIDERORDERID' => 'pi_123'
        ]);
    }

    /** @test */
    public function it_finds_transaction_by_id(): void
    {
        $orderId = $this->createTestOrder();
        $transaction = new PaymentTransaction(
            shopId: '1',
            orderId: $orderId,
            provider: 'stripe',
            providerOrderId: 'pi_123',
            type: 'capture',
            status: 'completed',
            amount: 50.00,
            currency: 'EUR'
        );

        $this->repository->save($transaction);
        $txId = $transaction->getId();

        // Act
        $found = $this->repository->findById($txId);

        // Assert
        $this->assertNotNull($found);
        $this->assertEquals($txId, $found->getId());
        $this->assertEquals('pi_123', $found->getProviderOrderId());
        $this->assertEquals(50.00, $found->getAmount());
    }

    /** @test */
    public function it_returns_null_when_transaction_not_found(): void
    {
        $result = $this->repository->findById('nonexistent-id');

        $this->assertNull($result);
    }

    /** @test */
    public function it_finds_all_transactions_by_order_id(): void
    {
        $orderId = $this->createTestOrder();

        // Create multiple transactions for same order
        $tx1 = new PaymentTransaction('1', $orderId, 'stripe', 'pi_auth', 'authorization', 'completed', 100.00, 'EUR');
        $tx2 = new PaymentTransaction('1', $orderId, 'stripe', 'pi_capture', 'capture', 'completed', 100.00, 'EUR');
        $tx3 = new PaymentTransaction('1', $orderId, 'stripe', 'pi_refund', 'refund', 'completed', 50.00, 'EUR');

        $this->repository->save($tx1);
        $this->repository->save($tx2);
        $this->repository->save($tx3);

        // Act
        $transactions = $this->repository->findAllByOrderId($orderId);

        // Assert
        $this->assertCount(3, $transactions);
        $this->assertEquals('pi_auth', $transactions[0]->getProviderOrderId());
        $this->assertEquals('pi_capture', $transactions[1]->getProviderOrderId());
        $this->assertEquals('pi_refund', $transactions[2]->getProviderOrderId());
    }

    /** @test */
    public function it_updates_existing_transaction(): void
    {
        $orderId = $this->createTestOrder();
        $transaction = new PaymentTransaction('1', $orderId, 'stripe', 'pi_123', 'capture', 'pending', 99.99, 'EUR');

        $this->repository->save($transaction);
        $txId = $transaction->getId();

        // Modify transaction
        $transaction->markAsCompleted();
        $transaction->setTransactionId('ch_123');

        // Act - Update
        $this->repository->save($transaction);

        // Assert
        $updated = $this->repository->findById($txId);
        $this->assertEquals('completed', $updated->getStatus());
        $this->assertEquals('ch_123', $updated->getTransactionId());
    }

    /** @test */
    public function it_enforces_foreign_key_constraint(): void
    {
        $this->expectException(\PDOException::class);

        $transaction = new PaymentTransaction(
            '1',
            'invalid-order-id', // Non-existent order
            'stripe',
            'pi_123',
            'capture',
            'completed',
            99.99,
            'EUR'
        );

        $this->repository->save($transaction);
    }

    /** @test */
    public function it_cascades_delete_when_order_deleted(): void
    {
        $orderId = $this->createTestOrder();
        $transaction = new PaymentTransaction('1', $orderId, 'stripe', 'pi_123', 'capture', 'completed', 99.99, 'EUR');

        $this->repository->save($transaction);
        $txId = $transaction->getId();

        // Delete order
        $this->deleteOrder($orderId);

        // Transaction should be deleted due to CASCADE
        $found = $this->repository->findById($txId);
        $this->assertNull($found);
    }
}
```

#### Step 2: Implement Repository

```php
<?php
// src/Component/Repository/PaymentTransactionRepository.php

namespace PaymentComponent\Repository;

use PaymentComponent\Model\PaymentTransaction;
use PaymentComponent\Contract\PaymentTransactionRepositoryInterface;

final class PaymentTransactionRepository implements PaymentTransactionRepositoryInterface
{
    private \PDO $db;

    public function __construct(\PDO $db)
    {
        $this->db = $db;
    }

    public function save(PaymentTransaction $transaction): void
    {
        if ($transaction->getId() === null) {
            $this->insert($transaction);
        } else {
            $this->update($transaction);
        }
    }

    public function findById(string $id): ?PaymentTransaction
    {
        $sql = "SELECT * FROM osc_payment_transaction WHERE OXID = :id";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $id]);

        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row ? $this->hydrate($row) : null;
    }

    public function findAllByOrderId(string $orderId): array
    {
        $sql = "
            SELECT * FROM osc_payment_transaction
            WHERE OXORDERID = :orderId
            ORDER BY OXCREATED ASC
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['orderId' => $orderId]);

        $transactions = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $transactions[] = $this->hydrate($row);
        }

        return $transactions;
    }

    public function findByProviderOrderId(string $providerOrderId): ?PaymentTransaction
    {
        $sql = "SELECT * FROM osc_payment_transaction WHERE OXPROVIDERORDERID = :providerOrderId";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['providerOrderId' => $providerOrderId]);

        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row ? $this->hydrate($row) : null;
    }

    private function insert(PaymentTransaction $transaction): void
    {
        $id = $this->generateId();
        $now = new \DateTimeImmutable();

        $sql = "
            INSERT INTO osc_payment_transaction (
                OXID, OXSHOPID, OXORDERID, OXPROVIDER, OXPROVIDERORDERID,
                OXTRANSACTIONID, OXTYPE, OXSTATUS, OXAMOUNT, OXCURRENCY,
                OXPAYMENTMETHODID, OXPAYMENTMETHODTYPE, OXPARENTTRANSACTIONID,
                OXCREATED, OXUPDATED
            ) VALUES (
                :id, :shopId, :orderId, :provider, :providerOrderId,
                :transactionId, :type, :status, :amount, :currency,
                :paymentMethodId, :paymentMethodType, :parentTxId,
                :created, :updated
            )
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'id' => $id,
            'shopId' => $transaction->getShopId(),
            'orderId' => $transaction->getOrderId(),
            'provider' => $transaction->getProvider(),
            'providerOrderId' => $transaction->getProviderOrderId(),
            'transactionId' => $transaction->getTransactionId(),
            'type' => $transaction->getType(),
            'status' => $transaction->getStatus(),
            'amount' => $transaction->getAmount(),
            'currency' => $transaction->getCurrency(),
            'paymentMethodId' => $transaction->getPaymentMethodId(),
            'paymentMethodType' => $transaction->getPaymentMethodType(),
            'parentTxId' => $transaction->getParentTransactionId(),
            'created' => $now->format('Y-m-d H:i:s'),
            'updated' => $now->format('Y-m-d H:i:s')
        ]);

        // Set ID on model
        $transaction->setId($id);
    }

    private function update(PaymentTransaction $transaction): void
    {
        $sql = "
            UPDATE osc_payment_transaction
            SET
                OXTRANSACTIONID = :transactionId,
                OXSTATUS = :status,
                OXPAYMENTMETHODID = :paymentMethodId,
                OXPAYMENTMETHODTYPE = :paymentMethodType,
                OXUPDATED = :updated
            WHERE OXID = :id
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'id' => $transaction->getId(),
            'transactionId' => $transaction->getTransactionId(),
            'status' => $transaction->getStatus(),
            'paymentMethodId' => $transaction->getPaymentMethodId(),
            'paymentMethodType' => $transaction->getPaymentMethodType(),
            'updated' => (new \DateTimeImmutable())->format('Y-m-d H:i:s')
        ]);
    }

    private function hydrate(array $row): PaymentTransaction
    {
        $transaction = new PaymentTransaction(
            shopId: (string) $row['OXSHOPID'],
            orderId: $row['OXORDERID'],
            provider: $row['OXPROVIDER'],
            providerOrderId: $row['OXPROVIDERORDERID'],
            type: $row['OXTYPE'],
            status: $row['OXSTATUS'],
            amount: (float) $row['OXAMOUNT'],
            currency: $row['OXCURRENCY']
        );

        $transaction->setId($row['OXID']);

        if ($row['OXTRANSACTIONID']) {
            $transaction->setTransactionId($row['OXTRANSACTIONID']);
        }

        if ($row['OXPAYMENTMETHODID']) {
            $transaction->setPaymentMethodId($row['OXPAYMENTMETHODID']);
        }

        if ($row['OXPAYMENTMETHODTYPE']) {
            $transaction->setPaymentMethodType($row['OXPAYMENTMETHODTYPE']);
        }

        if ($row['OXPARENTTRANSACTIONID']) {
            $transaction->setParentTransactionId($row['OXPARENTTRANSACTIONID']);
        }

        return $transaction;
    }

    private function generateId(): string
    {
        return md5(uniqid((string) rand(), true));
    }
}
```

---

## Test-First Workflow

### Complete TDD Cycle for Each Feature

```
┌─────────────────────────────────────────────────────────────┐
│                    TDD WORKFLOW                              │
├─────────────────────────────────────────────────────────────┤
│                                                              │
│  1. 🔴 RED: Write failing test                              │
│     - Write test for desired behavior                       │
│     - Run test → it should FAIL                             │
│     - Verify failure message is correct                     │
│                                                              │
│  2. 🟢 GREEN: Make test pass                                │
│     - Write MINIMAL code to pass test                       │
│     - Don't optimize yet                                    │
│     - Run test → it should PASS                             │
│                                                              │
│  3. 🔵 REFACTOR: Improve code                               │
│     - Clean up code                                         │
│     - Remove duplication                                    │
│     - Improve names                                         │
│     - Run tests → all should still PASS                     │
│                                                              │
│  4. 🔁 REPEAT: Next feature                                 │
│                                                              │
└─────────────────────────────────────────────────────────────┘
```

### Example: Complete Cycle for `findByProviderOrderId`

#### Step 1: RED - Write Failing Test

```php
/** @test */
public function it_finds_transaction_by_provider_order_id(): void
{
    $orderId = $this->createTestOrder();
    $transaction = new PaymentTransaction('1', $orderId, 'stripe', 'pi_unique_123', 'capture', 'completed', 99.99, 'EUR');

    $this->repository->save($transaction);

    // Act
    $found = $this->repository->findByProviderOrderId('pi_unique_123');

    // Assert
    $this->assertNotNull($found);
    $this->assertEquals('pi_unique_123', $found->getProviderOrderId());
}
```

**Run test:**
```bash
vendor/bin/phpunit --filter it_finds_transaction_by_provider_order_id
```

**Expected output:** ❌ FAIL - Method `findByProviderOrderId` does not exist

#### Step 2: GREEN - Implement Minimal Code

```php
public function findByProviderOrderId(string $providerOrderId): ?PaymentTransaction
{
    $sql = "SELECT * FROM osc_payment_transaction WHERE OXPROVIDERORDERID = :providerOrderId";

    $stmt = $this->db->prepare($sql);
    $stmt->execute(['providerOrderId' => $providerOrderId]);

    $row = $stmt->fetch(\PDO::FETCH_ASSOC);

    return $row ? $this->hydrate($row) : null;
}
```

**Run test:**
```bash
vendor/bin/phpunit --filter it_finds_transaction_by_provider_order_id
```

**Expected output:** ✅ PASS

#### Step 3: REFACTOR - Improve Code

```php
// Add index optimization comment
public function findByProviderOrderId(string $providerOrderId): ?PaymentTransaction
{
    // Uses IDX_PROVIDER_ORDER index for fast lookup
    $sql = "SELECT * FROM osc_payment_transaction WHERE OXPROVIDERORDERID = :providerOrderId";

    $stmt = $this->db->prepare($sql);
    $stmt->execute(['providerOrderId' => $providerOrderId]);

    $row = $stmt->fetch(\PDO::FETCH_ASSOC);

    return $row ? $this->hydrate($row) : null;
}
```

**Run ALL tests:**
```bash
vendor/bin/phpunit
```

**Expected output:** ✅ ALL PASS

---

## Database Testing Strategy

### Test Categories

#### 1. Schema Tests (Migration Tests)

```php
/** @test */
public function table_has_correct_schema(): void
{
    $this->assertTableExists('osc_payment_transaction');
    $this->assertColumnExists('osc_payment_transaction', 'OXID');
    $this->assertColumnType('osc_payment_transaction', 'OXAMOUNT', 'DECIMAL', [10, 2]);
    $this->assertPrimaryKeyExists('osc_payment_transaction', 'OXID');
}
```

#### 2. Constraint Tests

```php
/** @test */
public function foreign_key_cascade_works(): void
{
    $orderId = $this->createTestOrder();
    $transaction = $this->createTestTransaction($orderId);

    $this->deleteOrder($orderId);

    $this->assertTransactionNotExists($transaction->getId());
}
```

#### 3. Repository Tests

```php
/** @test */
public function repository_saves_and_retrieves_transaction(): void
{
    $transaction = new PaymentTransaction(...);

    $this->repository->save($transaction);

    $found = $this->repository->findById($transaction->getId());
    $this->assertEquals($transaction->getProviderOrderId(), $found->getProviderOrderId());
}
```

#### 4. Concurrency Tests

```php
/** @test */
public function concurrent_updates_use_pessimistic_locking(): void
{
    // Test concurrent access with FOR UPDATE
    // Implementation depends on requirements
}
```

---

## Implementation Checklist

### Phase 1: Core Tables (Week 1, Days 1-2)

- [ ] **Migration 001: osc_payment_transaction**
  - [ ] Write migration test
  - [ ] Create migration SQL
  - [ ] Test FK to oxorder (CASCADE)
  - [ ] Test indexes work correctly
  - [ ] Verify 100% test pass

- [ ] **Migration 002: osc_payment_authorization_details**
  - [ ] Write migration test
  - [ ] Create migration SQL
  - [ ] Test FK to osc_payment_transaction
  - [ ] Test computed columns (OXISEXPIRED)
  - [ ] Verify 100% test pass

- [ ] **Migration 003: osc_payment_3ds_details**
  - [ ] Write migration test
  - [ ] Create migration SQL
  - [ ] Test 1:1 relationship with transaction
  - [ ] Verify 100% test pass

- [ ] **Migration 004: osc_payment_refund_details**
  - [ ] Write migration test
  - [ ] Create migration SQL
  - [ ] Test 1:1 relationship
  - [ ] Verify 100% test pass

### Phase 2: Support Tables (Week 1, Days 3-4)

- [ ] **Migration 007: osc_payment_order_state**
  - [ ] Write migration test
  - [ ] Create migration SQL
  - [ ] Test 1:1 relationship with oxorder (UNIQUE on OXORDERID)
  - [ ] Test state transitions
  - [ ] Verify 100% test pass

- [ ] **Migration 008: osc_payment_customer**
  - [ ] Write migration test
  - [ ] Create migration SQL
  - [ ] Test 1:1 relationship with oxuser
  - [ ] Verify 100% test pass

- [ ] **Migration 009: osc_payment_idempotency**
  - [ ] Write migration test
  - [ ] Create migration SQL
  - [ ] Test UNIQUE constraint on OXKEY
  - [ ] Verify 100% test pass

### Phase 3: Models (Week 1, Days 4-5)

- [ ] **PaymentTransaction Model**
  - [ ] Write unit tests (15+ tests)
  - [ ] Implement model
  - [ ] Test validation (type, amount, currency)
  - [ ] Test state management
  - [ ] Achieve 100% coverage

- [ ] **PaymentOrderState Model**
  - [ ] Write unit tests (12+ tests)
  - [ ] Implement state machine
  - [ ] Test all valid transitions
  - [ ] Test invalid transitions throw exceptions
  - [ ] Achieve 100% coverage

- [ ] **PaymentCustomer Model**
  - [ ] Write unit tests (8+ tests)
  - [ ] Implement model
  - [ ] Achieve 95%+ coverage

### Phase 4: Repositories (Week 2, Days 1-2)

- [ ] **PaymentTransactionRepository**
  - [ ] Write integration tests (12+ tests)
  - [ ] Implement repository
  - [ ] Test CRUD operations
  - [ ] Test FK constraints
  - [ ] Test CASCADE behavior
  - [ ] Achieve 95%+ coverage

- [ ] **PaymentOrderStateRepository**
  - [ ] Write integration tests (10+ tests)
  - [ ] Implement repository
  - [ ] Test state transitions
  - [ ] Test 1:1 relationship
  - [ ] Achieve 95%+ coverage

### Phase 5: Verification (Week 2, Day 3)

- [ ] **Run full test suite**
  - [ ] All unit tests pass
  - [ ] All integration tests pass
  - [ ] Coverage ≥ 95% for component layer

- [ ] **Performance testing**
  - [ ] Query performance benchmarks
  - [ ] Index usage verified
  - [ ] No N+1 queries

- [ ] **Code quality**
  - [ ] PHPStan level 8 passes
  - [ ] PHPCS passes
  - [ ] No code smells

---

## Summary

### Key TDD Principles for Database Layer

1. ✅ **Test migrations BEFORE running them**
2. ✅ **Test FK constraints with real cascade scenarios**
3. ✅ **Test models in isolation (unit tests)**
4. ✅ **Test repositories with real database (integration tests)**
5. ✅ **Test state machines exhaustively**
6. ✅ **Achieve 95%+ coverage**

### Benefits of TDD Approach

| Benefit | Impact |
|---------|--------|
| **Early bug detection** | Catch FK issues before production |
| **Living documentation** | Tests document expected behavior |
| **Refactoring confidence** | Change code without fear |
| **Design feedback** | Hard-to-test code = bad design |
| **Regression prevention** | Tests catch breaking changes |

### Next Steps

After completing database layer:
1. Move to Service layer (see [09-tdd-strategy.md](09-tdd-strategy.md))
2. Implement Event system (see [IMPLEMENTATION-TICKETS-SPRINT-1.md](IMPLEMENTATION-TICKETS-SPRINT-1.md))
3. Build provider adapters (see [10-test-organization.md](10-test-organization.md))

---

**Related Documentation:**
- [02-database-and-models.md](02-database-and-models.md) - Complete database schema
- [09-tdd-strategy.md](09-tdd-strategy.md) - Full TDD strategy
- [10-test-organization.md](10-test-organization.md) - Component vs Provider tests
- [IMPLEMENTATION-TICKETS-SPRINT-1.md](IMPLEMENTATION-TICKETS-SPRINT-1.md) - Sprint 1 tickets

---

**Version History:**
- v1.0.0 (2025-10-17): Initial implementation guide with TDD approach
