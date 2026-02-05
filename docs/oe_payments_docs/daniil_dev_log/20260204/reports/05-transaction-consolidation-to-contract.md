# Transaction Consolidation to Contract Directory

**Date:** 2026-02-04
**Status:** COMPLETED

---

## Overview

Consolidated `Transaction` class from isolated `Transaction/` directory into `Contract/` directory for better DDD organization. Added `TransactionInterface` for consistency with other domain models.

---

## Problem

The codebase had inconsistent model organization:

| Directory | Contents |
|-----------|----------|
| `Model/` | Only `AbstractModel.php` and `ModelInterface.php` (base abstractions) |
| `Contract/` | `PaymentContract` + 5 related classes (aggregate root + value objects) |
| `Transaction/` | Just `Transaction.php` alone |

**Issues:**
1. `Transaction` isolated in its own directory with just one class
2. `Transaction` is conceptually linked to `Contract` (via `contractId`)
3. No interface for `Transaction` (unlike `PaymentContractInterface`)

---

## Solution

Moved `Transaction` into `Contract/` directory and added interface:

```
Contract/
├── BasketSnapshot.php
├── ContractCondition.php
├── ContractState.php
├── PaymentContract.php
├── PaymentContractInterface.php
├── SecurityValidationResultInterface.php
├── Transaction.php              # NEW - moved from Transaction/
└── TransactionInterface.php     # NEW - added for consistency
```

---

## Changes

### New Files

| File | Description |
|------|-------------|
| `src/Contract/Transaction.php` | Moved and updated namespace |
| `src/Contract/TransactionInterface.php` | New interface for Transaction |

### Deleted

| Path | Reason |
|------|--------|
| `src/Transaction/` | Directory removed (contents moved) |
| `src/Transaction/Transaction.php` | Moved to Contract/ |

### Updated Imports

| File | Change |
|------|--------|
| `src/Repository/TransactionRepositoryInterface.php` | `Transaction` → `Contract\Transaction` |
| `src/Repository/DoctrineTransactionRepository.php` | `Transaction` → `Contract\Transaction` |
| `stripe/src/Stripe/Adapter/OxidShopOrderService.php` | `Transaction` → `Contract\Transaction` |
| `tests/Integration/Repository/DoctrineTransactionRepositoryTest.php` | Updated import + fixed bug using wrong class |
| `tests/Integration/Checkout/FullDataPersistenceFlowTest.php` | Updated import + use `DoctrineTransactionRepository` |

---

## TransactionInterface

```php
namespace OxidEsales\PaymentComponent\Contract;

interface TransactionInterface
{
    public function getId(): string;
    public function getShopId(): int;
    public function getOrderId(): string;
    public function getContractId(): ?string;
    public function getProvider(): string;
    public function getProviderOrderId(): ?string;
    public function setProviderOrderId(?string $providerOrderId): void;
    public function getTransactionId(): ?string;
    public function setTransactionId(?string $transactionId): void;
    public function getType(): string;
    public function getStatus(): string;
    public function setStatus(string $status): void;
    public function getAmount(): float;
    public function getCurrency(): string;
    public function getPaymentMethodId(): ?string;
    public function setPaymentMethodId(?string $paymentMethodId): void;
    public function getPaymentMethodType(): ?string;
    public function setPaymentMethodType(?string $paymentMethodType): void;
    public function getParentTransactionId(): ?string;
    public function setParentTransactionId(?string $parentTransactionId): void;
    public function getCreatedAt(): DateTimeImmutable;
    public function getUpdatedAt(): DateTimeImmutable;
    public function toArray(): array;
}
```

---

## Bug Fixes During Refactoring

### 1. DoctrineTransactionRepositoryTest

**Before:**
```php
$this->repository = new TransactionRepository($this->connection);
```

**After:**
```php
$this->repository = new DoctrineTransactionRepository($this->connection);
```

### 2. FullDataPersistenceFlowTest

**Before:**
```php
use OxidEsales\PaymentComponent\Repository\TransactionRepository;
// ...
private TransactionRepository $transactionRepository;
$this->transactionRepository = new TransactionRepository($this->connection);
```

**After:**
```php
use OxidEsales\PaymentComponent\Repository\DoctrineTransactionRepository;
// ...
private DoctrineTransactionRepository $transactionRepository;
$this->transactionRepository = new DoctrineTransactionRepository($this->connection);
```

---

## Namespace Changes

**Old:** `OxidEsales\PaymentComponent\Transaction\Transaction`
**New:** `OxidEsales\PaymentComponent\Contract\Transaction`

---

## Directory Structure After

```
payment-component/src/
├── Adapter/
├── Composer/
├── Contract/               # Domain models consolidated here
│   ├── BasketSnapshot.php
│   ├── ContractCondition.php
│   ├── ContractState.php
│   ├── PaymentContract.php
│   ├── PaymentContractInterface.php
│   ├── SecurityValidationResultInterface.php
│   ├── Transaction.php         # MOVED
│   └── TransactionInterface.php # NEW
├── EventSystem/
├── Model/                  # Base abstractions only
│   ├── AbstractModel.php
│   └── ModelInterface.php
├── Repository/
├── Service/
└── Webhook/
```

---

## Test Results

```
stripe module:
✓ PHP Code Sniffer passed
✓ PHPUnit tests passed (619 tests)
✓ PHPStan passed
✓ PHPMD passed
Status: COMMITABLE
```

---

## Benefits

1. **DDD Consistency** - Domain models grouped by bounded context
2. **Interface Parity** - `Transaction` now has interface like `PaymentContract`
3. **Discoverability** - All payment-related domain models in one place
4. **Dependency Injection** - `TransactionInterface` enables proper DI/mocking
5. **Fixed Hidden Bugs** - Tests were using non-existent `TransactionRepository` class

---

## Migration Notes

Any code using the old namespace must be updated:

```php
// Old
use OxidEsales\PaymentComponent\Transaction\Transaction;

// New
use OxidEsales\PaymentComponent\Contract\Transaction;
```
