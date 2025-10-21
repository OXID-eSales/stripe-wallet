# TDD Strategy - Part 2 of 8: Data Persistence & Integrity

**Version:** 2.1.0
**Date:** 2025-10-16
**Target Platform:** OXID eShop 7.4+ (compatible with 7.5, 8.0+)

**Part of Series:**
- [Part 1](09-01-tdd-overview.md): Overview, Test Organization, Priority Classification, Payment Security
- **Part 2** (This document): Data Persistence & Integrity
- [Part 3](09-03-tdd-event-system.md): Event System & Business Logic, Service Layer
- [Part 4](09-04-tdd-provider-integration.md): Provider Integration, SDK-Adapter Layer
- [Part 5](09-05-tdd-authorization-flow.md): Two-Step Authorization Flow, Webhook Processing
- [Part 6](09-06-tdd-checkout-frontend.md): Checkout Frontend, Admin Features
- [Part 7](09-07-tdd-test-pyramid.md): Test Pyramid Strategy, Unit/Integration/E2E Tests, Fixtures
- [Part 8](09-08-tdd-mocking-coverage.md): Mocking Strategy, Coverage Goals, CI/CD, Best Practices

---

## Block 2: Data Persistence & Integrity 🔴 CRITICAL (P0) - Continued

This document continues the coverage of Block 2 from Part 1, focusing on repository layer testing and transaction history management.

### 2.1 Repository Layer (P0-E) - Detailed

#### Component Tables Structure

The component uses its own tables with foreign key references to OXID core tables, avoiding direct table extensions:

```sql
-- osc_payment_order_state (1:1 with oxorder)
CREATE TABLE osc_payment_order_state (
    OXID CHAR(32) NOT NULL PRIMARY KEY,
    OXORDERID CHAR(32) NOT NULL UNIQUE,  -- FK to oxorder.OXID
    OXSTATE VARCHAR(32) NOT NULL,
    OXPROVIDER_ORDER_ID VARCHAR(128),
    OXCREATED DATETIME NOT NULL,
    OXUPDATED DATETIME NOT NULL,
    FOREIGN KEY (OXORDERID) REFERENCES oxorder(OXID) ON DELETE CASCADE
);

-- osc_payment_transaction (N:1 with oxorder)
CREATE TABLE osc_payment_transaction (
    OXID CHAR(32) NOT NULL PRIMARY KEY,
    OXORDERID CHAR(32) NOT NULL,  -- FK to oxorder.OXID
    OXTRANSACTION_TYPE VARCHAR(32) NOT NULL,
    OXSTATUS VARCHAR(32) NOT NULL,
    OXAMOUNT DECIMAL(10,2) NOT NULL,
    OXCURRENCY CHAR(3) NOT NULL,
    OXPROVIDER_TRANSACTION_ID VARCHAR(128),
    OXIDEMPOTENCY_KEY VARCHAR(128),
    OXCREATED DATETIME NOT NULL,
    FOREIGN KEY (OXORDERID) REFERENCES oxorder(OXID) ON DELETE CASCADE,
    UNIQUE KEY (OXORDERID, OXIDEMPOTENCY_KEY, OXTRANSACTION_TYPE)
);

-- osc_payment_customer (1:1 with oxuser)
CREATE TABLE osc_payment_customer (
    OXID CHAR(32) NOT NULL PRIMARY KEY,
    OXUSERID CHAR(32) NOT NULL UNIQUE,  -- FK to oxuser.OXID
    OXPROVIDER_CUSTOMER_ID VARCHAR(128),
    OXCREATED DATETIME NOT NULL,
    FOREIGN KEY (OXUSERID) REFERENCES oxuser(OXID) ON DELETE CASCADE
);
```

#### Repository Test Coverage

**Test File:** `tests/Component/Integration/Repository/PaymentTransactionRepositoryTest.php`

```php
<?php

namespace PaymentComponent\Tests\Component\Integration\Repository;

use PaymentComponent\Repository\PaymentTransactionRepository;
use PaymentComponent\Model\PaymentTransaction;
use PaymentComponent\Tests\Integration\DatabaseTestCase;

class PaymentTransactionRepositoryTest extends DatabaseTestCase
{
    private PaymentTransactionRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new PaymentTransactionRepository(self::$pdo);
    }

    public function testSaveTransaction_EnforcesRequiredFields(): void
    {
        // Arrange
        $transaction = new PaymentTransaction();
        $transaction->setOrderId('order-123');
        $transaction->setTransactionType('capture');
        // Missing status and amount - should fail

        // Act & Assert
        $this->expectException(\InvalidArgumentException::class);
        $this->repository->save($transaction);
    }

    public function testGetTransactionsByOrderId_ReturnsChronological(): void
    {
        // Arrange
        $orderId = 'order-123';
        $this->createOrder($orderId);

        $transaction1 = $this->createTransaction($orderId, 'authorization', '2025-01-01 10:00:00');
        $transaction2 = $this->createTransaction($orderId, 'capture', '2025-01-01 11:00:00');
        $transaction3 = $this->createTransaction($orderId, 'refund', '2025-01-01 12:00:00');

        $this->repository->save($transaction1);
        $this->repository->save($transaction2);
        $this->repository->save($transaction3);

        // Act
        $transactions = $this->repository->getTransactionsByOrderId($orderId);

        // Assert
        $this->assertCount(3, $transactions);
        $this->assertEquals('authorization', $transactions[0]->getTransactionType());
        $this->assertEquals('capture', $transactions[1]->getTransactionType());
        $this->assertEquals('refund', $transactions[2]->getTransactionType());
    }

    public function testOrphanedTransaction_ForeignKeyPrevents(): void
    {
        // Arrange
        $transaction = new PaymentTransaction();
        $transaction->setOrderId('non-existent-order');
        $transaction->setTransactionType('capture');
        $transaction->setStatus('CAPTURED');
        $transaction->setAmount(99.99);
        $transaction->setCurrency('EUR');

        // Act & Assert
        $this->expectException(\PDOException::class);
        $this->expectExceptionMessageMatches('/foreign key constraint/i');
        $this->repository->save($transaction);
    }

    public function testPaymentOrderState_UniqueConstraintEnforced(): void
    {
        // Arrange
        $orderId = 'order-123';
        $this->createOrder($orderId);

        $state1 = new PaymentOrderState();
        $state1->setOrderId($orderId);
        $state1->setState('IN_PROGRESS');
        $this->repository->saveOrderState($state1);

        // Try to create duplicate
        $state2 = new PaymentOrderState();
        $state2->setOrderId($orderId);
        $state2->setState('COMPLETED');

        // Act & Assert
        $this->expectException(\PDOException::class);
        $this->expectExceptionMessageMatches('/duplicate entry/i');
        $this->repository->saveOrderState($state2);
    }

    public function testForeignKeyOnDeleteCascade_WorksCorrectly(): void
    {
        // Arrange
        $orderId = 'order-123';
        $this->createOrder($orderId);

        $transaction = $this->createTransaction($orderId, 'capture');
        $this->repository->save($transaction);

        // Act - Delete order (should cascade to transaction)
        self::$pdo->exec("DELETE FROM oxorder WHERE OXID = '{$orderId}'");

        // Assert
        $transactions = $this->repository->getTransactionsByOrderId($orderId);
        $this->assertEmpty($transactions);
    }

    private function createOrder(string $orderId): void
    {
        $sql = "INSERT INTO oxorder (OXID, OXTOTALORDERSUM, OXORDERNR)
                VALUES (:oxid, 99.99, 100001)";
        $stmt = self::$pdo->prepare($sql);
        $stmt->execute(['oxid' => $orderId]);
    }

    private function createTransaction(
        string $orderId,
        string $type,
        string $created = null
    ): PaymentTransaction {
        $transaction = new PaymentTransaction();
        $transaction->setOrderId($orderId);
        $transaction->setTransactionType($type);
        $transaction->setStatus('COMPLETED');
        $transaction->setAmount(99.99);
        $transaction->setCurrency('EUR');
        if ($created) {
            $transaction->setCreated($created);
        }
        return $transaction;
    }
}
```

---

### 2.2 Transaction History & Audit Trail (P0-F) - Detailed

#### Immutable Transaction Log Design

**Key Principles:**
- **Insert-only**: No updates or deletes allowed on transaction records
- **Complete audit trail**: Every transaction is tracked with timestamps
- **Transaction linking**: Refunds link to original captures, captures link to authorizations
- **Reconciliation support**: Queries to match provider statements with shop transactions

#### Audit Trail Test Coverage

**Test File:** `tests/Component/Integration/Repository/TransactionAuditTrailTest.php`

```php
<?php

namespace PaymentComponent\Tests\Component\Integration\Repository;

use PaymentComponent\Repository\PaymentTransactionRepository;
use PaymentComponent\Tests\Integration\DatabaseTestCase;

class TransactionAuditTrailTest extends DatabaseTestCase
{
    private PaymentTransactionRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new PaymentTransactionRepository(self::$pdo);
    }

    public function testTransactionHistory_ImmutableAfterCreation(): void
    {
        // Arrange
        $orderId = 'order-123';
        $this->createOrder($orderId);

        $transaction = $this->createTransaction($orderId, 'capture');
        $transaction->setStatus('CAPTURED');
        $this->repository->save($transaction);

        $transactionId = $transaction->getId();

        // Act - Try to update transaction (should not be supported)
        $retrieved = $this->repository->getById($transactionId);
        $retrieved->setStatus('REFUNDED');  // This should NOT persist

        // No update method should exist
        $this->assertFalse(method_exists($this->repository, 'update'));

        // Assert - Original transaction unchanged
        $retrieved = $this->repository->getById($transactionId);
        $this->assertEquals('CAPTURED', $retrieved->getStatus());
    }

    public function testMultipleTransactionsPerOrder_AllTracked(): void
    {
        // Arrange
        $orderId = 'order-123';
        $this->createOrder($orderId);

        // Create authorization
        $auth = $this->createTransaction($orderId, 'authorization');
        $auth->setProviderTransactionId('auth_123');
        $this->repository->save($auth);

        // Create capture
        $capture = $this->createTransaction($orderId, 'capture');
        $capture->setProviderTransactionId('cap_456');
        $capture->setParentTransactionId($auth->getId());
        $this->repository->save($capture);

        // Create partial refund
        $refund = $this->createTransaction($orderId, 'refund');
        $refund->setAmount(50.00);
        $refund->setProviderTransactionId('ref_789');
        $refund->setParentTransactionId($capture->getId());
        $this->repository->save($refund);

        // Act
        $transactions = $this->repository->getTransactionsByOrderId($orderId);

        // Assert
        $this->assertCount(3, $transactions);

        // Verify linking
        $this->assertEquals($auth->getId(), $capture->getParentTransactionId());
        $this->assertEquals($capture->getId(), $refund->getParentTransactionId());
    }

    public function testRefundLinksToOriginalCapture_AuditTrail(): void
    {
        // Arrange
        $orderId = 'order-123';
        $this->createOrder($orderId);

        $capture = $this->createTransaction($orderId, 'capture');
        $capture->setAmount(100.00);
        $this->repository->save($capture);

        $refund1 = $this->createTransaction($orderId, 'refund');
        $refund1->setAmount(30.00);
        $refund1->setParentTransactionId($capture->getId());
        $this->repository->save($refund1);

        $refund2 = $this->createTransaction($orderId, 'refund');
        $refund2->setAmount(20.00);
        $refund2->setParentTransactionId($capture->getId());
        $this->repository->save($refund2);

        // Act
        $refunds = $this->repository->getRefundsByCapture($capture->getId());
        $totalRefunded = array_sum(array_map(fn($r) => $r->getAmount(), $refunds));

        // Assert
        $this->assertCount(2, $refunds);
        $this->assertEquals(50.00, $totalRefunded);
    }

    public function testTransactionTimestamps_AccurateToMillisecond(): void
    {
        // Arrange
        $orderId = 'order-123';
        $this->createOrder($orderId);

        $transaction = $this->createTransaction($orderId, 'capture');

        // Act
        $beforeSave = microtime(true);
        $this->repository->save($transaction);
        $afterSave = microtime(true);

        // Assert
        $savedTimestamp = $transaction->getCreatedTimestamp();
        $this->assertGreaterThanOrEqual($beforeSave, $savedTimestamp);
        $this->assertLessThanOrEqual($afterSave, $savedTimestamp);
    }

    private function createOrder(string $orderId): void
    {
        $sql = "INSERT INTO oxorder (OXID, OXTOTALORDERSUM, OXORDERNR)
                VALUES (:oxid, 99.99, 100001)";
        $stmt = self::$pdo->prepare($sql);
        $stmt->execute(['oxid' => $orderId]);
    }

    private function createTransaction(string $orderId, string $type): PaymentTransaction
    {
        $transaction = new PaymentTransaction();
        $transaction->setOrderId($orderId);
        $transaction->setTransactionType($type);
        $transaction->setStatus('COMPLETED');
        $transaction->setAmount(99.99);
        $transaction->setCurrency('EUR');
        return $transaction;
    }
}
```

---

## Related Documentation

- **[Part 1: Overview](09-01-tdd-overview.md)** - Priority classification and payment security
- **[Part 3: Event System](09-03-tdd-event-system.md)** - Event layer and service layer testing (continues from here)
- **[Test Organization](09-test-organization.md)** - Component vs provider test separation

---

**Version:** 2.1.0
**Last Updated:** 2025-10-16
