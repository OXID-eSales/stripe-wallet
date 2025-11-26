# Event System OXID Integration Explained

**Date:** 2025-11-26

## How EventListener is Triggered During Checkout in Production

### 1. Dependency Injection Setup (`services.yaml`)

```yaml
# Handlers are collected via tagged_iterator
OxidSolutionCatalysts\Payments\Component\EventSystem\EventListenerProviderInterface:
  class: OxidSolutionCatalysts\Payments\Component\EventSystem\EventListenerProvider
  arguments:
    - !tagged_iterator payment.event_handler

# EventDispatcher receives the provider
OxidSolutionCatalysts\Payments\Component\EventSystem\EventDispatcherInterface:
  class: OxidSolutionCatalysts\Payments\Component\EventSystem\EventDispatcher
  arguments:
    - '@OxidSolutionCatalysts\Payments\Component\EventSystem\EventListenerProviderInterface'

# CheckoutOrchestrator receives EventDispatcher
OxidSolutionCatalysts\Payments\Component\Service\CheckoutOrchestratorInterface:
  class: OxidSolutionCatalysts\Payments\Component\Service\CheckoutOrchestrator
  arguments:
    - '@OxidSolutionCatalysts\Payments\Component\EventSystem\EventDispatcherInterface'
```

### 2. The Flow

```
Customer clicks "Place Order"
         ↓
OrderController::execute()
         ↓
isStripePaymentMethod() → YES
         ↓
getCheckoutOrchestrator()
    → ContainerFactory::getContainer()->get(CheckoutOrchestratorInterface::class)
    → Returns CheckoutOrchestrator (already has EventDispatcher injected)
         ↓
CheckoutOrchestrator::processCheckout($basket, $user, $paymentId)
         ↓
Creates PaymentInitiatedEvent with EventContext
         ↓
$this->eventDispatcher->dispatch($event)
         ↓
EventDispatcher::dispatch()
    → Calls listenerProvider->getListenersForEvent(PaymentInitiatedEvent::class)
    → Gets array of [ContractCreationHandler, 'handle']
         ↓
Executes: ContractCreationHandler::handle($event)
    → Creates contract
    → Stores in context: $context->setContract($contract)
    → Dispatches ContractCreatedEvent (chain continues)
         ↓
Back to CheckoutOrchestrator
    → $contract = $context->getContract()
    → Returns CheckoutResult
```

### 3. Key Mechanisms

**Handler Auto-Registration:**
```php
// EventListenerProvider constructor receives DI-tagged handlers
public function __construct(iterable $handlers = [])
{
    foreach ($handlers as $handler) {
        $eventClass = $handler::getHandledEventClass(); // e.g., PaymentInitiatedEvent::class
        $this->addListener($eventClass, [$handler, 'handle']);
    }
}
```

**Handler declares its event:**
```php
class ContractCreationHandler implements HandlerInterface
{
    public static function getHandledEventClass(): string
    {
        return PaymentInitiatedEvent::class;
    }
}
```

**Service Container bridge (in OrderController):**
```php
private function getCheckoutOrchestrator(): CheckoutOrchestratorInterface
{
    return $this->getServiceFromContainer(CheckoutOrchestratorInterface::class);
}
```

### 4. Visual Summary

```
┌─────────────────────────────────────────────────────────────┐
│                    OXID DI Container                        │
│  ┌─────────────────────────────────────────────────────┐   │
│  │  EventListenerProvider                               │   │
│  │    - ContractCreationHandler → PaymentInitiatedEvent │   │
│  │    - ConditionResolverHandler → ContractCreatedEvent │   │
│  │    - PaymentAuthHandler → ContractPendingEvent       │   │
│  └─────────────────────────────────────────────────────┘   │
│                          ↑                                  │
│  ┌─────────────────────────────────────────────────────┐   │
│  │  EventDispatcher                                     │   │
│  │    - Uses listenerProvider for lookups               │   │
│  └─────────────────────────────────────────────────────┘   │
│                          ↑                                  │
│  ┌─────────────────────────────────────────────────────┐   │
│  │  CheckoutOrchestrator                                │   │
│  │    - Calls dispatcher->dispatch()                    │   │
│  └─────────────────────────────────────────────────────┘   │
│                          ↑                                  │
│  ┌─────────────────────────────────────────────────────┐   │
│  │  OrderController                                     │   │
│  │    - getServiceFromContainer(Orchestrator::class)    │   │
│  └─────────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────────┘
```

The magic is in `!tagged_iterator payment.event_handler` - Symfony DI automatically collects all services tagged with `payment.event_handler` and injects them into EventListenerProvider at container build time.

---

## Complete Production Flow Diagram

```
OrderController::execute()
    ↓
isStripePaymentMethod() ? YES
    ↓
getCheckoutOrchestrator()
    → ContainerFactory::getContainer()->get(CheckoutOrchestratorInterface::class)
    → Returns CheckoutOrchestrator instance (injected with EventDispatcher)
    ↓
CheckoutOrchestrator::processCheckout()
    ↓
Create PaymentInitiatedEvent with EventContext
    ↓
eventDispatcher->dispatch($event)
    ↓
EventDispatcher::dispatch()
    ↓
listenerProvider->getListenersForEvent(PaymentInitiatedEvent::class)
    ↓
EventListenerProvider::getListenersForEvent()
    → Look up handlers registered during __construct()
    → Handlers were injected via DI tagged_iterator
    → Return sorted array of callables [$handler, 'handle']
    ↓
For each listener (callable):
    listener($event)
    ↓
ContractCreationHandler::handle($event)
    ↓
Extract basket, user from event->getContext()
    ↓
Create payment contract via ContractService
    ↓
Set contract in context: context->setContract($contract)
    ↓
Dispatch follow-up event: ContractCreatedEvent
    ↓
CheckoutOrchestrator continues after dispatch()
    ↓
Retrieve contract from context: context->getContract()
    ↓
Return CheckoutResult with contract ID
    ↓
OrderController stores contract ID in session
    ↓
Continue with standard OXID order creation
```

---

## Key Architectural Patterns

### 1. Dependency Injection with Tagged Services
- Handlers are automatically collected via `!tagged_iterator payment.event_handler`
- No manual registration needed - just tag a handler and DI wires it

### 2. EventContext as Shared Data Bus
- All handlers access same EventContext instance
- Handlers can read input data and write results
- Orchestrator retrieves results after dispatch

### 3. Handler Chain with Event Propagation
- One handler can dispatch new events
- Multiple handlers can execute for same event
- Events propagate unless explicitly stopped

### 4. Service Container Bridge
- ServiceContainer trait provides runtime DI access
- Controllers retrieve services via ContainerFactory
- Supports unit test mocking via serviceArray

### 5. Type Safety
- HandlerInterface enforces static `getHandledEventClass()` method
- EventDispatcher uses class name for listener lookup
- Each event class is self-documenting about its handlers

---

## Related Files

| File | Purpose |
|------|---------|
| `services.yaml` | DI configuration with tagged_iterator |
| `src/Component/Controller/Core/OrderController.php` | Entry point for checkout |
| `src/Component/Service/CheckoutOrchestrator.php` | Coordinates checkout, dispatches events |
| `src/Component/EventSystem/EventDispatcher.php` | Dispatches events to listeners |
| `src/Component/EventSystem/EventListenerProvider.php` | Stores and retrieves listeners |
| `src/Component/EventSystem/Handler/ContractCreationHandler.php` | Creates contract on PaymentInitiatedEvent |
| `src/Component/Traits/ServiceContainer.php` | Bridge to DI container |
