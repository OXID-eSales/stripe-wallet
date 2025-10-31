# Interface Pattern Violations Report

**Date:** 2025-10-31
**Status:** ⚠️ VIOLATIONS FOUND
**Scope:** All classes in `/src/Component/`

---

## 📋 Overview

This report documents violations of the interface pattern principle: **"Always depend on interfaces, not concrete implementations"**.

The codebase follows SOLID principles and uses dependency injection. However, several classes violate the Dependency Inversion Principle by depending on concrete classes instead of their interfaces.

---

## ❌ Violations Found

### Critical Violations (Interfaces Defined but Not Used)

#### 1. AbstractHandler.php
**File:** `src/Component/EventSystem/Handler/AbstractHandler.php`
**Lines:** 12, 13, 50, 51

**Issue:**
```php
use OxidSolutionCatalysts\Payments\Component\Repository\ContractRepository;
use OxidSolutionCatalysts\Payments\Component\EventSystem\EventDispatcher;

public function __construct(
    protected ContractRepository $contractRepository,  // ❌ Concrete class
    protected ?EventDispatcher $eventDispatcher = null  // ❌ Concrete class
) {
}
```

**Should be:**
```php
use OxidSolutionCatalysts\Payments\Component\Repository\ContractRepositoryInterface;
use OxidSolutionCatalysts\Payments\Component\EventSystem\EventDispatcherInterface;

public function __construct(
    protected ContractRepositoryInterface $contractRepository,  // ✅ Interface
    protected ?EventDispatcherInterface $eventDispatcher = null  // ✅ Interface
) {
}
```

**Impact:** HIGH - All handlers extending AbstractHandler inherit this violation

---

#### 2. ContractCreationHandler.php
**File:** `src/Component/EventSystem/Handler/ContractCreationHandler.php`
**Lines:** 9, 10, 15, 16

**Issue:**
```php
use OxidSolutionCatalysts\Payments\Component\EventSystem\EventDispatcher;
use OxidSolutionCatalysts\Payments\Component\Service\ContractService;

public function __construct(
    private ContractService $contractService,      // ❌ No interface exists
    private EventDispatcher $eventDispatcher        // ❌ Concrete class
) {
}
```

**Should be:**
```php
use OxidSolutionCatalysts\Payments\Component\EventSystem\EventDispatcherInterface;
use OxidSolutionCatalysts\Payments\Component\Service\ContractServiceInterface;  // Need to create

public function __construct(
    private ContractServiceInterface $contractService,  // ✅ Interface
    private EventDispatcherInterface $eventDispatcher   // ✅ Interface
) {
}
```

**Impact:** HIGH - Direct dependency on concrete implementations

---

#### 3. OrderCreationHandler.php
**File:** `src/Component/EventSystem/Handler/OrderCreationHandler.php`
**Lines:** 9, 10, 28-30

**Issue:**
```php
use OxidSolutionCatalysts\Payments\Component\EventSystem\EventDispatcher;
use OxidSolutionCatalysts\Payments\Component\Repository\ContractRepository;

public function __construct(
    ContractRepository $contractRepository,             // ❌ Concrete class
    private InMemoryOrderRepository $orderRepository,   // ❌ Test class (has TODO)
    ?EventDispatcher $eventDispatcher = null            // ❌ Concrete class
) {
    parent::__construct($contractRepository, $eventDispatcher);
}
```

**Should be:**
```php
use OxidSolutionCatalysts\Payments\Component\EventSystem\EventDispatcherInterface;
use OxidSolutionCatalysts\Payments\Component\Repository\ContractRepositoryInterface;
use OxidSolutionCatalysts\Payments\Component\Repository\OrderRepositoryInterface;  // Need to create

public function __construct(
    ContractRepositoryInterface $contractRepository,    // ✅ Interface
    private OrderRepositoryInterface $orderRepository,   // ✅ Interface
    ?EventDispatcherInterface $eventDispatcher = null   // ✅ Interface
) {
    parent::__construct($contractRepository, $eventDispatcher);
}
```

**Impact:** HIGH - Uses test class in production code

---

#### 4. ContractFulfillmentHandler.php
**File:** `src/Component/EventSystem/Handler/ContractFulfillmentHandler.php`
**Lines:** 9, 10, 31-33

**Issue:**
```php
use OxidSolutionCatalysts\Payments\Component\EventSystem\EventDispatcher;
use OxidSolutionCatalysts\Payments\Component/Repository\ContractRepository;

public function __construct(
    ContractRepository $contractRepository,             // ❌ Concrete class
    private InMemoryOrderRepository $orderRepository,   // ❌ Test class (has TODO)
    ?EventDispatcher $eventDispatcher = null            // ❌ Concrete class
) {
    parent::__construct($contractRepository, $eventDispatcher);
}
```

**Should be:**
```php
use OxidSolutionCatalysts\Payments\Component\EventSystem\EventDispatcherInterface;
use OxidSolutionCatalysts\Payments\Component\Repository\ContractRepositoryInterface;
use OxidSolutionCatalysts\Payments\Component\Repository\OrderRepositoryInterface;  // Need to create

public function __construct(
    ContractRepositoryInterface $contractRepository,    // ✅ Interface
    private OrderRepositoryInterface $orderRepository,   // ✅ Interface
    ?EventDispatcherInterface $eventDispatcher = null   // ✅ Interface
) {
    parent::__construct($contractRepository, $eventDispatcher);
}
```

**Impact:** HIGH - Uses test class in production code

---

#### 5. ContractService.php
**File:** `src/Component/Service/ContractService.php`
**Lines:** 10, 14, 16

**Issue:**
```php
use OxidSolutionCatalysts\Payments\Component\Repository\ContractRepository;

class ContractService
{
    private ContractRepository $contractRepository;  // ❌ Concrete class

    public function __construct(ContractRepository $contractRepository)  // ❌ Concrete class
    {
        $this->contractRepository = $contractRepository;
    }
}
```

**Should be:**
```php
use OxidSolutionCatalysts\Payments\Component\Repository\ContractRepositoryInterface;

class ContractService implements ContractServiceInterface  // Need to create interface
{
    private ContractRepositoryInterface $contractRepository;  // ✅ Interface

    public function __construct(ContractRepositoryInterface $contractRepository)  // ✅ Interface
    {
        $this->contractRepository = $contractRepository;
    }
}
```

**Impact:** HIGH - Service layer violates DIP

---

#### 6. ContractEventInterface.php
**File:** `src/Component/EventSystem/Event/Contract/ContractEventInterface.php`
**Line:** Return type in interface definition

**Issue:**
```php
interface ContractEventInterface extends EventInterface
{
    public function getContext(): EventContext;  // ❌ Concrete class in interface!
}
```

**Should be:**
```php
interface ContractEventInterface extends EventInterface
{
    public function getContext(): EventContextInterface;  // ✅ Interface
}
```

**Impact:** CRITICAL - Interface depends on concrete class, violates interface segregation

---

#### 7. All Contract Event Classes (16 files)
**Files:** All event classes in `src/Component/EventSystem/Event/Contract/`

**Issue:** Constructor and return types use concrete `EventContext`

**Example (ContractCreatedEvent.php):**
```php
readonly class ContractCreatedEvent implements ContractCreatedEventInterface
{
    public function __construct(
        private PaymentContractInterface $contract,
        private EventContext $context  // ❌ Concrete class
    ) {
    }

    public function getContext(): EventContext  // ❌ Concrete class
    {
        return $this->context;
    }
}
```

**Should be:**
```php
readonly class ContractCreatedEvent implements ContractCreatedEventInterface
{
    public function __construct(
        private PaymentContractInterface $contract,
        private EventContextInterface $context  // ✅ Interface
    ) {
    }

    public function getContext(): EventContextInterface  // ✅ Interface
    {
        return $this->context;
    }
}
```

**Affected Files:**
- `ContractCreatedEvent.php`
- `ContractTransitionedToPendingEvent.php`
- `ContractReadyToCommitEvent.php`
- `ContractCommittedEvent.php`
- `ContractFulfilledEvent.php`
- `ContractCancelledEvent.php`
- `ContractExpiredEvent.php`
- `ContractFailedEvent.php`
- `ContractConditionFulfilledEvent.php`

**Impact:** HIGH - 9 event classes violate interface pattern

---

#### 8. Payment Event Classes (7 files)
**Files:** Some event classes in `src/Component/EventSystem/Event/Payment/`

**Issue:** Constructor and return types use concrete `EventContext`

**Example (PaymentInitiatedEvent.php):**
```php
class PaymentInitiatedEvent implements PaymentInitiatedEventInterface
{
    public function __construct(
        private readonly EventContext $context,  // ❌ Concrete class
        // ... other params
    ) {
    }

    public function getContext(): EventContext  // ❌ Concrete class
    {
        return $this->context;
    }
}
```

**Should be:**
```php
class PaymentInitiatedEvent implements PaymentInitiatedEventInterface
{
    public function __construct(
        private readonly EventContextInterface $context,  // ✅ Interface
        // ... other params
    ) {
    }

    public function getContext(): EventContextInterface  // ✅ Interface
    {
        return $this->context;
    }
}
```

**Affected Files:**
- `PaymentInitiatedEvent.php`
- `PaymentAuthorizedEvent.php`
- `PaymentCapturedEvent.php`
- `PaymentFailedEvent.php`
- `PaymentRefundedEvent.php`
- `OrderCreatedEvent.php`
- `OrderCompletedEvent.php`

**Impact:** HIGH - 7 event classes violate interface pattern

---

## ✅ Classes Following Interface Pattern Correctly

### Good Examples

#### WebhookProcessor.php
```php
public function __construct(
    private readonly ContractRepositoryInterface $contractRepository,      // ✅
    private readonly EventDispatcherInterface $eventDispatcher,            // ✅
    private readonly WebhookIdempotencyCheckerInterface $idempotencyChecker, // ✅
    private readonly WebhookLogRepositoryInterface $logRepository,          // ✅
    private readonly LoggerInterface $logger                                // ✅
) {
}
```

#### WebhookController.php
```php
public function __construct(
    private readonly WebhookSignatureVerifierInterface $signatureVerifier,  // ✅
    private readonly WebhookProcessorInterface $processor,                  // ✅
    private readonly LoggerInterface $logger                                // ✅
) {
}
```

#### WebhookIdempotencyChecker.php
```php
public function __construct(
    private readonly WebhookLogRepositoryInterface $logRepository  // ✅
) {
}
```

---

## 📊 Summary Statistics

| Category | Count |
|----------|-------|
| **Total Violations** | **25 files** |
| Handler violations | 4 files |
| Service violations | 1 file |
| Interface violations | 1 file (CRITICAL) |
| Event class violations | 16 files (Contract events) + 7 files (Payment events) |
| **Correctly implemented** | ~75 files |
| **Compliance rate** | ~75% |

---

## 🎯 Required Fixes

### Priority 1: Critical (Fix First)

1. **ContractEventInterface** - Fix interface to use `EventContextInterface`
2. **AbstractHandler** - Use `ContractRepositoryInterface` and `EventDispatcherInterface`

### Priority 2: High (Handler Layer)

3. **ContractCreationHandler** - Use interfaces for dependencies
4. **OrderCreationHandler** - Use interfaces, create `OrderRepositoryInterface`
5. **ContractFulfillmentHandler** - Use interfaces

### Priority 3: High (Service Layer)

6. **ContractService** - Use `ContractRepositoryInterface`, create `ContractServiceInterface`

### Priority 4: Medium (Event Layer)

7. **All Contract Event classes** (9 files) - Use `EventContextInterface`
8. **All Payment Event classes** (7 files) - Use `EventContextInterface`

---

## 🏗️ Missing Interfaces to Create

### 1. ContractServiceInterface
**Location:** `src/Component/Service/ContractServiceInterface.php`

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Component\Service;

use OxidSolutionCatalysts\Payments\Component\Contract\PaymentContractInterface;

interface ContractServiceInterface extends ServiceInterface
{
    public function createContract(
        string $userId,
        object $basket,
        array $conditionTypes = []
    ): PaymentContractInterface;

    public function findActiveContractByUser(string $userId): ?PaymentContractInterface;

    public function cleanupExpiredContracts(): int;
}
```

### 2. OrderRepositoryInterface
**Location:** `src/Component/Repository/OrderRepositoryInterface.php`

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Component\Repository;

interface OrderRepositoryInterface
{
    public function save(object $order): void;

    public function findById(int $id): ?object;

    public function generateNextId(): int;

    public function generateNextOrderNumber(): string;
}
```

---

## 🔍 Impact Analysis

### Benefits of Following Interface Pattern

1. **Testability** ✅
   - Easy to mock dependencies in unit tests
   - No need for concrete implementations in tests

2. **Flexibility** ✅
   - Can swap implementations without changing dependents
   - Easy to add new implementations

3. **SOLID Compliance** ✅
   - Dependency Inversion Principle (DIP)
   - Open/Closed Principle (OCP)

4. **Loose Coupling** ✅
   - Reduces dependencies between modules
   - Changes to implementations don't affect consumers

### Risks of Current Violations

1. **Hard to Test** ❌
   - Difficult to mock concrete classes
   - Tests depend on concrete implementations

2. **Tight Coupling** ❌
   - Changes to concrete classes affect all dependents
   - Difficult to refactor

3. **SOLID Violations** ❌
   - Violates Dependency Inversion Principle
   - Makes code less maintainable

---

## 📋 Recommended Fix Order

1. **Create missing interfaces** (ContractServiceInterface, OrderRepositoryInterface)
2. **Fix ContractEventInterface** (CRITICAL - affects all contract events)
3. **Fix AbstractHandler** (affects all child handlers)
4. **Fix individual handlers** (ContractCreationHandler, OrderCreationHandler, ContractFulfillmentHandler)
5. **Fix ContractService**
6. **Fix all Event classes** (16 Contract + 7 Payment events)
7. **Run full test suite** to verify no regressions
8. **Update DI container configuration** to inject interfaces

---

## ✅ Definition of Done

- [ ] All classes use interfaces for dependencies
- [ ] No concrete class type hints in constructors (except value objects)
- [ ] No concrete class type hints in method parameters
- [ ] No concrete class return types (use interfaces)
- [ ] All tests passing
- [ ] PHPStan Level 6 passing
- [ ] PHPCS passing
- [ ] Documentation updated

---

**Status:** ⚠️ VIOLATIONS FOUND - NEEDS FIXING
**Priority:** HIGH
**Estimated Effort:** 4-6 hours

*Version: 1.0*
*Last Updated: 2025-10-31*
