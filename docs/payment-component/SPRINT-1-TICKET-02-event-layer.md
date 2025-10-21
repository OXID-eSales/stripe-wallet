[← Previous: TICKET-001](SPRINT-1-TICKET-01-project-setup.md) | [Back to Sprint Overview](SPRINT-1-overview.md) | [Back to Index](SPRINT-1-index.md) | [Next: TICKET-003 →](SPRINT-1-TICKET-03-component-models.md)

---

# TICKET-002: Component Event Layer (Domain Events + Context)

## Summary
Implement the reusable event layer in `src/Component/Event/` with domain events, EventContext, and event dispatcher.

## Priority
**P0 - Critical**

## Story Points
**8 points** (2 days)

## Business Value
Establishes the event-driven foundation that enables loose coupling between Component and Stripe layers.

---

## Description

Create the Component event layer:
- EventContext for request data caching
- 8 domain events for payment lifecycle
- Event contracts
- PSR-14 event dispatcher wrapper

All code goes in `src/Component/Event/` as it's provider-agnostic.

---

## Acceptance Criteria

### Must Have
- [ ] EventContext class in `src/Component/Event/`
- [ ] 8 domain events in `src/Component/Event/Domain/`
- [ ] EventDispatcher in `src/Component/Event/`
- [ ] EventDispatcherInterface in `src/Component/Contract/`
- [ ] All events immutable with validation
- [ ] 100% test coverage
- [ ] All events properly namespaced under Component

### Should Have
- [ ] Event factory helpers
- [ ] Event serialization support

---

## Technical Details

### EventContext Implementation

```php
<?php
// src/Component/Event/EventContext.php

namespace Osc\Payment\Component\Event;

/**
 * Event Context - Request-scoped data cache
 *
 * Prevents multiple DB queries during event processing
 */
final class EventContext
{
    private array $data = [];

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
}
```

### Example Domain Event

```php
<?php
// src/Component/Event/Domain/PaymentInitiatedEvent.php

namespace Osc\Payment\Component\Event\Domain;

use Osc\Payment\Component\Event\EventContext;

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

namespace Osc\Payment\Component\Event;

use Osc\Payment\Component\Contract\EventDispatcherInterface;
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
// tests/Unit/Component/Event/EventContextTest.php
// tests/Unit/Component/Event/EventDispatcherTest.php
// tests/Unit/Component/Event/Domain/PaymentInitiatedEventTest.php
// tests/Unit/Component/Event/Domain/PaymentCapturedEventTest.php
// ... (tests for all 8 events)
```

(Same test structure as before, but with correct namespaces)

---

## Tasks Breakdown

1. **EventContext** (2 hours)
   - Write tests
   - Implement EventContext
   - Test request-scoped caching

2. **Domain Events** (4 hours)
   - Implement 8 domain events with tests:
     - PaymentInitiatedEvent
     - PaymentAuthorizedEvent
     - PaymentCapturedEvent
     - PaymentFailedEvent
     - PaymentRefundedEvent
     - OrderCreatedEvent
     - OrderCompletedEvent
     - WebhookReceivedEvent

3. **EventDispatcher** (3 hours)
   - Write dispatcher tests
   - Implement dispatcher
   - Test priority ordering
   - Test stoppable events

4. **Integration** (1 hour)
   - Test full event flow
   - Document event catalog

---

## Definition of Done

- [ ] All acceptance criteria met
- [ ] EventContext + 8 events + dispatcher implemented
- [ ] All in `src/Component/Event/` namespace
- [ ] 100% test coverage
- [ ] All tests passing
- [ ] PHPStan passes
- [ ] Documentation complete

---


---

[← Previous: TICKET-001](SPRINT-1-TICKET-01-project-setup.md) | [Back to Sprint Overview](SPRINT-1-overview.md) | [Back to Index](SPRINT-1-index.md) | [Next: TICKET-003 →](SPRINT-1-TICKET-03-component-models.md)
