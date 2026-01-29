[← Previous: TICKET-003](SPRINT-1-TICKET-03-component-models.md) | [Back to Sprint Overview](SPRINT-1-overview.md) | [Back to Index](SPRINT-1-index.md) | [Next: TICKET-005 →](SPRINT-1-TICKET-05-sdk-adapter.md)

---

# TICKET-004: Component Repositories (Data Access Layer)

## Summary
Implement repository pattern in `src/Component/Repository/` for PaymentTransaction and Order data access.

## Priority
**P1 - High**

## Story Points
**5 points** (1.5 days)

## Business Value
Provides clean data access abstraction for transaction and order management.

---

## Description

Create Component repositories:
- PaymentTransactionRepository
- OrderRepository
- Repository interfaces

All in `src/Component/Repository/` as they're provider-agnostic.

---

## Acceptance Criteria

### Must Have
- [ ] PaymentTransactionRepositoryInterface in `src/Component/Contract/`
- [ ] PaymentTransactionRepository in `src/Component/Repository/`
- [ ] OrderRepositoryInterface in `src/Component/Contract/`
- [ ] OrderRepository in `src/Component/Repository/`
- [ ] CRUD operations for PaymentTransaction
- [ ] Query methods (by order ID, provider ID, transaction ID)
- [ ] 100% test coverage with real database

---

## Technical Details

### Repository Interface

```php
<?php
// src/Component/Contract/PaymentTransactionRepositoryInterface.php

namespace Osc\Payment\Component\Contract;

use Osc\Payment\Component\Model\PaymentTransaction;

interface PaymentTransactionRepositoryInterface
{
    public function save(PaymentTransaction $transaction): void;
    public function findById(string $id): ?PaymentTransaction;
    public function findByOrderAndProvider(string $orderId, string $providerOrderId): ?PaymentTransaction;
    public function findAllByOrderId(string $orderId): array;
    public function findByTransactionId(string $transactionId): ?PaymentTransaction;
    public function delete(PaymentTransaction $transaction): void;
}
```

### Repository Implementation

```php
<?php
// src/Component/Repository/PaymentTransactionRepository.php

namespace Osc\Payment\Component\Repository;

use Osc\Payment\Component\Contract\PaymentTransactionRepositoryInterface;
use Osc\Payment\Component\Model\PaymentTransaction;
use OxidEsales\Eshop\Core\Database\Adapter\DatabaseInterface;

class PaymentTransactionRepository implements PaymentTransactionRepositoryInterface
{
    private DatabaseInterface $db;

    public function __construct(DatabaseInterface $db)
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

    private function insert(PaymentTransaction $transaction): void
    {
        $id = $this->generateId();
        $transaction->setId($id);

        $sql = "INSERT INTO oe_payments_transaction
                (OXID, OXSHOPID, OXORDERID, OXPROVIDERORDERID, OXSTATUS,
                 OXPAYMENTMETHODID, OXTRANSACTIONTYPE)
                VALUES (?, ?, ?, ?, ?, ?, ?)";

        $this->db->execute($sql, [
            $id,
            $transaction->getShopId(),
            $transaction->getOrderId(),
            $transaction->getProviderOrderId(),
            $transaction->getStatus(),
            $transaction->getPaymentMethodId(),
            $transaction->getTransactionType(),
        ]);
    }

    private function update(PaymentTransaction $transaction): void
    {
        $sql = "UPDATE oe_payments_transaction
                SET OXSTATUS = ?,
                    OXTRANSACTIONID = ?,
                    OXPROVIDERDATA = ?
                WHERE OXID = ?";

        $this->db->execute($sql, [
            $transaction->getStatus(),
            $transaction->getTransactionId(),
            json_encode($transaction->getProviderData()),
            $transaction->getId(),
        ]);
    }

    public function findByOrderAndProvider(string $orderId, string $providerOrderId): ?PaymentTransaction
    {
        $sql = "SELECT * FROM oe_payments_transaction
                WHERE OXORDERID = ? AND OXPROVIDERORDERID = ?
                LIMIT 1";

        $row = $this->db->getRow($sql, [$orderId, $providerOrderId]);

        return $row ? $this->hydrate($row) : null;
    }

    public function findAllByOrderId(string $orderId): array
    {
        $sql = "SELECT * FROM oe_payments_transaction
                WHERE OXORDERID = ?
                ORDER BY OXTIMESTAMP DESC";

        $rows = $this->db->getAll($sql, [$orderId]);

        return array_map([$this, 'hydrate'], $rows);
    }

    private function hydrate(array $row): PaymentTransaction
    {
        $transaction = new PaymentTransaction(
            $row['OXSHOPID'],
            $row['OXORDERID'],
            $row['OXPROVIDERORDERID'],
            $row['OXSTATUS'],
            $row['OXPAYMENTMETHODID'],
            $row['OXTRANSACTIONTYPE']
        );

        $transaction->setId($row['OXID']);
        if ($row['OXTRANSACTIONID']) {
            $transaction->setTransactionId($row['OXTRANSACTIONID']);
        }
        if ($row['OXPROVIDERDATA']) {
            $transaction->setProviderData(json_decode($row['OXPROVIDERDATA'], true));
        }

        return $transaction;
    }

    private function generateId(): string
    {
        return md5(uniqid((string)mt_rand(), true));
    }
}
```

---

## TDD Workflow

Write integration tests in `tests/Component/Integration/Component/Repository/`:
- PaymentTransactionRepositoryTest (CRUD operations)
- OrderRepositoryTest (order queries)

Use real database (SQLite for tests).

---

## Tasks Breakdown

1. **Repository Interfaces** (1 hour)
   - Define interfaces
   - Document methods

2. **PaymentTransactionRepository** (3 hours)
   - Write integration tests
   - Implement repository
   - Test CRUD operations
   - Test query methods

3. **OrderRepository** (2 hours)
   - Write integration tests
   - Implement repository
   - Test order queries

4. **Performance** (1 hour)
   - Add database indexes
   - Test query performance

---

## Definition of Done

- [ ] Repositories in `src/Component/Repository/`
- [ ] Interfaces in `src/Component/Contract/`
- [ ] 100% integration test coverage
- [ ] All CRUD operations tested
- [ ] Performance tests pass

---


---

[← Previous: TICKET-003](SPRINT-1-TICKET-03-component-models.md) | [Back to Sprint Overview](SPRINT-1-overview.md) | [Back to Index](SPRINT-1-index.md) | [Next: TICKET-005 →](SPRINT-1-TICKET-05-sdk-adapter.md)
