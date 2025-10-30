[← Previous: TICKET-001](SPRINT-1-TICKET-01-project-setup.md) | [Back to Sprint Overview](SPRINT-1-overview.md) | [Back to Index](SPRINT-1-index.md) | [Next: TICKET-003 →](SPRINT-1-TICKET-03-component-models.md)

---

# TICKET-002: Component Event Layer (Domain Events + Context + Contract Events)

## Summary
Implement the reusable event layer in `src/Component/Event/` with domain events, EventContext, event dispatcher, and **contract-aware events** that support the smart-contract pattern architecture (v4.0).

## Priority
**P0 - Critical**

## Story Points
**8 points** (2 days)

## Business Value
Establishes the event-driven foundation that enables loose coupling between Component and provider layers, with explicit support for contract lifecycle management (DRAFT → PENDING → COMMITTED → FULFILLED).

## Architecture Reference
- **[00-overview.md](00-overview.md)** - Smart-contract pattern introduction (v4.0)
- **[01-architecture-layers.md](01-architecture-layers.md)** - Contract-aware event-driven architecture

---

## Description

Create the Component event layer with **contract-aware architecture**:
- EventContext for request data caching (enhanced with contract reference)
- **Contract lifecycle events** (9 events) - NEW in v4.0
- **Payment lifecycle events** (8 events) - Traditional payment events
- Event contracts/interfaces
- PSR-14 event dispatcher wrapper

All code goes in `src/Component/Event/` as it's provider-agnostic.

**Key Innovation:** Event layer supports the smart-contract pattern where contracts capture purchase intent BEFORE order creation, enabling clean separation between payment domain and order domain.

---

## Acceptance Criteria

### Must Have
- [ ] EventContext class in `src/Component/Event/` (enhanced with contract reference)
- [ ] **9 contract lifecycle events** in `src/Component/Event/Contract/` - NEW
- [ ] **8 payment lifecycle events** in `src/Component/Event/Domain/`
- [ ] EventDispatcher in `src/Component/Event/`
- [ ] EventDispatcherInterface in `src/Component/Contract/`
- [ ] All events immutable with validation
- [ ] 100% test coverage
- [ ] All events properly namespaced under Component
- [ ] Contract events support full lifecycle (DRAFT → FULFILLED)

### Should Have
- [ ] Event factory helpers
- [ ] Event serialization support
- [ ] Stoppable event support (PSR-14)

---

## TDD Approach: RED → GREEN → REFACTOR

**CRITICAL:** This ticket follows strict Test-Driven Development. We write **tests FIRST**, watch them fail (RED), then write **minimal code** to pass tests (GREEN), then refactor.

### TDD Workflow

```
1. 🔴 RED:     Write a failing test
2. 🟢 GREEN:   Write minimal code to pass the test
3. 🔵 REFACTOR: Improve code while keeping tests green
4. 🔁 REPEAT:   Next feature
```

**Rules:**
- ❌ NO production code without a failing test first
- ✅ Write the simplest test that could possibly fail
- ✅ Write the simplest code that could possibly pass
- ✅ Run tests after EVERY change
- ✅ Commit after each GREEN phase

---

## Phase 1: EventContext (TDD Cycle)

### Step 1: Write Tests FIRST 🔴

```php
<?php
// tests/Unit/Component/EventSystem/Event/EventContextTest.php

namespace OxidSolutionCatalysts\Payments\Tests\Unit\Component\EventSystem\Event;

use PHPUnit\Framework\TestCase;
use OxidSolutionCatalysts\Payments\Component\Event\EventContext;

class EventContextTest extends TestCase
{
    // Test 1: Basic data storage
    public function testSet_StoresValue()
    {
        $context = new EventContext();

        $context->set('userId', '123');

        $this->assertEquals('123', $context->get('userId'));
    }

    // Test 2: Default values
    public function testGet_ReturnsDefaultWhenKeyNotSet()
    {
        $context = new EventContext();

        $result = $context->get('nonexistent', 'default');

        $this->assertEquals('default', $result);
    }

    // Test 3: Has method
    public function testHas_ReturnsTrueWhenKeyExists()
    {
        $context = new EventContext(['key' => 'value']);

        $this->assertTrue($context->has('key'));
        $this->assertFalse($context->has('missing'));
    }

    // Test 4: All method
    public function testAll_ReturnsAllData()
    {
        $data = ['a' => 1, 'b' => 2];
        $context = new EventContext($data);

        $this->assertEquals($data, $context->all());
    }

    // Test 5: Typed basket getter
    public function testGetBasket_ReturnsBasketObject()
    {
        $basket = new \stdClass();
        $basket->total = 100.00;

        $context = new EventContext(['basket' => $basket]);

        $this->assertSame($basket, $context->getBasket());
    }

    // Test 6: Contract support (NEW in v4.0)
    public function testHasContract_ReturnsFalseInitially()
    {
        $context = new EventContext();

        $this->assertFalse($context->hasContract());
    }

    // Test 7: Set contract
    public function testSetContract_StoresContractReference()
    {
        $context = new EventContext();
        $contract = $this->createMock(\OxidSolutionCatalysts\Payments\Component\Contract\PaymentContract::class);

        $context->setContract($contract);

        $this->assertTrue($context->hasContract());
        $this->assertSame($contract, $context->getContract());
    }

    // Test 8: Get contract returns null initially
    public function testGetContract_ReturnsNullWhenNotSet()
    {
        $context = new EventContext();

        $this->assertNull($context->getContract());
    }
}
```

**Run tests:** `vendor/bin/phpunit tests/Unit/Component/EventSystem/Event/EventContextTest.php`

**Expected:** ❌ FAIL - Class EventContext does not exist

### Step 2: Minimal Implementation to Pass Tests 🟢

```php
<?php
// src/Component/Event/EventContext.php

namespace OxidSolutionCatalysts\Payments\Component\Event;

final class EventContext
{
    private array $data = [];
    private $contract = null;

    public function __construct(array $initialData = [])
    {
        $this->data = $initialData;
    }

    public function set(string $key, $value): void
    {
        $this->data[$key] = $value;
    }

    public function get(string $key, $default = null)
    {
        return $this->data[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return isset($this->data[$key]);
    }

    public function all(): array
    {
        return $this->data;
    }

    public function getBasket()
    {
        return $this->get('basket');
    }

    public function getUser()
    {
        return $this->get('user');
    }

    public function getOrderId()
    {
        return $this->get('orderId');
    }

    public function setContract($contract): void
    {
        $this->contract = $contract;
    }

    public function getContract()
    {
        return $this->contract;
    }

    public function hasContract(): bool
    {
        return $this->contract !== null;
    }
}
```

**Run tests:** `vendor/bin/phpunit tests/Unit/Component/EventSystem/Event/EventContextTest.php`

**Expected:** ✅ PASS - All 8 tests green

### Step 3: Refactor (Add Type Hints) 🔵

```php
<?php
// src/Component/Event/EventContext.php

namespace OxidSolutionCatalysts\Payments\Component\Event;

use OxidSolutionCatalysts\Payments\Component\Contract\PaymentContract;

/**
 * Event Context - Request-scoped data cache (Contract-Aware v4.0)
 */
final class EventContext
{
    private array $data = [];
    private ?PaymentContract $contract = null;

    public function __construct(array $initialData = [])
    {
        $this->data = $initialData;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return isset($this->data[$key]);
    }

    public function all(): array
    {
        return $this->data;
    }

    public function getBasket(): ?object
    {
        return $this->get('basket');
    }

    public function getUser(): ?object
    {
        return $this->get('user');
    }

    public function getOrderId(): ?string
    {
        return $this->get('orderId');
    }

    public function setContract(PaymentContract $contract): void
    {
        $this->contract = $contract;
    }

    public function getContract(): ?PaymentContract
    {
        return $this->contract;
    }

    public function hasContract(): bool
    {
        return $this->contract !== null;
    }
}
```

**Run tests again:** ✅ All tests still pass

**Run PHPStan:** `vendor/bin/phpstan analyse src/Component/Event/EventContext.php --level=6`

**Expected:** ✅ No errors

---

## Phase 2: Contract Events (TDD Cycle)

### Step 1: Write Tests FIRST 🔴

```php
<?php
// tests/Unit/Component/EventSystem/Event/Contract/ContractCreatedEventTest.php

namespace OxidSolutionCatalysts\Payments\Tests\Unit\Component\EventSystem\Event\Contract;

use PHPUnit\Framework\TestCase;
use OxidSolutionCatalysts\Payments\Component\Event\Contract\ContractCreatedEvent;
use OxidSolutionCatalysts\Payments\Component\Event\EventContext;
use OxidSolutionCatalysts\Payments\Component\Contract\PaymentContract;

class ContractCreatedEventTest extends TestCase
{
    private PaymentContract $contract;
    private EventContext $context;

    protected function setUp(): void
    {
        $this->contract = $this->createMock(PaymentContract::class);
        $this->contract->method('getId')->willReturn('contract_123');
        $this->contract->method('getStateValue')->willReturn('draft');

        $this->context = new EventContext(['userId' => 'user_456']);
    }

    // Test 1: Event creation
    public function testConstruct_CreatesEvent()
    {
        $event = new ContractCreatedEvent($this->contract, $this->context);

        $this->assertInstanceOf(ContractCreatedEvent::class, $event);
    }

    // Test 2: Get contract
    public function testGetContract_ReturnsContract()
    {
        $event = new ContractCreatedEvent($this->contract, $this->context);

        $this->assertSame($this->contract, $event->getContract());
    }

    // Test 3: Get context
    public function testGetContext_ReturnsContext()
    {
        $event = new ContractCreatedEvent($this->contract, $this->context);

        $this->assertSame($this->context, $event->getContext());
    }

    // Test 4: Get contract ID convenience method
    public function testGetContractId_ReturnsIdFromContract()
    {
        $event = new ContractCreatedEvent($this->contract, $this->context);

        $this->assertEquals('contract_123', $event->getContractId());
    }

    // Test 5: Get contract state convenience method
    public function testGetContractState_ReturnsStateFromContract()
    {
        $event = new ContractCreatedEvent($this->contract, $this->context);

        $this->assertEquals('draft', $event->getContractState());
    }

    // Test 6: Immutability - contract cannot be changed
    public function testEvent_IsImmutable()
    {
        $event = new ContractCreatedEvent($this->contract, $this->context);

        // Verify no setter methods exist
        $this->assertFalse(method_exists($event, 'setContract'));
        $this->assertFalse(method_exists($event, 'setContext'));
    }
}
```

**Run tests:** `vendor/bin/phpunit tests/Unit/Component/EventSystem/Event/Contract/ContractCreatedEventTest.php`

**Expected:** ❌ FAIL - Class ContractCreatedEvent does not exist

### Step 2: Minimal Implementation 🟢

```php
<?php
// src/Component/Event/Contract/ContractCreatedEvent.php

namespace OxidSolutionCatalysts\Payments\Component\Event\Contract;

use OxidSolutionCatalysts\Payments\Component\Event\EventContext;
use OxidSolutionCatalysts\Payments\Component\Contract\PaymentContract;

final class ContractCreatedEvent
{
    private PaymentContract $contract;
    private EventContext $context;

    public function __construct(PaymentContract $contract, EventContext $context)
    {
        $this->contract = $contract;
        $this->context = $context;
    }

    public function getContract(): PaymentContract
    {
        return $this->contract;
    }

    public function getContext(): EventContext
    {
        return $this->context;
    }

    public function getContractId(): string
    {
        return $this->contract->getId();
    }

    public function getContractState(): string
    {
        return $this->contract->getStateValue();
    }
}
```

**Run tests:** ✅ All 6 tests pass

### Step 3: Add Documentation 🔵

```php
<?php
// src/Component/Event/Contract/ContractCreatedEvent.php

namespace OxidSolutionCatalysts\Payments\Component\Event\Contract;

use OxidSolutionCatalysts\Payments\Component\Event\EventContext;
use OxidSolutionCatalysts\Payments\Component\Contract\PaymentContract;

/**
 * Contract Created Event (v4.0)
 *
 * Emitted when a new payment contract is created (state: DRAFT).
 * Signals the start of the contract lifecycle.
 *
 * Listeners:
 * - ContractConditionResolverHandler: Begins condition resolution
 * - AuditLogHandler: Records contract creation
 */
final class ContractCreatedEvent
{
    private PaymentContract $contract;
    private EventContext $context;

    public function __construct(PaymentContract $contract, EventContext $context)
    {
        $this->contract = $contract;
        $this->context = $context;
    }

    public function getContract(): PaymentContract
    {
        return $this->contract;
    }

    public function getContext(): EventContext
    {
        return $this->context;
    }

    public function getContractId(): string
    {
        return $this->contract->getId();
    }

    public function getContractState(): string
    {
        return $this->contract->getStateValue();
    }
}
```

---

## Phase 3: Payment Events (TDD Cycle)

### Step 1: Write Tests FIRST 🔴

```php
<?php
// tests/Unit/Component/EventSystem/Event/Payment/PaymentInitiatedEventTest.php

namespace OxidSolutionCatalysts\Payments\Tests\Unit\Component\EventSystem\Event\Payment;

use PHPUnit\Framework\TestCase;
use OxidSolutionCatalysts\Payments\Component\Event\Domain\PaymentInitiatedEvent;
use OxidSolutionCatalysts\Payments\Component\Event\EventContext;

class PaymentInitiatedEventTest extends TestCase
{
    private EventContext $context;

    protected function setUp(): void
    {
        $this->context = new EventContext([
            'basket' => new \stdClass(),
            'userId' => 'user_123'
        ]);
    }

    // Test 1: Valid event creation
    public function testConstruct_WithValidData_CreatesEvent()
    {
        $event = new PaymentInitiatedEvent(
            $this->context,
            'pm_card',
            100.00,
            'EUR',
            'https://shop.com/return',
            'https://shop.com/cancel'
        );

        $this->assertInstanceOf(PaymentInitiatedEvent::class, $event);
    }

    // Test 2: Validate amount - must be positive
    public function testConstruct_WithNegativeAmount_ThrowsException()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Amount must be positive');

        new PaymentInitiatedEvent(
            $this->context,
            'pm_card',
            -10.00,  // INVALID
            'EUR',
            'https://shop.com/return',
            'https://shop.com/cancel'
        );
    }

    // Test 3: Validate amount - zero not allowed
    public function testConstruct_WithZeroAmount_ThrowsException()
    {
        $this->expectException(\InvalidArgumentException::class);

        new PaymentInitiatedEvent(
            $this->context,
            'pm_card',
            0.00,  // INVALID
            'EUR',
            'https://shop.com/return',
            'https://shop.com/cancel'
        );
    }

    // Test 4: Validate currency - must be 3 letters
    public function testConstruct_WithInvalidCurrency_ThrowsException()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Currency must be 3-letter ISO code');

        new PaymentInitiatedEvent(
            $this->context,
            'pm_card',
            100.00,
            'EURO',  // INVALID - 4 letters
            'https://shop.com/return',
            'https://shop.com/cancel'
        );
    }

    // Test 5: Getters return correct values
    public function testGetters_ReturnConstructorValues()
    {
        $event = new PaymentInitiatedEvent(
            $this->context,
            'pm_card',
            100.00,
            'EUR',
            'https://shop.com/return',
            'https://shop.com/cancel'
        );

        $this->assertSame($this->context, $event->getContext());
        $this->assertEquals('pm_card', $event->getPaymentMethodId());
        $this->assertEquals(100.00, $event->getAmount());
        $this->assertEquals('EUR', $event->getCurrency());
        $this->assertEquals('https://shop.com/return', $event->getReturnUrl());
        $this->assertEquals('https://shop.com/cancel', $event->getCancelUrl());
    }

    // Test 6: Provider redirect URL - initially null
    public function testGetProviderRedirectUrl_InitiallyNull()
    {
        $event = new PaymentInitiatedEvent(
            $this->context,
            'pm_card',
            100.00,
            'EUR',
            'https://shop.com/return',
            'https://shop.com/cancel'
        );

        $this->assertNull($event->getProviderRedirectUrl());
    }

    // Test 7: Set provider redirect URL
    public function testSetProviderRedirectUrl_StoresUrl()
    {
        $event = new PaymentInitiatedEvent(
            $this->context,
            'pm_card',
            100.00,
            'EUR',
            'https://shop.com/return',
            'https://shop.com/cancel'
        );

        $event->setProviderRedirectUrl('https://stripe.com/pay/12345');

        $this->assertEquals('https://stripe.com/pay/12345', $event->getProviderRedirectUrl());
    }

    // Test 8: Provider order ID
    public function testSetProviderOrderId_StoresId()
    {
        $event = new PaymentInitiatedEvent(
            $this->context,
            'pm_card',
            100.00,
            'EUR',
            'https://shop.com/return',
            'https://shop.com/cancel'
        );

        $event->setProviderOrderId('pi_123456');

        $this->assertEquals('pi_123456', $event->getProviderOrderId());
    }
}
```

**Run tests:** ❌ FAIL - Class PaymentInitiatedEvent does not exist

### Step 2: Minimal Implementation 🟢

```php
<?php
// src/Component/Event/Domain/PaymentInitiatedEvent.php

namespace OxidSolutionCatalysts\Payments\Component\Event\Domain;

use OxidSolutionCatalysts\Payments\Component\Event\EventContext;

final class PaymentInitiatedEvent
{
    private EventContext $context;
    private string $paymentMethodId;
    private float $amount;
    private string $currency;
    private string $returnUrl;
    private string $cancelUrl;
    private ?string $providerRedirectUrl = null;
    private ?string $providerOrderId = null;

    public function __construct(
        EventContext $context,
        string $paymentMethodId,
        float $amount,
        string $currency,
        string $returnUrl,
        string $cancelUrl
    ) {
        $this->validateAmount($amount);
        $this->validateCurrency($currency);

        $this->context = $context;
        $this->paymentMethodId = $paymentMethodId;
        $this->amount = $amount;
        $this->currency = $currency;
        $this->returnUrl = $returnUrl;
        $this->cancelUrl = $cancelUrl;
    }

    public function getContext(): EventContext
    {
        return $this->context;
    }

    public function getPaymentMethodId(): string
    {
        return $this->paymentMethodId;
    }

    public function getAmount(): float
    {
        return $this->amount;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getReturnUrl(): string
    {
        return $this->returnUrl;
    }

    public function getCancelUrl(): string
    {
        return $this->cancelUrl;
    }

    public function setProviderRedirectUrl(string $url): void
    {
        $this->providerRedirectUrl = $url;
    }

    public function getProviderRedirectUrl(): ?string
    {
        return $this->providerRedirectUrl;
    }

    public function setProviderOrderId(string $orderId): void
    {
        $this->providerOrderId = $orderId;
    }

    public function getProviderOrderId(): ?string
    {
        return $this->providerOrderId;
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

**Run tests:** ✅ All 8 tests pass

---

## Phase 4: EventDispatcher (TDD Cycle)

### Step 1: Write Tests FIRST 🔴

```php
<?php
// tests/Unit/Component/EventSystem/Event/EventDispatcherTest.php

namespace OxidSolutionCatalysts\Payments\Tests\Component\Unit\Event;

use PHPUnit\Framework\TestCase;
use Osc\Payment\Component\Event\EventDispatcher;
use Psr\Log\LoggerInterface;

class EventDispatcherTest extends TestCase
{
    private EventDispatcher $dispatcher;

    protected function setUp(): void
    {
        $this->dispatcher = new EventDispatcher();
    }

    // Test 1: Add listener
    public function testAddListener_RegistersListener()
    {
        $called = false;
        $listener = function($event) use (&$called) {
            $called = true;
        };

        $this->dispatcher->addListener(\stdClass::class, $listener);

        $this->assertTrue($this->dispatcher->hasListeners(\stdClass::class));
    }

    // Test 2: Has listeners returns false initially
    public function testHasListeners_ReturnsFalseWhenNoListeners()
    {
        $this->assertFalse($this->dispatcher->hasListeners(\stdClass::class));
    }

    // Test 3: Dispatch calls listener
    public function testDispatch_CallsRegisteredListener()
    {
        $called = false;
        $listener = function($event) use (&$called) {
            $called = true;
        };

        $this->dispatcher->addListener(\stdClass::class, $listener);
        $event = new \stdClass();

        $this->dispatcher->dispatch($event);

        $this->assertTrue($called);
    }

    // Test 4: Dispatch returns event
    public function testDispatch_ReturnsEvent()
    {
        $event = new \stdClass();

        $result = $this->dispatcher->dispatch($event);

        $this->assertSame($event, $result);
    }

    // Test 5: Multiple listeners are called
    public function testDispatch_CallsAllRegisteredListeners()
    {
        $callCount = 0;
        $listener1 = function($event) use (&$callCount) { $callCount++; };
        $listener2 = function($event) use (&$callCount) { $callCount++; };

        $this->dispatcher->addListener(\stdClass::class, $listener1);
        $this->dispatcher->addListener(\stdClass::class, $listener2);

        $this->dispatcher->dispatch(new \stdClass());

        $this->assertEquals(2, $callCount);
    }

    // Test 6: Priority ordering - higher priority first
    public function testDispatch_CallsListenersInPriorityOrder()
    {
        $callOrder = [];
        $listener1 = function($event) use (&$callOrder) { $callOrder[] = 'low'; };
        $listener2 = function($event) use (&$callOrder) { $callOrder[] = 'high'; };

        $this->dispatcher->addListener(\stdClass::class, $listener1, 0);  // Low priority
        $this->dispatcher->addListener(\stdClass::class, $listener2, 10); // High priority

        $this->dispatcher->dispatch(new \stdClass());

        $this->assertEquals(['high', 'low'], $callOrder);
    }

    // Test 7: Remove listener
    public function testRemoveListener_RemovesListener()
    {
        $called = false;
        $listener = function($event) use (&$called) { $called = true; };

        $this->dispatcher->addListener(\stdClass::class, $listener);
        $this->dispatcher->removeListener(\stdClass::class, $listener);

        $this->dispatcher->dispatch(new \stdClass());

        $this->assertFalse($called);
    }

    // Test 8: Get listeners
    public function testGetListeners_ReturnsRegisteredListeners()
    {
        $listener1 = function() {};
        $listener2 = function() {};

        $this->dispatcher->addListener(\stdClass::class, $listener1);
        $this->dispatcher->addListener(\stdClass::class, $listener2);

        $listeners = $this->dispatcher->getListeners(\stdClass::class);

        $this->assertCount(2, $listeners);
    }

    // Test 9: Logger integration
    public function testDispatch_LogsEventDispatching()
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
               ->method('debug')
               ->with('Dispatching event', $this->arrayHasKey('event'));

        $dispatcher = new EventDispatcher($logger);
        $dispatcher->addListener(\stdClass::class, function() {});

        $dispatcher->dispatch(new \stdClass());
    }

    // Test 10: No listeners - dispatch returns event unchanged
    public function testDispatch_WithNoListeners_ReturnsEventUnchanged()
    {
        $event = new \stdClass();
        $event->data = 'test';

        $result = $this->dispatcher->dispatch($event);

        $this->assertSame($event, $result);
        $this->assertEquals('test', $result->data);
    }
}
```

**Run tests:** ❌ FAIL - Class EventDispatcher does not exist

### Step 2: Minimal Implementation 🟢

```php
<?php
// src/Component/Event/EventDispatcher.php

namespace OxidSolutionCatalysts\Payments\Component\Event;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class EventDispatcher
{
    private array $listeners = [];
    private LoggerInterface $logger;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
    }

    public function dispatch(object $event): object
    {
        $eventClass = get_class($event);
        $this->logger->debug('Dispatching event', ['event' => $eventClass]);

        if (!$this->hasListeners($eventClass)) {
            return $event;
        }

        foreach ($this->getListeners($eventClass) as $listener) {
            $listener($event);
        }

        return $event;
    }

    public function addListener(string $eventClass, callable $listener, int $priority = 0): void
    {
        if (!isset($this->listeners[$eventClass])) {
            $this->listeners[$eventClass] = [];
        }

        $this->listeners[$eventClass][] = [$listener, $priority];

        usort($this->listeners[$eventClass], fn($a, $b) => $b[1] <=> $a[1]);
    }

    public function removeListener(string $eventClass, callable $listener): void
    {
        if (!isset($this->listeners[$eventClass])) {
            return;
        }

        $this->listeners[$eventClass] = array_filter(
            $this->listeners[$eventClass],
            fn($item) => $item[0] !== $listener
        );
    }

    public function getListeners(string $eventClass): array
    {
        if (!isset($this->listeners[$eventClass])) {
            return [];
        }

        return array_map(fn($item) => $item[0], $this->listeners[$eventClass]);
    }

    public function hasListeners(string $eventClass): bool
    {
        return isset($this->listeners[$eventClass]) && !empty($this->listeners[$eventClass]);
    }
}
```

**Run tests:** ✅ All 10 tests pass

---

## Technical Details (Reference)

### EventContext Implementation (Contract-Aware)

```php
<?php
// src/Component/Event/EventContext.php

namespace OxidSolutionCatalysts\Payments\Component\Event;

use OxidSolutionCatalysts\Payments\Component\Model\PaymentContract;

/**
 * Event Context - Request-scoped data cache (Contract-Aware v4.0)
 *
 * Prevents multiple DB queries during event processing.
 * Enhanced with contract reference for smart-contract pattern.
 */
final class EventContext
{
    private array $data = [];
    private ?PaymentContract $contract = null;  // NEW in v4.0!

    public function __construct(array $initialData = [])
    {
        $this->data = $initialData;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return isset($this->data[$key]);
    }

    public function all(): array
    {
        return $this->data;
    }

    // Typed convenience methods
    public function getBasket(): ?object
    {
        return $this->get('basket');
    }

    public function getUser(): ?object
    {
        return $this->get('user');
    }

    public function getOrderId(): ?string
    {
        return $this->get('orderId');
    }

    // NEW in v4.0: Contract support
    public function setContract(PaymentContract $contract): void
    {
        $this->contract = $contract;
    }

    public function getContract(): ?PaymentContract
    {
        return $this->contract;
    }

    public function hasContract(): bool
    {
        return $this->contract !== null;
    }
}
```

### Contract Lifecycle Events (NEW in v4.0)

All contract events are in `src/Component/Event/Contract/` namespace:

#### Event Catalog

| Event | Emitted When | Purpose | Listeners |
|-------|-------------|---------|-----------|
| `ContractCreatedEvent` | Contract created (DRAFT) | Start condition resolution | ConditionResolverHandler |
| `ContractTransitionedToPendingEvent` | Contract moves to PENDING | Begin parallel condition checks | PaymentAuthHandler, FraudCheckHandler |
| `ContractConditionFulfilledEvent` | Single condition fulfilled | Track progress | StateMonitorHandler |
| `ContractReadyToCommitEvent` | All conditions met | Create order | OrderCreationHandler |
| `ContractCommittedEvent` | Order created | Link contract to order | OrderStateHandler |
| `ContractFulfilledEvent` | Payment captured | Complete order | EmailHandler, StockHandler |
| `ContractCancelledEvent` | User/system cancelled | Cleanup | RollbackHandler |
| `ContractExpiredEvent` | Timeout reached | Cleanup | ArchiveHandler |
| `ContractFailedEvent` | Condition failed | Handle error | NotificationHandler |

#### Example Contract Event

```php
<?php
// src/Component/Event/Contract/ContractCreatedEvent.php

namespace OxidSolutionCatalysts\Payments\Component\Event\Contract;

use OxidSolutionCatalysts\Payments\Component\Event\EventContext;
use OxidSolutionCatalysts\Payments\Component\Model\PaymentContract;

/**
 * Contract Created Event (v4.0)
 *
 * Emitted when a new payment contract is created (state: DRAFT).
 * Signals the start of the contract lifecycle.
 */
final class ContractCreatedEvent
{
    private PaymentContract $contract;
    private EventContext $context;

    public function __construct(PaymentContract $contract, EventContext $context)
    {
        $this->contract = $contract;
        $this->context = $context;
    }

    public function getContract(): PaymentContract
    {
        return $this->contract;
    }

    public function getContext(): EventContext
    {
        return $this->context;
    }

    public function getContractId(): string
    {
        return $this->contract->getId();
    }

    public function getContractState(): string
    {
        return $this->contract->getState();
    }
}
```

### Payment Lifecycle Events (Traditional)

Events in `src/Component/Event/Domain/` namespace:

#### Example Payment Event (Contract-Aware)

```php
<?php
// src/Component/Event/Domain/PaymentInitiatedEvent.php

namespace OxidSolutionCatalysts\Payments\Component\Event\Domain;

use OxidSolutionCatalysts\Payments\Component\Event\EventContext;

/**
 * Payment Initiated Event
 *
 * Emitted when customer initiates payment at checkout.
 * Handler should create provider order and return redirect URL.
 */
final class PaymentInitiatedEvent
{
    private EventContext $context;
    private string $paymentMethodId;
    private float $amount;
    private string $currency;
    private string $returnUrl;
    private string $cancelUrl;

    // Result data (set by handlers)
    private ?string $providerRedirectUrl = null;
    private ?string $providerOrderId = null;

    public function __construct(
        EventContext $context,
        string $paymentMethodId,
        float $amount,
        string $currency,
        string $returnUrl,
        string $cancelUrl
    ) {
        $this->validateAmount($amount);
        $this->validateCurrency($currency);

        $this->context = $context;
        $this->paymentMethodId = $paymentMethodId;
        $this->amount = $amount;
        $this->currency = $currency;
        $this->returnUrl = $returnUrl;
        $this->cancelUrl = $cancelUrl;
    }

    // Getters
    public function getContext(): EventContext { return $this->context; }
    public function getPaymentMethodId(): string { return $this->paymentMethodId; }
    public function getAmount(): float { return $this->amount; }
    public function getCurrency(): string { return $this->currency; }
    public function getReturnUrl(): string { return $this->returnUrl; }
    public function getCancelUrl(): string { return $this->cancelUrl; }

    // Result setters (for handlers)
    public function setProviderRedirectUrl(string $url): void
    {
        $this->providerRedirectUrl = $url;
    }

    public function getProviderRedirectUrl(): ?string
    {
        return $this->providerRedirectUrl;
    }

    public function setProviderOrderId(string $orderId): void
    {
        $this->providerOrderId = $orderId;
    }

    public function getProviderOrderId(): ?string
    {
        return $this->providerOrderId;
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

### Event Dispatcher

```php
<?php
// src/Component/Event/EventDispatcher.php

namespace OxidSolutionCatalysts\Payments\Component\Event;

use OxidSolutionCatalysts\Payments\Component\Contract\EventDispatcherInterface;
use Psr\EventDispatcher\StoppableEventInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class EventDispatcher implements EventDispatcherInterface
{
    private array $listeners = [];
    private LoggerInterface $logger;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
    }

    public function dispatch(object $event): object
    {
        $eventClass = get_class($event);
        $this->logger->debug('Dispatching event', ['event' => $eventClass]);

        if (!$this->hasListeners($eventClass)) {
            return $event;
        }

        foreach ($this->getListeners($eventClass) as $listener) {
            if ($event instanceof StoppableEventInterface && $event->isPropagationStopped()) {
                break;
            }

            $listener($event);
        }

        return $event;
    }

    public function addListener(string $eventClass, callable $listener, int $priority = 0): void
    {
        if (!isset($this->listeners[$eventClass])) {
            $this->listeners[$eventClass] = [];
        }

        $this->listeners[$eventClass][] = [$listener, $priority];

        usort($this->listeners[$eventClass], fn($a, $b) => $b[1] <=> $a[1]);
    }

    public function removeListener(string $eventClass, callable $listener): void
    {
        if (!isset($this->listeners[$eventClass])) {
            return;
        }

        $this->listeners[$eventClass] = array_filter(
            $this->listeners[$eventClass],
            fn($item) => $item[0] !== $listener
        );
    }

    public function getListeners(string $eventClass): array
    {
        if (!isset($this->listeners[$eventClass])) {
            return [];
        }

        return array_map(fn($item) => $item[0], $this->listeners[$eventClass]);
    }

    public function hasListeners(string $eventClass): bool
    {
        return isset($this->listeners[$eventClass]) && !empty($this->listeners[$eventClass]);
    }
}
```

---

## TDD Workflow

### Tests to Write

```php
<?php
// tests/Component/Unit/Component/Event/EventContextTest.php
// tests/Component/Unit/Component/Event/EventDispatcherTest.php
// tests/Component/Unit/Component/Event/Domain/PaymentInitiatedEventTest.php
// tests/Component/Unit/Component/Event/Domain/PaymentCapturedEventTest.php
// ... (tests for all 8 events)
```

(Same test structure as before, but with correct namespaces)

---

## Tasks Breakdown (TDD Approach)

### Task 1: EventContext (2 hours) ✅ Covered Above

**TDD Cycle:**
1. 🔴 Write 8 tests (basic storage, defaults, contract support)
2. 🟢 Implement minimal EventContext
3. 🔵 Add type hints and documentation

**Tests:** 8 tests, 100% coverage
**Files:** `tests/Unit/Component/EventSystem/Event/EventContextTest.php`, `src/Component/Event/EventContext.php`

---

### Task 2: Contract Lifecycle Events (3 hours)

**Apply TDD cycle for each of 9 events:**

#### Event 1: ContractCreatedEvent ✅ Covered Above
- Tests: 6 tests (creation, getters, immutability)
- Implementation: Minimal class with 4 methods

#### Event 2: ContractTransitionedToPendingEvent (20 min)

**🔴 Write Tests First:**
```php
// tests/Component/Unit/Event/Contract/ContractTransitionedToPendingEventTest.php

public function testGetContract_ReturnsContract()
public function testGetConditions_ReturnsConditionList()
public function testEvent_IsImmutable()
```

**🟢 Minimal Implementation:**
```php
// src/Component/Event/Contract/ContractTransitionedToPendingEvent.php
final class ContractTransitionedToPendingEvent { /* ... */ }
```

#### Event 3: ContractConditionFulfilledEvent (20 min)

**🔴 Write Tests First:**
```php
// tests/Component/Unit/Event/Contract/ContractConditionFulfilledEventTest.php

public function testGetContract_ReturnsContract()
public function testGetConditionType_ReturnsType()
public function testGetConditionData_ReturnsData()
```

**🟢 Minimal Implementation:**
```php
// src/Component/Event/Contract/ContractConditionFulfilledEvent.php
final class ContractConditionFulfilledEvent { /* ... */ }
```

#### Remaining 6 Contract Events (2 hours)
- ContractReadyToCommitEvent
- ContractCommittedEvent (includes Order reference)
- ContractFulfilledEvent
- ContractCancelledEvent (includes reason)
- ContractExpiredEvent
- ContractFailedEvent (includes error details)

**Pattern for each:**
1. 🔴 Write 4-6 tests
2. 🟢 Implement minimal class
3. 🔵 Add documentation
4. ✅ Verify all tests pass

---

### Task 3: Payment Lifecycle Events (2 hours)

#### Event 1: PaymentInitiatedEvent ✅ Covered Above
- Tests: 8 tests (validation, getters, result setters)
- Implementation: Full validation logic

#### Event 2: PaymentAuthorizedEvent (15 min)

**🔴 Write Tests First:**
```php
// tests/Component/Unit/Event/Domain/PaymentAuthorizedEventTest.php

public function testConstruct_WithValidData_CreatesEvent()
public function testGetAuthorizationId_ReturnsId()
public function testGetProviderOrderId_ReturnsId()
public function testGetAmount_ReturnsAmount()
public function testGetCurrency_ReturnsCurrency()
```

**🟢 Minimal Implementation:**
```php
// src/Component/Event/Domain/PaymentAuthorizedEvent.php
final class PaymentAuthorizedEvent { /* ... */ }
```

#### Remaining 6 Payment Events (1.5 hours)
- PaymentCapturedEvent (authorization ID, capture amount)
- PaymentFailedEvent (error code, error message)
- PaymentRefundedEvent (refund ID, amount, reason)
- OrderCreatedEvent (order ID, order number)
- OrderCompletedEvent (order ID, completion timestamp)
- WebhookReceivedEvent (raw payload, signature, provider)

**Pattern for each:**
1. 🔴 Write 4-6 tests
2. 🟢 Implement minimal class
3. 🔵 Add validation where needed
4. ✅ Verify all tests pass

---

### Task 4: EventDispatcher (2 hours) ✅ Covered Above

**TDD Cycle:**
1. 🔴 Write 10 tests (listeners, dispatch, priority, logging)
2. 🟢 Implement minimal dispatcher
3. 🔵 Add PSR-14 StoppableEventInterface support (optional)

**Tests:** 10 tests, 100% coverage
**Files:** `tests/Unit/Component/EventSystem/Event/EventDispatcherTest.php`, `src/Component/Event/EventDispatcher.php`

---

### Task 5: Integration Tests (1 hour)

**Integration Test 1: Contract Event Flow**

```php
<?php
// tests/Integration/Component/EventSystem/Event/ContractEventFlowTest.php

public function testContractLifecycle_EventsAreEmitted()
{
    $dispatcher = new EventDispatcher();
    $events = [];

    // Register listeners
    $dispatcher->addListener(ContractCreatedEvent::class, function($e) use (&$events) {
        $events[] = 'created';
    });
    $dispatcher->addListener(ContractTransitionedToPendingEvent::class, function($e) use (&$events) {
        $events[] = 'pending';
    });

    // Simulate flow
    $contract = $this->createMockContract();
    $context = new EventContext(['userId' => '123']);

    $dispatcher->dispatch(new ContractCreatedEvent($contract, $context));
    $dispatcher->dispatch(new ContractTransitionedToPendingEvent($contract, $context));

    // Verify order
    $this->assertEquals(['created', 'pending'], $events);
}
```

**Integration Test 2: Payment Event Flow**

```php
<?php
// tests/Integration/Component/EventSystem/Event/PaymentEventFlowTest.php

public function testPaymentFlow_EventsAreEmittedInOrder()
{
    $dispatcher = new EventDispatcher();
    $context = new EventContext(['userId' => '123']);

    // Test full payment flow
    $initiatedEvent = new PaymentInitiatedEvent($context, 'pm_card', 100.00, 'EUR', '/return', '/cancel');
    $dispatcher->dispatch($initiatedEvent);

    // Verify event data integrity
    $this->assertEquals(100.00, $initiatedEvent->getAmount());
}
```

**Integration Test 3: EventContext Passing**

```php
<?php
// tests/Integration/Component/EventSystem/Event/EventContextPassingTest.php

public function testEventContext_PassedBetweenEvents()
{
    $context = new EventContext(['basket' => new \stdClass()]);
    $contract = $this->createMockContract();

    // Set contract in context
    $context->setContract($contract);

    // Create event with context
    $event = new ContractCreatedEvent($contract, $context);

    // Verify context is accessible
    $this->assertTrue($event->getContext()->hasContract());
    $this->assertSame($contract, $event->getContext()->getContract());
}
```

---

## TDD Best Practices for This Ticket

### 1. Write Tests Before Code
```bash
# ALWAYS follow this order:
$ vim tests/Unit/Component/EventSystem/Event/EventContextTest.php  # 1. Write test
$ vendor/bin/phpunit tests/Unit/Component/EventSystem/Event/EventContextTest.php  # 2. Watch it FAIL (RED)
$ vim src/Component/Event/EventContext.php  # 3. Write minimal code
$ vendor/bin/phpunit tests/Unit/Component/EventSystem/Event/EventContextTest.php  # 4. Watch it PASS (GREEN)
$ vim src/Component/Event/EventContext.php  # 5. Refactor (BLUE)
$ vendor/bin/phpunit tests/Unit/Component/EventSystem/Event/EventContextTest.php  # 6. Verify still PASS
```

### 2. One Test at a Time
Don't write all tests at once. Write ONE test, make it pass, then write the next test.

```php
// ❌ DON'T DO THIS:
public function test1() { /* ... */ }
public function test2() { /* ... */ }
public function test3() { /* ... */ }
// Implement all at once

// ✅ DO THIS:
public function test1() { /* ... */ }
// Implement to pass test1
// Run test, verify GREEN
// THEN add test2
```

### 3. Commit After Each GREEN
```bash
$ vendor/bin/phpunit tests/Unit/Component/EventSystem/Event/EventContextTest.php
# ✅ All tests pass

$ git add tests/Unit/Component/EventSystem/Event/EventContextTest.php src/Component/Event/EventContext.php
$ git commit -m "feat: add EventContext with contract support (TDD)"
```

### 4. Test Names as Documentation
```php
// ❌ BAD:
public function testConstructor() { /* ... */ }

// ✅ GOOD:
public function testConstruct_WithValidData_CreatesEvent() { /* ... */ }
public function testConstruct_WithNegativeAmount_ThrowsException() { /* ... */ }
```

### 5. Minimal Implementation First
```php
// 🟢 GREEN Phase: Write JUST enough to pass

// ❌ DON'T:
public function get(string $key, mixed $default = null): mixed
{
    // Validate key format
    if (empty($key)) {
        throw new \InvalidArgumentException('Key cannot be empty');
    }

    // Log access
    $this->logger->debug("Accessing key: {$key}");

    // Return with caching
    return $this->cache[$key] ?? $this->data[$key] ?? $default;
}

// ✅ DO (for first test):
public function get($key, $default = null)
{
    return $this->data[$key] ?? $default;
}

// Add complexity ONLY when tests require it!
```

---

## Test Coverage Requirements

| Component | Required Coverage | Notes |
|-----------|------------------|-------|
| EventContext | 100% | Pure logic, no external dependencies |
| Contract Events | 100% | All 9 events, immutability verified |
| Payment Events | 100% | All 8 events, validation tested |
| EventDispatcher | 100% | Priority, listeners, dispatch logic |
| Integration | 80% | Event flow scenarios |

**Total Tests Expected:** ~80-90 unit tests + 5 integration tests

---

## Verification Checklist

After completing each task:

- [ ] All tests written BEFORE implementation
- [ ] All tests pass (`vendor/bin/phpunit`)
- [ ] PHPStan passes level 6+ (`vendor/bin/phpstan analyse src/Component/Event/ --level=6`)
- [ ] Code coverage 100% (`vendor/bin/phpunit --coverage-html coverage/`)
- [ ] Each commit has tests + implementation together
- [ ] No production code without tests
- [ ] All events are immutable (no setters for core properties)
- [ ] EventContext supports contract reference
- [ ] EventDispatcher respects priority ordering

---

## Definition of Done (TDD)

### Tests (Written FIRST)
- [ ] **EventContext tests** - 8 unit tests, 100% coverage
- [ ] **Contract event tests** - 9 events × ~5 tests = 45 unit tests, 100% coverage
- [ ] **Payment event tests** - 8 events × ~5 tests = 40 unit tests, 100% coverage
- [ ] **EventDispatcher tests** - 10 unit tests, 100% coverage
- [ ] **Integration tests** - 3 integration tests, 80% coverage
- [ ] **Total:** ~100 tests, all passing ✅

### Implementation (Driven by Tests)
- [ ] EventContext (contract-aware) implemented in `src/Component/Event/EventContext.php`
- [ ] **9 contract lifecycle events** implemented in `src/Component/Event/Contract/`
  - ContractCreatedEvent
  - ContractTransitionedToPendingEvent
  - ContractConditionFulfilledEvent
  - ContractReadyToCommitEvent
  - ContractCommittedEvent
  - ContractFulfilledEvent
  - ContractCancelledEvent
  - ContractExpiredEvent
  - ContractFailedEvent
- [ ] **8 payment lifecycle events** implemented in `src/Component/Event/Domain/`
  - PaymentInitiatedEvent
  - PaymentAuthorizedEvent
  - PaymentCapturedEvent
  - PaymentFailedEvent
  - PaymentRefundedEvent
  - OrderCreatedEvent
  - OrderCompletedEvent
  - WebhookReceivedEvent
- [ ] EventDispatcher (PSR-14 compliant) implemented in `src/Component/Event/EventDispatcher.php`
- [ ] EventDispatcherInterface in `src/Component/Contract/EventDispatcherInterface.php`

### Quality Gates
- [ ] All tests passing (`vendor/bin/phpunit`)
- [ ] PHPStan level 6+ passes with zero errors (`vendor/bin/phpstan analyse src/Component/Event/ --level=6`)
- [ ] Code coverage 100% for unit tests (`vendor/bin/phpunit --coverage-html coverage/`)
- [ ] All events are immutable (no setters for core properties)
- [ ] EventContext supports contract reference (getContract/setContract/hasContract)
- [ ] EventDispatcher respects priority ordering (higher priority first)
- [ ] All validation tested (amount, currency, etc.)

### Documentation
- [ ] Event catalog complete (all 17 events documented)
- [ ] TDD examples provided (RED-GREEN-REFACTOR)
- [ ] Integration test examples documented
- [ ] Contract lifecycle flow documented

### TDD Compliance
- [ ] ✅ Every production class has tests written FIRST
- [ ] ✅ Every commit includes tests + implementation together
- [ ] ✅ No production code without failing test first
- [ ] ✅ All tests follow RED → GREEN → REFACTOR cycle
- [ ] ✅ Test names follow `testMethodName_Scenario_ExpectedResult` pattern
- [ ] ✅ Minimal implementation approach followed

---

## Contract Lifecycle Event Flow (v4.0)

This section illustrates how contract events enable the smart-contract pattern:

### Phase 1: Contract Creation
```
User clicks "Place Order"
  ↓
Controller emits PaymentInitiatedEvent
  ↓
Handler creates PaymentContract (state: DRAFT)
  ↓
Emit ContractCreatedEvent
```

### Phase 2: Condition Resolution
```
ContractTransitionedToPendingEvent
  ↓
Multiple handlers process in parallel:
  ├─ PaymentAuthHandler → ContractConditionFulfilledEvent (payment_authorized)
  ├─ FraudCheckHandler → ContractConditionFulfilledEvent (fraud_check)
  └─ StockHandler → ContractConditionFulfilledEvent (stock_reserved)
  ↓
Contract monitors: All conditions fulfilled?
  ↓
Emit ContractReadyToCommitEvent
```

### Phase 3: Order Creation
```
ContractReadyToCommitEvent
  ↓
OrderCreationHandler creates oxorder
  ↓
Contract.commitToOrder(orderId)
  ↓
Emit ContractCommittedEvent
```

### Phase 4: Fulfillment
```
WebhookReceivedEvent (payment captured)
  ↓
Contract.fulfill()
  ↓
Emit ContractFulfilledEvent
  ↓
Order.setState(OK)
```

**Key Benefit:** Events make the contract lifecycle explicit, testable, and extensible.

---

## Testing Strategy for Contract Events

### Unit Tests
```php
public function testContractCreatedEvent_CarriesContractAndContext()
{
    $contract = $this->createMockContract();
    $context = new EventContext(['userId' => '123']);

    $event = new ContractCreatedEvent($contract, $context);

    $this->assertSame($contract, $event->getContract());
    $this->assertSame($context, $event->getContext());
    $this->assertEquals('123', $event->getContext()->get('userId'));
}

public function testEventContext_SupportsContractReference()
{
    $context = new EventContext();
    $contract = $this->createMockContract();

    $this->assertFalse($context->hasContract());

    $context->setContract($contract);

    $this->assertTrue($context->hasContract());
    $this->assertSame($contract, $context->getContract());
}
```

### Integration Tests
```php
public function testContractEventFlow_FromCreationToFulfillment()
{
    $dispatcher = new EventDispatcher();
    $contractRepository = $this->createMockRepository();

    // Subscribe handlers
    $dispatcher->addListener(
        ContractCreatedEvent::class,
        fn($event) => $this->handleContractCreated($event)
    );

    // Emit event
    $contract = new PaymentContract(/*...*/);
    $event = new ContractCreatedEvent($contract, new EventContext());
    $dispatcher->dispatch($event);

    // Verify handler was called
    $this->assertTrue($this->handlerWasCalled);
}
```

---

## TDD Summary: Why Test-First Matters

### Benefits of TDD for Event Layer

1. **Clear Specifications**
   - Tests define exact behavior before writing code
   - No ambiguity about what events should do
   - Tests serve as living documentation

2. **Minimal Code**
   - Write only code needed to pass tests
   - No over-engineering or premature optimization
   - Lean, focused implementations

3. **Refactoring Confidence**
   - Change code freely knowing tests will catch breakage
   - Add type hints and documentation without fear
   - Improve code quality incrementally

4. **Fast Feedback Loop**
   ```
   Write test (30 seconds) → Watch fail (5 seconds) →
   Write code (2 minutes) → Watch pass (5 seconds) →
   Commit (10 seconds) → REPEAT
   ```

5. **Prevents Common Mistakes**
   - Forgot to validate amount? Test will catch it
   - Forgot to check currency length? Test will catch it
   - Forgot immutability? Test will verify no setters exist

### Example TDD Session (5 minutes)

```bash
# Minute 1: Write test
$ vim tests/Unit/Component/EventSystem/Event/EventContextTest.php
# Add testSet_StoresValue()

# Minute 2: Run test (RED)
$ vendor/bin/phpunit tests/Unit/Component/EventSystem/Event/EventContextTest.php
# FAIL: Class EventContext does not exist

# Minute 3-4: Write minimal code (GREEN)
$ vim src/Component/Event/EventContext.php
# Add class EventContext with set() and get() methods
$ vendor/bin/phpunit tests/Unit/Component/EventSystem/Event/EventContextTest.php
# PASS: All tests green ✅

# Minute 5: Commit
$ git add tests/Unit/Component/EventSystem/Event/EventContextTest.php src/Component/Event/EventContext.php
$ git commit -m "feat: add EventContext.set() and get() methods (TDD)"
```

**Result:** Working, tested feature in 5 minutes!

---

## Key Takeaways

✅ **ALWAYS write tests before code**
✅ **One test at a time** - don't batch
✅ **Minimal implementation** - YAGNI principle
✅ **Commit after GREEN** - small, safe steps
✅ **Test names document behavior** - be descriptive
✅ **100% coverage is achievable** - pure logic with no external dependencies

**This approach ensures:**
- No bugs slip through (tests catch them)
- Clean, simple code (minimal implementation)
- Easy maintenance (tests document behavior)
- Fast development (immediate feedback)

---

[← Previous: TICKET-001](SPRINT-1-TICKET-01-project-setup.md) | [Back to Sprint Overview](SPRINT-1-overview.md) | [Back to Index](SPRINT-1-index.md) | [Next: TICKET-003 →](SPRINT-1-TICKET-03-component-models.md)
