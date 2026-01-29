# Sprint 1: Event System DI Wiring

**Sprint Goal:** Wire the existing EventDispatcher to Symfony DI container using tagged services
**Duration:** 1 day
**Dependencies:** None (foundational sprint)

---

## Tickets

---

### STRP-101: Create EventListenerProviderInterface

**Priority:** High
**Estimate:** 1 hour
**Type:** Feature

#### Description

Create the interface that defines how event listeners are provided to the EventDispatcher. This is the contract between the DI container and the event system.

#### Acceptance Criteria

- [ ] Interface created at `src/Component/EventSystem/EventListenerProviderInterface.php`
- [ ] Unit test created at `tests/Component/Unit/EventSystem/EventListenerProviderInterfaceTest.php`
- [ ] PHPStan passes
- [ ] PHP CS Fixer passes

#### Technical Details

**File:** `src/Component/EventSystem/EventListenerProviderInterface.php`

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Component\EventSystem;

/**
 * Provides event listeners/handlers for the EventDispatcher.
 * This is the bridge between DI container and event system.
 */
interface EventListenerProviderInterface
{
    /**
     * Returns all registered listeners for an event class.
     *
     * @param string $eventClass Fully qualified event class name
     * @return array<callable> Array of callables that handle the event
     */
    public function getListenersForEvent(string $eventClass): array;

    /**
     * Registers a listener for an event class.
     *
     * @param string $eventClass Event class to listen for
     * @param callable $listener Handler callable
     * @param int $priority Higher priority = executed first (default: 0)
     */
    public function addListener(string $eventClass, callable $listener, int $priority = 0): void;
}
```

#### Test Plan

```php
// tests/Component/Unit/EventSystem/EventListenerProviderInterfaceTest.php
public function testInterfaceDefinesGetListenersForEvent(): void
{
    $reflection = new ReflectionClass(EventListenerProviderInterface::class);
    $this->assertTrue($reflection->hasMethod('getListenersForEvent'));
}

public function testInterfaceDefinesAddListener(): void
{
    $reflection = new ReflectionClass(EventListenerProviderInterface::class);
    $this->assertTrue($reflection->hasMethod('addListener'));
}
```

#### Commands

```bash
# Create file
touch /var/www/extensions/stripe/src/Component/EventSystem/EventListenerProviderInterface.php

# Run tests
docker compose exec php bash -c "cd /var/www && vendor/bin/phpunit extensions/stripe/tests/Component/Unit/EventSystem/EventListenerProviderInterfaceTest.php"

# Check PHPStan
docker compose exec php bash -c "cd /var/www && vendor/bin/phpstan analyse extensions/stripe/src/Component/EventSystem/EventListenerProviderInterface.php -l 8"
```

#### Checklist

- [ ] Interface file created
- [ ] PHPDoc comments added
- [ ] Unit test created
- [ ] Tests pass
- [ ] PHPStan passes

---

### STRP-102: Implement EventListenerProvider

**Priority:** High
**Estimate:** 2 hours
**Type:** Feature
**Depends On:** STRP-101

#### Description

Implement the EventListenerProvider class that:
1. Receives handlers from DI container via tagged iterator
2. Auto-registers handlers by introspecting their `handle()` method type hints
3. Provides listeners to EventDispatcher

#### Acceptance Criteria

- [ ] Class created at `src/Component/EventSystem/EventListenerProvider.php`
- [ ] Implements `EventListenerProviderInterface`
- [ ] Auto-registers handlers from DI container
- [ ] Supports priority-based sorting
- [ ] Unit tests pass with 100% coverage

#### Technical Details

**File:** `src/Component/EventSystem/EventListenerProvider.php`

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Component\EventSystem;

use OxidSolutionCatalysts\Payments\Component\EventSystem\Handler\HandlerInterface;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Manages event listeners and provides them to EventDispatcher.
 * Integrates with Symfony DI via tagged services.
 */
class EventListenerProvider implements EventListenerProviderInterface
{
    /** @var array<string, array<array{listener: callable, priority: int}>> */
    private array $listeners = [];

    /**
     * @param iterable<HandlerInterface> $handlers Handlers injected via DI (tagged services)
     */
    public function __construct(iterable $handlers = [])
    {
        foreach ($handlers as $handler) {
            $this->registerHandler($handler);
        }
    }

    public function getListenersForEvent(string $eventClass): array
    {
        if (!isset($this->listeners[$eventClass])) {
            return [];
        }

        $listeners = $this->listeners[$eventClass];
        usort($listeners, fn($a, $b) => $b['priority'] <=> $a['priority']);

        return array_map(fn($item) => $item['listener'], $listeners);
    }

    public function addListener(string $eventClass, callable $listener, int $priority = 0): void
    {
        if (!isset($this->listeners[$eventClass])) {
            $this->listeners[$eventClass] = [];
        }

        $this->listeners[$eventClass][] = [
            'listener' => $listener,
            'priority' => $priority,
        ];
    }

    /**
     * Registers a handler by introspecting its handle() method type hint.
     */
    private function registerHandler(HandlerInterface $handler): void
    {
        if (!method_exists($handler, 'handle')) {
            return;
        }

        $reflection = new ReflectionMethod($handler, 'handle');
        $parameters = $reflection->getParameters();

        if (count($parameters) === 0) {
            return;
        }

        $paramType = $parameters[0]->getType();
        if (!$paramType instanceof ReflectionNamedType || $paramType->isBuiltin()) {
            return;
        }

        $eventClass = $paramType->getName();
        $this->addListener($eventClass, [$handler, 'handle']);
    }
}
```

#### Test Plan

**File:** `tests/Component/Unit/EventSystem/EventListenerProviderTest.php`

```php
<?php

declare(strict_types=1);

namespace Tests\Component\Unit\EventSystem;

use PHPUnit\Framework\TestCase;
use OxidSolutionCatalysts\Payments\Component\EventSystem\EventListenerProvider;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Handler\HandlerInterface;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\Payment\PaymentInitiatedEvent;

class EventListenerProviderTest extends TestCase
{
    public function testGetListenersForEvent_WithNoListeners_ReturnsEmptyArray(): void
    {
        $provider = new EventListenerProvider([]);

        $listeners = $provider->getListenersForEvent(PaymentInitiatedEvent::class);

        $this->assertIsArray($listeners);
        $this->assertCount(0, $listeners);
    }

    public function testGetListenersForEvent_WithRegisteredHandler_ReturnsListener(): void
    {
        $handler = new class implements HandlerInterface {
            public function handle(PaymentInitiatedEvent $event): void {}
        };

        $provider = new EventListenerProvider([$handler]);

        $listeners = $provider->getListenersForEvent(PaymentInitiatedEvent::class);

        $this->assertCount(1, $listeners);
        $this->assertIsCallable($listeners[0]);
    }

    public function testAddListener_ManualRegistration_IsRetrievable(): void
    {
        $provider = new EventListenerProvider([]);
        $listener = fn($event) => null;

        $provider->addListener(PaymentInitiatedEvent::class, $listener);

        $listeners = $provider->getListenersForEvent(PaymentInitiatedEvent::class);
        $this->assertContains($listener, $listeners);
    }

    public function testGetListenersForEvent_WithMultiplePriorities_ReturnsSortedByPriority(): void
    {
        $provider = new EventListenerProvider([]);

        $lowPriority = fn($e) => 'low';
        $highPriority = fn($e) => 'high';
        $mediumPriority = fn($e) => 'medium';

        $provider->addListener(PaymentInitiatedEvent::class, $lowPriority, 0);
        $provider->addListener(PaymentInitiatedEvent::class, $highPriority, 100);
        $provider->addListener(PaymentInitiatedEvent::class, $mediumPriority, 50);

        $listeners = $provider->getListenersForEvent(PaymentInitiatedEvent::class);

        $this->assertSame($highPriority, $listeners[0]);
        $this->assertSame($mediumPriority, $listeners[1]);
        $this->assertSame($lowPriority, $listeners[2]);
    }

    public function testConstructor_WithMultipleHandlers_RegistersAll(): void
    {
        $handler1 = new class implements HandlerInterface {
            public function handle(PaymentInitiatedEvent $event): void {}
        };

        $handler2 = new class implements HandlerInterface {
            public function handle(PaymentInitiatedEvent $event): void {}
        };

        $provider = new EventListenerProvider([$handler1, $handler2]);

        $listeners = $provider->getListenersForEvent(PaymentInitiatedEvent::class);
        $this->assertCount(2, $listeners);
    }

    public function testRegisterHandler_WithoutHandleMethod_DoesNotRegister(): void
    {
        // Handler without handle method
        $handler = new class implements HandlerInterface {};

        $provider = new EventListenerProvider([$handler]);

        $listeners = $provider->getListenersForEvent(PaymentInitiatedEvent::class);
        $this->assertCount(0, $listeners);
    }
}
```

#### Commands

```bash
# Run tests
docker compose exec php bash -c "cd /var/www && vendor/bin/phpunit extensions/stripe/tests/Component/Unit/EventSystem/EventListenerProviderTest.php"

# Run with coverage
docker compose exec php bash -c "cd /var/www && vendor/bin/phpunit --coverage-text extensions/stripe/tests/Component/Unit/EventSystem/EventListenerProviderTest.php"

# Check PHPStan
docker compose exec php bash -c "cd /var/www && vendor/bin/phpstan analyse extensions/stripe/src/Component/EventSystem/EventListenerProvider.php -l 8"
```

#### Checklist

- [ ] TDD: Write tests first (RED)
- [ ] Implement class (GREEN)
- [ ] Refactor if needed
- [ ] All tests pass
- [ ] 100% coverage
- [ ] PHPStan passes
- [ ] PHP CS Fixer passes

---

### STRP-103: Update services.yaml for Event System

**Priority:** High
**Estimate:** 1 hour
**Type:** Configuration
**Depends On:** STRP-102

#### Description

Update the services.yaml to:
1. Register EventListenerProvider with tagged iterator
2. Update EventDispatcher to use provider
3. Tag existing handlers with `payment.event_handler`

#### Acceptance Criteria

- [ ] EventListenerProvider registered in services.yaml
- [ ] EventDispatcher configured with provider
- [ ] At least one handler tagged and auto-registered
- [ ] Module activates without errors
- [ ] Event dispatch works end-to-end

#### Technical Details

**File:** `services.yaml` (additions)

```yaml
services:
  # ===========================================
  # Event System (Component Level)
  # ===========================================

  # Event Listener Provider - Collects all tagged handlers
  OxidSolutionCatalysts\Payments\Component\EventSystem\EventListenerProviderInterface:
    class: OxidSolutionCatalysts\Payments\Component\EventSystem\EventListenerProvider
    arguments:
      - !tagged_iterator payment.event_handler
    public: false

  # Event Dispatcher - Uses provider for listener lookup
  OxidSolutionCatalysts\Payments\Component\EventSystem\EventDispatcherInterface:
    class: OxidSolutionCatalysts\Payments\Component\EventSystem\EventDispatcher
    arguments:
      - '@OxidSolutionCatalysts\Payments\Component\EventSystem\EventListenerProviderInterface'
    public: true

  # ===========================================
  # Event Handlers (Tagged for auto-registration)
  # ===========================================

  # Contract Creation Handler
  OxidSolutionCatalysts\Payments\Component\EventSystem\Handler\ContractCreationHandler:
    tags:
      - { name: payment.event_handler }
    public: false

  # Contract Fulfillment Handler
  OxidSolutionCatalysts\Payments\Component\EventSystem\Handler\ContractFulfillmentHandler:
    tags:
      - { name: payment.event_handler }
    public: false

  # Order Creation Handler
  OxidSolutionCatalysts\Payments\Component\EventSystem\Handler\OrderCreationHandler:
    tags:
      - { name: payment.event_handler }
    public: false
```

#### Update EventDispatcher

**Note:** The existing `EventDispatcher` needs a minor update to accept the provider:

**File:** `src/Component/EventSystem/EventDispatcher.php` (modification)

```php
class EventDispatcher implements EventDispatcherInterface
{
    private array $listeners = [];
    private ?EventListenerProviderInterface $listenerProvider;

    public function __construct(?EventListenerProviderInterface $listenerProvider = null)
    {
        $this->listenerProvider = $listenerProvider;
    }

    public function dispatch(EventInterface $event): EventInterface
    {
        $eventClass = get_class($event);

        // Get listeners from provider first, then local
        $listeners = $this->listenerProvider
            ? $this->listenerProvider->getListenersForEvent($eventClass)
            : [];

        // Merge with locally added listeners
        if (isset($this->listeners[$eventClass])) {
            $localListeners = $this->getSortedListeners($eventClass);
            $listeners = array_merge($listeners, $localListeners);
        }

        foreach ($listeners as $listener) {
            if ($this->isStoppableEvent($event) && $event->isPropagationStopped()) {
                break;
            }
            $listener($event);
        }

        return $event;
    }

    // ... rest unchanged
}
```

#### Test Plan (Manual Verification)

```bash
# 1. Clear cache
docker compose exec php bash -c "cd /var/www && bin/oe-console oe:cache:clear"

# 2. Deactivate and reactivate module
docker compose exec php bash -c "cd /var/www && bin/oe-console oe:module:deactivate osc_stripe_wallet"
docker compose exec php bash -c "cd /var/www && bin/oe-console oe:module:activate osc_stripe_wallet"

# 3. Verify no errors in activation

# 4. Create a simple test script to verify event dispatch
docker compose exec php bash -c "cd /var/www && php -r \"
require 'bootstrap.php';
\\\$container = \\OxidEsales\\EshopCommunity\\Internal\\Container\\ContainerFactory::getInstance()->getContainer();
\\\$dispatcher = \\\$container->get(\\OxidSolutionCatalysts\\Payments\\Component\\EventSystem\\EventDispatcherInterface::class);
echo 'EventDispatcher loaded: ' . get_class(\\\$dispatcher) . PHP_EOL;
\""
```

#### Checklist

- [ ] services.yaml updated with EventListenerProvider
- [ ] services.yaml updated with EventDispatcher dependency
- [ ] At least one handler tagged
- [ ] EventDispatcher updated to accept provider
- [ ] Cache cleared
- [ ] Module reactivates without errors
- [ ] Manual verification passes

---

## Sprint 1 Completion Criteria

- [ ] All 3 tickets completed
- [ ] Event system wired to DI container
- [ ] Handlers auto-register via tags
- [ ] Module activates without errors
- [ ] Ready for Sprint 2

---

## Notes

- Keep `EventDispatcher` backward compatible (optional provider)
- Don't modify existing handler implementations
- Focus only on DI wiring in this sprint

---

**Next Sprint:** [SPRINT-2-ORCHESTRATOR.md](./SPRINT-2-ORCHESTRATOR.md)
