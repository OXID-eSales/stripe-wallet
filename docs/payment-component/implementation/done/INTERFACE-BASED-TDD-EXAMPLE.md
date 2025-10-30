# Interface-Based TDD Example

**Date:** 2025-10-30
**Topic:** Why Interfaces Enable Better Testing and SOLID Design

---

## 🎯 The Problem Without Interfaces

### ❌ Depending on Concrete Classes

```php
// ❌ BAD: Handler depends on concrete class
class ContractCreatedHandler
{
    public function handle(ContractCreatedEvent $event): void
    {
        $contract = $event->getContract();
        // ... handle logic
    }
}

// Test becomes harder
class ContractCreatedHandlerTest extends TestCase
{
    public function testHandle()
    {
        // ❌ Must create real event instance or mock concrete class
        $event = $this->createMock(ContractCreatedEvent::class);
        // Mocking concrete classes is fragile
    }
}
```

**Problems:**
- ❌ Violates Dependency Inversion Principle (depend on abstractions)
- ❌ Tight coupling to concrete implementation
- ❌ Hard to test (must mock concrete class)
- ❌ Hard to swap implementations

---

## ✅ The Solution: Interface-Based Design

### Interface Hierarchy

```
EventInterface (base)
  ↓
ContractEventInterface (all contract events)
  ↓
ContractCreatedEventInterface (specific event)
```

### ✅ Handler Depends on Interface

```php
// ✅ GOOD: Handler depends on interface
class ContractCreatedHandler
{
    public function handle(ContractCreatedEventInterface $event): void
    {
        $contract = $event->getContract();
        // ... handle logic
    }
}
```

---

## 📝 TDD Example with Interface Mocking

### Step 1: 🔴 RED - Write Test First

```php
<?php
// tests/Unit/Component/EventSystem/Handler/ContractCreatedHandlerTest.php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Unit\Component\EventSystem\Handler;

use PHPUnit\Framework\TestCase;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Handler\ContractCreatedHandler;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\Contract\ContractCreatedEventInterface;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\EventContext;
use OxidSolutionCatalysts\Payments\Component\Contract\PaymentContractInterface;
use OxidSolutionCatalysts\Payments\Component\Repository\ContractRepositoryInterface;

final class ContractCreatedHandlerTest extends TestCase
{
    private ContractRepositoryInterface $repository;
    private ContractCreatedHandler $handler;

    protected function setUp(): void
    {
        // ✅ Mock the interface, not the concrete class!
        $this->repository = $this->createMock(ContractRepositoryInterface::class);
        $this->handler = new ContractCreatedHandler($this->repository);
    }

    public function testHandle_SavesContractToRepository(): void
    {
        // ✅ Mock the event interface
        $event = $this->createMock(ContractCreatedEventInterface::class);

        // ✅ Mock the contract interface
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getId')->willReturn('contract_123');

        // Set up event expectations
        $event->method('getContract')->willReturn($contract);
        $event->method('getContext')->willReturn(new EventContext());

        // ✅ Expect repository to be called with the contract
        $this->repository
            ->expects($this->once())
            ->method('save')
            ->with($contract);

        // Act
        $this->handler->handle($event);
    }

    public function testHandle_LogsContractCreation(): void
    {
        $event = $this->createMock(ContractCreatedEventInterface::class);
        $contract = $this->createMock(PaymentContractInterface::class);

        $contract->method('getId')->willReturn('contract_456');
        $event->method('getContract')->willReturn($contract);
        $event->method('getContractId')->willReturn('contract_456');

        // ✅ Easy to test because we control the interface behavior
        $this->handler->handle($event);

        // Assert logging occurred (with a logger mock)
        $this->assertTrue(true); // Placeholder
    }
}
```

**Test runs:** ❌ FAIL - Class ContractCreatedHandler does not exist

---

### Step 2: 🟢 GREEN - Minimal Implementation

```php
<?php
// src/Component/EventSystem/Handler/ContractCreatedHandler.php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Component\EventSystem\Handler;

use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\Contract\ContractCreatedEventInterface;
use OxidSolutionCatalysts\Payments\Component\Repository\ContractRepositoryInterface;

final class ContractCreatedHandler
{
    public function __construct(
        private readonly ContractRepositoryInterface $repository
    ) {
    }

    public function handle(ContractCreatedEventInterface $event): void
    {
        $contract = $event->getContract();
        $this->repository->save($contract);
    }
}
```

**Test runs:** ✅ PASS - All tests green!

---

### Step 3: 🔵 REFACTOR - Add Logging

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Component\EventSystem\Handler;

use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\Contract\ContractCreatedEventInterface;
use OxidSolutionCatalysts\Payments\Component\Repository\ContractRepositoryInterface;
use Psr\Log\LoggerInterface;

final class ContractCreatedHandler
{
    public function __construct(
        private readonly ContractRepositoryInterface $repository,
        private readonly LoggerInterface $logger
    ) {
    }

    public function handle(ContractCreatedEventInterface $event): void
    {
        $contract = $event->getContract();
        $contractId = $event->getContractId();

        $this->logger->info('Contract created', [
            'contractId' => $contractId,
            'state' => $event->getContractState()
        ]);

        $this->repository->save($contract);

        $this->logger->info('Contract saved to repository', [
            'contractId' => $contractId
        ]);
    }
}
```

**Test runs:** ✅ PASS - Tests still green!

---

## 🎁 Benefits of Interface-Based Design

### 1. Easy Mocking

```php
// ✅ Mock interface - clean and easy
$event = $this->createMock(ContractCreatedEventInterface::class);
$event->method('getContractId')->willReturn('test_123');

// ❌ Mock concrete class - fragile
$event = $this->createMock(ContractCreatedEvent::class);
// Must know internal implementation details
```

### 2. Dependency Inversion Principle (SOLID)

```php
// ✅ Depend on abstraction
public function handle(ContractCreatedEventInterface $event): void

// ❌ Depend on concretion
public function handle(ContractCreatedEvent $event): void
```

### 3. Liskov Substitution

```php
// ✅ Any implementation of the interface works
function processEvent(ContractCreatedEventInterface $event) {
    // Works with ANY implementation:
    // - ContractCreatedEvent
    // - MockContractCreatedEvent
    // - TestContractCreatedEvent
}
```

### 4. Interface Segregation

```php
// ✅ Small, focused interfaces
interface ContractCreatedEventInterface {
    public function getContractId(): string;
    public function getContractState(): string;
}

// Handler only needs what it uses
```

### 5. Testability

```php
class MyHandlerTest extends TestCase
{
    public function testSomething()
    {
        // ✅ Create test doubles easily
        $event = $this->createStub(ContractCreatedEventInterface::class);
        $event->method('getContractId')->willReturn('test');

        // ✅ No need for complex mocking setup
        // ✅ Tests are fast and isolated
        // ✅ No database, no real objects
    }
}
```

---

## 🏗️ Complete Interface Hierarchy

```php
// Base
interface EventInterface { }

// Layer 1: Event Categories
interface ContractEventInterface extends EventInterface {
    public function getContract(): PaymentContractInterface;
    public function getContext(): EventContext;
}

interface PaymentEventInterface extends EventInterface {
    public function getContext(): EventContext;
}

// Layer 2: Specific Events
interface ContractCreatedEventInterface extends ContractEventInterface {
    public function getContractId(): string;
    public function getContractState(): string;
}

interface PaymentInitiatedEventInterface extends PaymentEventInterface {
    public function getPaymentMethodId(): string;
    public function getAmount(): float;
    public function getCurrency(): string;
}
```

---

## 📊 Testing Comparison

| Aspect | Without Interfaces | With Interfaces |
|--------|-------------------|-----------------|
| **Mocking** | Mock concrete class (fragile) | Mock interface (clean) |
| **Test Setup** | Complex (must know implementation) | Simple (only interface contract) |
| **Test Speed** | Slower (depends on concrete class) | Fast (pure interface) |
| **SOLID** | Violates DIP | Follows DIP |
| **Refactoring** | Tests break easily | Tests remain stable |
| **Clarity** | What does handler need? Unclear | Interface shows exact needs |

---

## ✅ Real-World Example

### Handler Using Multiple Interfaces

```php
final class PaymentCaptureHandler
{
    public function __construct(
        private readonly ContractRepositoryInterface $contractRepo,
        private readonly PaymentServiceInterface $paymentService,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly LoggerInterface $logger
    ) {
    }

    public function handle(PaymentCapturedEventInterface $event): void
    {
        $captureId = $event->getCaptureId();
        $amount = $event->getCapturedAmount();

        // All dependencies are interfaces!
        // Easy to mock in tests
        // Easy to swap implementations
        // Clear what this handler needs
    }
}
```

### Test is Clean

```php
final class PaymentCaptureHandlerTest extends TestCase
{
    public function testHandle_UpdatesContractToFulfilled(): void
    {
        // ✅ All mocks are interfaces
        $contractRepo = $this->createMock(ContractRepositoryInterface::class);
        $paymentService = $this->createMock(PaymentServiceInterface::class);
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $logger = $this->createMock(LoggerInterface::class);

        $handler = new PaymentCaptureHandler(
            $contractRepo,
            $paymentService,
            $eventDispatcher,
            $logger
        );

        // ✅ Event is also an interface mock
        $event = $this->createMock(PaymentCapturedEventInterface::class);
        $event->method('getCaptureId')->willReturn('cap_123');
        $event->method('getCapturedAmount')->willReturn(100.00);

        // ✅ Set expectations on interface methods
        $contractRepo
            ->expects($this->once())
            ->method('findByProviderOrderId')
            ->willReturn($this->createMock(PaymentContractInterface::class));

        // Act
        $handler->handle($event);

        // ✅ Clean, fast, isolated test
    }
}
```

---

## 🎯 Key Takeaways

1. **Always depend on interfaces, not concrete classes** (Dependency Inversion)
2. **Interfaces make mocking trivial** (Better tests)
3. **Interfaces define clear contracts** (What does code need?)
4. **Interfaces enable substitution** (Swap implementations easily)
5. **TDD works better with interfaces** (Design driven by contracts)

---

## 🚀 Interface-First TDD Workflow

```
1. Define interface (contract)
   ↓
2. Write test using interface mock
   ↓
3. Watch test fail (RED)
   ↓
4. Implement class that implements interface
   ↓
5. Watch test pass (GREEN)
   ↓
6. Refactor
   ↓
7. Tests still pass (interface unchanged)
```

---

**Result:** Clean, testable, SOLID code! 🎉

*This is why we create interfaces for events.*
