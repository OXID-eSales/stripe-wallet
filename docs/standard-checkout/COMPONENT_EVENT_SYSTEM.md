# Component EventSystem Integration

How standard checkout integrates with the Component EventSystem for event-driven architecture.

## Overview

The standard checkout implementation uses the **Component EventSystem** (`/src/Component/EventSystem`) instead of standalone event classes. This provides:

✅ **Shared Event Architecture** - Consistent events across all payment methods
✅ **EventDispatcher Pattern** - Standard pub/sub event handling
✅ **EventContext** - Shared context across event handlers
✅ **Type Safety** - Interface-based event contracts
✅ **Extensibility** - Easy to add custom event listeners

---

## Architecture

### Component EventSystem Structure

```
/src/Component/EventSystem/
├── EventDispatcher.php                    # Main event dispatcher
├── EventDispatcherInterface.php           # Dispatcher contract
├── Event/
│   ├── EventInterface.php                 # Base event contract
│   ├── EventContext.php                   # Shared context object
│   ├── EventContextInterface.php          # Context contract
│   └── Payment/                           # Payment-related events
│       ├── PaymentInitiatedEvent.php
│       ├── PaymentAuthorizedEvent.php
│       ├── PaymentCapturedEvent.php
│       ├── PaymentRefundedEvent.php
│       ├── PaymentFailedEvent.php
│       ├── OrderCreatedEvent.php
│       ├── OrderCompletedEvent.php
│       └── WebhookReceivedEvent.php
└── Handler/                               # Event handlers
    ├── HandlerInterface.php
    └── ... (various handlers)
```

### Event Flow

```
┌─────────────────────────────────────────────────────────────┐
│                    Standard Checkout Flow                    │
└─────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────┐
│ 1. User selects Stripe payment → PaymentController          │
└─────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────┐
│ 2. createPayment() → StripeAdapter via Factory              │
│    ├─ Create PaymentIntent with Stripe API                  │
│    └─ Dispatch: PaymentInitiatedEvent ✨                    │
└─────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────┐
│ 3. Customer enters card → Stripe.js (frontend)              │
│    ├─ 3D Secure if required                                 │
│    └─ Payment confirmed                                      │
└─────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────┐
│ 4. executeStripePayment() → OrderController                 │
│    ├─ Verify PaymentIntent status                           │
│    └─ Call createOrderAfterPayment()                        │
└─────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────┐
│ 5. createOrderAfterPayment() → OrderController              │
│    ├─ ✅ Order::finalizeOrder() (OXID standard method)      │
│    ├─ Store transaction via adapter                          │
│    ├─ Dispatch: OrderCreatedEvent ✨                        │
│    └─ Dispatch: PaymentCapturedEvent ✨                     │
└─────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────┐
│ 6. Webhook received → WebhookController                     │
│    ├─ Verify signature                                       │
│    └─ Route to WebhookProcessingService                     │
└─────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────┐
│ 7. processEvent() → WebhookProcessingService                │
│    ├─ Dispatch: WebhookReceivedEvent ✨                     │
│    ├─ Handle specific event type                            │
│    └─ Update order states                                    │
└─────────────────────────────────────────────────────────────┘
```

---

## Events Used in Standard Checkout

### 1. PaymentInitiatedEvent

**When:** After Stripe PaymentIntent is created
**Location:** `StripeAdapter::createPayment()` or `PaymentController::createPaymentIntent()`
**Purpose:** Notify listeners that payment process has started

**Properties:**
```php
- context: EventContextInterface
- paymentMethodId: string ('osc_stripe_card')
- amount: float
- currency: string
- returnUrl: string
- cancelUrl: string
- providerOrderId: string (PaymentIntent ID)
```

**Usage:**
```php
$event = new PaymentInitiatedEvent(
    context: $context,
    paymentMethodId: 'osc_stripe_card',
    amount: 99.99,
    currency: 'EUR',
    returnUrl: 'https://shop.com/index.php?cl=order&fnc=return3DS',
    cancelUrl: 'https://shop.com/index.php?cl=payment'
);

$event->setProviderOrderId($paymentIntent->id);
$eventDispatcher->dispatch($event);
```

---

### 2. OrderCreatedEvent

**When:** After `Order::finalizeOrder()` succeeds
**Location:** `OrderController::execute()` or similar order creation logic
**Purpose:** Notify listeners that order was created successfully

**Properties:**
```php
- context: EventContextInterface
- orderId: string
- contractId: string (empty for standard checkout)
```

**Usage:**
```php
$event = new OrderCreatedEvent(
    context: $context,
    orderId: $order->getId(),
    contractId: '' // Standard checkout doesn't use contracts
);

$eventDispatcher->dispatch($event);
```

**Note:** Standard checkout passes empty string for `contractId` since contracts are only used in smart contract checkout.

---

### 3. PaymentCapturedEvent

**When:** After payment is captured (during order creation)
**Location:** `StripeAdapter::capturePayment()` or `OrderController::execute()`
**Purpose:** Notify listeners that funds were captured

**Properties:**
```php
- context: EventContextInterface
- authorizationId: string (PaymentIntent ID)
- captureId: string (Charge ID)
- capturedAmount: float
- currency: string
```

**Usage:**
```php
$event = new PaymentCapturedEvent(
    context: $context,
    authorizationId: $paymentIntent->id,
    captureId: $charge->id,
    capturedAmount: 99.99,
    currency: 'EUR'
);

$eventDispatcher->dispatch($event);
```

---

### 4. PaymentRefundedEvent

**When:** After refund is processed
**Location:** `StripeAdapter::refundPayment()`
**Purpose:** Notify listeners that refund was issued

**Properties:**
```php
- context: EventContextInterface
- refundId: string
- refundedAmount: float
- currency: string
- reason: ?string
```

**Usage:**
```php
$event = new PaymentRefundedEvent(
    context: $context,
    refundId: $refund->id,
    refundedAmount: 50.00,
    currency: 'EUR',
    reason: 'requested_by_customer'
);

$eventDispatcher->dispatch($event);
```

---

### 5. WebhookReceivedEvent

**When:** Webhook received from Stripe
**Location:** `WebhookProcessingService::processEvent()`
**Purpose:** Notify listeners that webhook was received

**Properties:**
```php
- context: EventContextInterface
- provider: string ('stripe')
- eventType: string
- payload: array
- signature: string
```

**Usage:**
```php
$event = new WebhookReceivedEvent(
    context: $context,
    provider: 'stripe',
    eventType: 'payment_intent.succeeded',
    payload: $stripeEvent->data->object->toArray(),
    signature: ''
);

$eventDispatcher->dispatch($event);
```

---

## EventContext

All events receive an `EventContext` object that carries shared data across event handlers.

### Standard Properties

```php
$context = new EventContext([
    'basket' => $basket,           // Shopping basket
    'user' => $user,               // Customer user object
    'orderId' => $order->getId(),  // Order ID
    'paymentIntentId' => 'pi_...'  // Stripe PaymentIntent ID
]);
```

### Built-in Methods

```php
// Get values
$basket = $context->getBasket();      // Returns basket object
$user = $context->getUser();          // Returns user object
$orderId = $context->getOrderId();    // Returns order ID string

// Generic get/set
$context->set('custom_key', $value);
$value = $context->get('custom_key', $default);
$exists = $context->has('custom_key');

// Get all data
$allData = $context->all();
```

---

## Service Integration

### StripeAdapter

**Event Dispatcher Injection:**

```php
use OxidSolutionCatalysts\Payments\Component\EventSystem\EventDispatcherInterface;

class StripeAdapter implements PaymentAdapterInterface
{
    private ?EventDispatcherInterface $eventDispatcher;

    public function __construct(
        StripeConfigurationService $config,
        StripeCustomerService $customerService,
        ?EventDispatcherInterface $eventDispatcher = null
    ) {
        $this->eventDispatcher = $eventDispatcher;
        // ...
    }
}
```

**Event Dispatching:**

```php
private function dispatchPaymentInitiatedEvent(...): void
{
    if (!$this->eventDispatcher) {
        return; // Gracefully skip if no dispatcher available
    }

    $context = new EventContext([...]);
    $event = new PaymentInitiatedEvent(...);
    $this->eventDispatcher->dispatch($event);
}
```

### WebhookProcessingService

**Same Pattern:**

```php
class WebhookProcessingService
{
    private ?EventDispatcherInterface $eventDispatcher;

    public function __construct(
        PaymentAdapterFactory $adapterFactory,
        ?EventDispatcherInterface $eventDispatcher = null
    ) {
        $this->eventDispatcher = $eventDispatcher;
    }
}
```

---

## Creating Event Listeners

### Step 1: Create Handler Class

```php
<?php

declare(strict_types=1);

namespace Your\Namespace\Handler;

use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\Payment\OrderCreatedEvent;

class CustomOrderHandler
{
    public function handle(OrderCreatedEvent $event): void
    {
        $orderId = $event->getOrderId();
        $context = $event->getContext();

        // Your custom logic here
        // E.g., send notification, update external system, etc.
    }
}
```

### Step 2: Register Listener

```php
$eventDispatcher = Registry::get(EventDispatcherInterface::class);

$handler = new CustomOrderHandler();

$eventDispatcher->addListener(
    OrderCreatedEvent::class,
    [$handler, 'handle'],
    priority: 100 // Higher = executes first
);
```

### Step 3: Use in Services

Services automatically dispatch events if `EventDispatcher` is injected:

```php
$paymentService = new StripePaymentService(
    $config,
    $customerService,
    $eventDispatcher // ← Inject here
);

// Now all events will be dispatched automatically
$paymentService->createPaymentIntent($basket, $user);
```

---

## Available Event Handlers

The Component EventSystem includes pre-built handlers:

### Payment Handlers

- **PaymentAuthorizationHandler** - Handles payment authorization
- **OrderCreationHandler** - Handles order creation logic
- **StockReservationHandler** - Reserves stock after payment
- **StockReleaseHandler** - Releases stock on failure
- **FraudCheckHandler** - Performs fraud checks

### Contract Handlers (Not used in standard checkout)

- ContractCreationHandler
- ContractFulfillmentHandler
- ContractConditionResolverHandler
- ContractCleanupHandler

---

## Dependency Injection

### Registering Services with EventDispatcher

**In OXID service configuration (e.g., `services.yaml`):**

```yaml
services:
  # EventDispatcher
  OxidSolutionCatalysts\Payments\Component\EventSystem\EventDispatcherInterface:
    class: OxidSolutionCatalysts\Payments\Component\EventSystem\EventDispatcher

  # StripePaymentService with EventDispatcher
  OxidSolutionCatalysts\Stripe\Service\StripePaymentService:
    arguments:
      - '@OxidSolutionCatalysts\Stripe\Service\StripeConfigurationService'
      - '@OxidSolutionCatalysts\Stripe\Service\StripeCustomerService'
      - '@OxidSolutionCatalysts\Payments\Component\EventSystem\EventDispatcherInterface'

  # WebhookProcessingService with EventDispatcher
  OxidSolutionCatalysts\Stripe\Service\WebhookProcessingService:
    arguments:
      - '@OxidSolutionCatalysts\Stripe\Service\StripePaymentService'
      - '@OxidSolutionCatalysts\Payments\Component\EventSystem\EventDispatcherInterface'
```

---

## Event Propagation

Events support propagation stopping (for stoppable events):

```php
public function handle(OrderCreatedEvent $event): void
{
    // Do something

    if ($someCondition) {
        // Stop propagation (if event supports it)
        $event->stopPropagation();
    }
}
```

**Note:** Component events don't have `stopPropagation()` by default, but the dispatcher checks for it.

---

## Testing Events

### Unit Test Example

```php
use PHPUnit\Framework\TestCase;
use OxidSolutionCatalysts\Payments\Component\EventSystem\EventDispatcher;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\Payment\OrderCreatedEvent;

class EventTest extends TestCase
{
    public function testOrderCreatedEventDispatched(): void
    {
        $dispatcher = new EventDispatcher();
        $eventFired = false;

        $dispatcher->addListener(
            OrderCreatedEvent::class,
            function (OrderCreatedEvent $event) use (&$eventFired) {
                $eventFired = true;
                $this->assertNotEmpty($event->getOrderId());
            }
        );

        $context = new EventContext(['orderId' => 'test123']);
        $event = new OrderCreatedEvent($context, 'test123', '');

        $dispatcher->dispatch($event);

        $this->assertTrue($eventFired);
    }
}
```

---

## Benefits of Component EventSystem

### 1. Consistency

All payment methods (Stripe, PayPal, etc.) use the same events:
- `PaymentInitiatedEvent`
- `PaymentCapturedEvent`
- `OrderCreatedEvent`

### 2. Decoupling

Services don't know about event handlers. Handlers register themselves:

```php
// Service just dispatches
$this->eventDispatcher->dispatch($event);

// Handlers listen independently
$dispatcher->addListener(OrderCreatedEvent::class, $handler);
```

### 3. Extensibility

Add custom logic without modifying core code:

```php
// Your module
$eventDispatcher->addListener(
    OrderCreatedEvent::class,
    [$myCustomHandler, 'handle']
);
```

### 4. Testability

Easy to test with mock dispatcher:

```php
$mockDispatcher = $this->createMock(EventDispatcherInterface::class);
$mockDispatcher->expects($this->once())
               ->method('dispatch')
               ->with($this->isInstanceOf(OrderCreatedEvent::class));
```

---

## Migration from Standalone Events

If you have standalone event classes (`/src/Event/*`), migrate to Component events:

### Before (Standalone)

```php
use OxidSolutionCatalysts\Stripe\Event\OrderCreatedEvent;

$event = new OrderCreatedEvent($order, $basket, $user, $paymentIntentId);
// No standard way to dispatch
```

### After (Component)

```php
use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\Payment\OrderCreatedEvent;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\EventContext;

$context = new EventContext([
    'basket' => $basket,
    'user' => $user,
    'orderId' => $order->getId(),
]);

$event = new OrderCreatedEvent($context, $order->getId(), '');
$this->eventDispatcher->dispatch($event);
```

---

## Summary

✅ **Standard checkout uses Component EventSystem**
✅ **Events dispatched: PaymentInitiated, OrderCreated, PaymentCaptured, WebhookReceived**
✅ **EventDispatcher injected via constructor**
✅ **EventContext carries shared data**
✅ **Listeners can be added without modifying services**
✅ **Fully compatible with OXID module system**

---

## Next Steps

1. **Configure services.yaml** to inject EventDispatcher
2. **Create custom handlers** for your business logic
3. **Register listeners** in module Events.php or services.yaml
4. **Test event flow** with unit and integration tests
5. **Monitor events** in production logs

---

## Related Documentation

- [EVENT_DRIVEN_ARCHITECTURE.md](EVENT_DRIVEN_ARCHITECTURE.md) - Conceptual overview
- [SERVICE_LAYER.md](SERVICE_LAYER.md) - Service implementation details
- [CONTROLLER_INTEGRATION.md](CONTROLLER_INTEGRATION.md) - Controller flow
- [WEBHOOK_HANDLING.md](WEBHOOK_HANDLING.md) - Webhook event processing

---

**Last Updated:** 2025-11-13
**Version:** 1.0.0
