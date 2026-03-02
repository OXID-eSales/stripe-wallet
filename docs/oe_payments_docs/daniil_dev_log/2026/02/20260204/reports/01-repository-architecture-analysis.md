# Sprint 32: Repository Architecture Analysis

**Date:** 2026-02-04
**Status:** COMPLETED

---

## Overview

Examination of the Repository layer in `payment-component/src/Repository/` to understand:
1. Why there are two ContractRepository classes
2. Why some repositories have "Doctrine" prefix
3. Code quality compliance (Clean Code, PSR-12, SOLID, DRY)

---

## Repository Inventory

| File | Purpose | Implementation |
|------|---------|----------------|
| `ContractRepositoryInterface.php` | Contract for contract persistence | Interface |
| `ContractRepository.php` | In-memory contract storage | Test/Fallback |
| `DoctrineContractRepository.php` | Database contract persistence | Production |
| `TransactionRepositoryInterface.php` | Contract for transaction persistence | Interface |
| `DoctrineTransactionRepository.php` | Database transaction persistence | Production |
| `WebhookLogRepositoryInterface.php` | Contract for webhook log persistence | Interface |
| `WebhookLogRepository.php` | In-memory webhook log storage | Test/Fallback |
| `DoctrineWebhookLogRepository.php` | Database webhook log persistence | Production |

---

## Why Two ContractRepository Classes?

The codebase follows the **Repository Pattern** with **Dependency Inversion Principle (DIP)**.

### The Pattern

```
                    ┌─────────────────────────────────────┐
                    │   ContractRepositoryInterface       │
                    │   (defines the contract)            │
                    └──────────────┬──────────────────────┘
                                   │
              ┌────────────────────┴────────────────────┐
              │                                         │
┌─────────────▼─────────────┐          ┌───────────────▼───────────────┐
│   ContractRepository       │          │   DoctrineContractRepository  │
│   (in-memory storage)      │          │   (Doctrine DBAL storage)     │
│   - Testing                │          │   - Production                │
│   - Fast unit tests        │          │   - Real database             │
│   - No DB dependencies     │          │   - MySQL/MariaDB             │
└────────────────────────────┘          └───────────────────────────────┘
```

### Rationale

1. **`ContractRepository.php`** (In-Memory)
   - Uses `private array $storage = []` for data
   - No external dependencies
   - Perfect for unit tests (fast, isolated)
   - Can be used as fallback when DB unavailable

2. **`DoctrineContractRepository.php`** (Database)
   - Uses `Doctrine\DBAL\Connection` for DB operations
   - Real SQL queries against `oe_payments_contract` table
   - Production implementation
   - Handles hydration/dehydration of domain objects

### Benefits of This Approach

| Benefit | Description |
|---------|-------------|
| **Testability** | Unit tests use in-memory repos - no DB setup needed |
| **Flexibility** | Can swap implementations via DI configuration |
| **SOLID Compliance** | Depends on abstractions (interface), not concretions |
| **Performance** | In-memory for tests = fast test suite |
| **Separation** | Business logic isolated from persistence details |

---

## Why "Doctrine" Prefix?

The "Doctrine" prefix clearly indicates:

1. **Implementation Technology** - Uses Doctrine DBAL (Database Abstraction Layer)
2. **Distinguishes from Alternatives** - Separates from in-memory or other implementations
3. **Convention** - Common pattern in PHP ecosystem (Laravel has `Eloquent*`, Symfony has `Doctrine*`)

### Naming Convention

| Prefix | Meaning |
|--------|---------|
| No prefix | Interface OR simple/in-memory implementation |
| `Doctrine*` | Uses Doctrine DBAL for database operations |

---

## Code Quality Analysis

### SOLID Compliance

| Principle | Status | Evidence |
|-----------|--------|----------|
| **S**ingle Responsibility | PASS | Each repository handles only one entity type |
| **O**pen/Closed | PASS | New implementations can be added without modifying interfaces |
| **L**iskov Substitution | PASS | Both implementations fulfill interface contracts |
| **I**nterface Segregation | PASS | Interfaces are focused, no "fat" interfaces |
| **D**ependency Inversion | PASS | Services depend on interfaces, not concrete classes |

### PSR-12 Compliance

| Check | Status | Notes |
|-------|--------|-------|
| `declare(strict_types=1)` | PASS | All files have strict types |
| Namespace conventions | PASS | Proper PSR-4 autoloading |
| Method visibility | PASS | Explicit visibility on all methods |
| Braces placement | PASS | K&R style (opening brace same line) |
| Indentation | PASS | 4 spaces consistent |
| Use statements | PASS | All imports at top, no inline `\Exception` |

### Clean Code Compliance

| Criterion | Status | Notes |
|-----------|--------|-------|
| Meaningful names | PASS | `hydrateContract`, `prepareContractData` clearly describe intent |
| Small methods | PARTIAL | `DoctrineContractRepository` has some longer methods (see below) |
| No else expressions | PASS | Early returns used consistently |
| DRY | PASS | Common patterns extracted to helper methods |
| PHPDoc types | PASS | All array types documented with `@return` and `@param` |

### Method Length Analysis

**DoctrineContractRepository.php:**

| Method | Lines | Status |
|--------|-------|--------|
| `save()` | 7 | PASS |
| `findById()` | 14 | PASS |
| `findByProviderOrderId()` | 14 | PASS |
| `findByUserId()` | 10 | PASS |
| `findActiveByUserId()` | 19 | PASS |
| `findExpired()` | 20 | PASS |
| `saveContract()` | 14 | PASS |
| `prepareContractData()` | 30 | BORDERLINE (target 15-25) |
| `hydrateContract()` | 15 | PASS |
| `setContractPrivateProperties()` | 25 | PASS |

**Observation:** `prepareContractData()` at 30 lines is slightly over the 15-25 line target but acceptable given it's a mapping function with many fields.

**DoctrineTransactionRepository.php:**

| Method | Lines | Status |
|--------|-------|--------|
| `save()` | 10 | PASS |
| `findById()` | 11 | PASS |
| `findByOrderId()` | 7 | PASS |
| `findByContractId()` | 7 | PASS |
| `findByProviderTransactionId()` | 11 | PASS |
| `findByTypeAndStatus()` | 11 | PASS |
| `findChildTransactions()` | 7 | PASS |
| `exists()` | 6 | PASS |
| `getTotalRefundedForContract()` | 11 | PASS |
| `logRefund()` | 30 | BORDERLINE - creates Transaction inline |
| `prepareTransactionData()` | 20 | PASS |
| `hydrateTransaction()` | 35 | SLIGHTLY LONG - many field mappings |

**DoctrineWebhookLogRepository.php:**

| Method | Lines | Status |
|--------|-------|--------|
| `save()` | 27 | BORDERLINE |
| `existsByEventId()` | 11 | PASS |
| `findByEventId()` | 14 | PASS |
| `hydrateWebhookLog()` | 12 | PASS |
| `setOptionalWebhookProperties()` | 29 | BORDERLINE |
| `extractString()` | 8 | PASS |
| `updateStatus()` | 21 | PASS |

---

## Issues Found

### 1. PHPMD Suppression in DoctrineContractRepository

```php
/**
 * @SuppressWarnings(PHPMD)
 */
class DoctrineContractRepository implements ContractRepositoryInterface
```

**Analysis:** The class-level PHPMD suppression is overly broad. It's used because of the complexity of hydrating domain objects with many fields. This is acceptable for repository classes that deal with database mapping.

### 2. PHPMD Suppression in DoctrineWebhookLogRepository

Same pattern - acceptable for repository data mapping.

### 3. PHPStan Ignore Comments

Multiple `@phpstan-ignore-next-line` comments exist for database type casts:

```php
/** @phpstan-ignore-next-line */
$shopId = is_int($data['OXSHOPID']) ? $data['OXSHOPID'] : (int) ($data['OXSHOPID'] ?? 0);
```

**Analysis:** These are necessary because database results come as `mixed` types and need safe casting. This is proper defensive programming.

### 4. Reflection Usage in DoctrineContractRepository

```php
private function setPrivateProperty(object $object, string $propertyName, mixed $value): void
{
    $reflection = new ReflectionClass($object);
    $property = $reflection->getProperty($propertyName);
    $property->setAccessible(true);
    $property->setValue($object, $value);
}
```

**Analysis:** Reflection is used to hydrate domain objects because `PaymentContract` has private properties without setters (immutable design). This is a common pattern in repository implementations but could be improved by:
- Adding internal setter methods to `PaymentContract`
- Using a static `fromDatabaseArray()` factory method

**Verdict:** Acceptable but could be refactored in future.

---

## Missing In-Memory Repository

**Observation:** `TransactionRepository` (in-memory) does NOT exist. Only `DoctrineTransactionRepository`.

| Entity | In-Memory | Doctrine |
|--------|-----------|----------|
| Contract | `ContractRepository` | `DoctrineContractRepository` |
| Transaction | **MISSING** | `DoctrineTransactionRepository` |
| WebhookLog | `WebhookLogRepository` | `DoctrineWebhookLogRepository` |

**Recommendation:** Create `TransactionRepository.php` (in-memory) for testing consistency. Currently, tests that need Transaction repository must mock `DoctrineTransactionRepository` or skip transaction testing.

---

## Summary

### Why Two ContractRepositories?

**Answer:** This follows the **Repository Pattern with Dependency Inversion**:
- `ContractRepositoryInterface` - defines the contract
- `ContractRepository` - in-memory implementation for testing
- `DoctrineContractRepository` - production database implementation

This allows:
- Fast unit tests without database
- Swappable implementations via DI
- SOLID-compliant architecture

### Why "Doctrine" Prefix?

**Answer:** The prefix indicates the implementation uses **Doctrine DBAL** for database operations. It distinguishes production implementations from test/fallback alternatives.

### Code Quality Status

| Category | Status |
|----------|--------|
| PSR-12 | **PASS** |
| SOLID | **PASS** |
| Clean Code | **PASS** (with minor observations) |
| DRY | **PASS** |

**Minor Issues:**
- Some methods slightly over 25 lines (acceptable for mapping functions)
- Missing in-memory `TransactionRepository` for test consistency
- Reflection usage could be refactored (future improvement)

---

## Recommendations

1. **Consider creating `TransactionRepository.php`** (in-memory) for test consistency
2. **Consider refactoring reflection usage** in `DoctrineContractRepository` to use factory method
3. **Keep PHPMD suppressions** as they are appropriate for repository data mapping complexity

---

## File References

```
payment-component/src/Repository/
├── ContractRepositoryInterface.php          (interface)
├── ContractRepository.php                   (in-memory test/fallback)
├── DoctrineContractRepository.php           (production Doctrine DBAL)
├── TransactionRepositoryInterface.php       (interface)
├── DoctrineTransactionRepository.php        (production Doctrine DBAL)
├── WebhookLogRepositoryInterface.php        (interface)
├── WebhookLogRepository.php                 (in-memory test/fallback)
└── DoctrineWebhookLogRepository.php         (production Doctrine DBAL)
```
