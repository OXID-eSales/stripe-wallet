# Event-Driven Architecture for Standard Checkout

**Complete Guide to Event-Based Payment Processing**
**Version:** 2.0.0
**Date:** 2025-11-24

---

## Overview

The payment component uses an **event-driven architecture** where business logic is decoupled from controllers through domain events. Controllers are thin validation layers that emit events, while event handlers contain the actual business logic.

**Key Principle:** Data flows through events, not direct method calls.

---

## Architecture Layers

```
┌─────────────────────────────────────────────────────────────┐
│                  PRESENTATION LAYER                          │
│              (Controllers - Thin & Fast)                     │
│                                                               │
│  Controllers validate input, enforce security, emit events   │
│  ⚡ NO BUSINESS LOGIC                                        │
└────────────────────┬────────────────────────────────────────┘
                     │ emits events
                     ▼
┌─────────────────────────────────────────────────────────────┐
│                      EVENT LAYER                             │
│                  (Event Dispatcher)                          │
│                                                               │
│  • PaymentInitiatedEvent                                     │
│  • PaymentConfirmedEvent                                     │
│  • PaymentCapturedEvent                                      │
│  • OrderCreatedEvent                                         │
│  • WebhookReceivedEvent                                      │
└───────┬─────────────────────────────────────────────┬───────┘
        │ triggers                          triggers  │
        ▼                                              ▼
┌──────────────────────────┐          ┌─────────────────────────┐
│   EVENT HANDLERS         │          │    SUBSCRIBERS          │
│   (Business Logic)       │          │    (Side Effects)       │
│                          │          │                         │
│  • PaymentHandler        │          │  • EmailSubscriber      │
│  • OrderCreationHandler  │          │  • LoggingSubscriber    │
│  • WebhookHandler        │          │  • AnalyticsSubscriber  │
└────────┬─────────────────┘          └──────────┬──────────────┘
         │ uses                                   │ uses
         ▼                                        ▼
┌─────────────────────────────────────────────────────────────┐
│                    SERVICE LAYER                             │
│              (Reusable Business Logic)                       │
│                                                               │
│  • PaymentAdapterFactory                                     │
│  • StripeAdapter (PaymentAdapterInterface)                   │
│  • TransactionService                                        │
└────────────────────┬────────────────────────────────────────┘
                     │ persists
                     ▼
┌─────────────────────────────────────────────────────────────┐
│                  DATA ACCESS LAYER                           │
│                   (Repositories)                             │
└────────────────────┬────────────────────────────────────────┘
                     │
                     ▼
┌─────────────────────────────────────────────────────────────┐
│                     DATABASE                                 │
└─────────────────────────────────────────────────────────────┘
```

---

## Core Concepts

### 1. Controllers Emit Events (No Business Logic)

**❌ Traditional Approach (BAD):**
```php
class OrderController
{
    public function execute()
    {
        // Validate
        $basket = $this->getBasket();

        // Create payment (business logic in controller!)
        $paymentIntent = $this->stripe->paymentIntents->create([...]);

        // Create order (business logic in controller!)
        $order = $this->createOrder();

        // Update status (business logic in controller!)
        $this->updateOrderStatus($order);

        // Send email (business logic in controller!)
        $this->sendEmail($order);

        // 100+ lines of business logic mixed with HTTP handling!
    }
}
```

**✅ Event-Driven Approach (GOOD):**
```php
class OrderController
{
    public function execute()
    {
        // 1. Validate input (only validation!)
        $basket = $this->validateBasket();
        $user = $this->validateUser();

        // 2. Create event context (cache data)
        $context = new EventContext([
            'basket' => $basket,
            'user' => $user,
        ]);

        // 3. Emit event (delegate to handlers)
        $event = new PaymentInitiatedEvent($context);
        $this->dispatcher->dispatch($event);

        // 4. Return response (10 lines total!)
        return $this->json([
            'success' => true,
            'redirectUrl' => $event->getRedirectUrl(),
        ]);
    }
}
```

**Benefits:**
- Controller is 10 lines instead of 100+
- Business logic moved to event handlers
- Easy to test (mock event dispatcher)
- Easy to extend (add new event listeners)

---

### 2. Event Handlers Contain Business Logic

**Event Handler Example:**
```php
class PaymentInitiatedEventHandler
{
    private StripePaymentService $paymentService;
    private TransactionService $transactionService;

    public function handle(PaymentInitiatedEvent $event): void
    {
        // Get cached data from event context
        $basket = $event->getContext()->getBasket();
        $user = $event->getContext()->getUser();

        // Business logic here!
        $paymentIntent = $this->paymentService->createPaymentIntent(
            $basket,
            $user
        );

        // Store transaction
        $this->transactionService->createTransaction([
            'provider_order_id' => $paymentIntent['id'],
            'amount' => $basket->getTotal(),
            'status' => 'pending',
        ]);

        // Emit next event (cascading)
        $event->setPaymentIntentId($paymentIntent['id']);
        $event->setRedirectUrl($paymentIntent['client_secret']);
    }
}
```

---

### 3. Multiple Subscribers Can Listen to Same Event

**Pattern:**
```php
// Email subscriber
class EmailSubscriber
{
    public function onPaymentCaptured(PaymentCapturedEvent $event): void
    {
        $order = $event->getOrder();
        $this->sendConfirmationEmail($order);
    }
}

// Analytics subscriber
class AnalyticsSubscriber
{
    public function onPaymentCaptured(PaymentCapturedEvent $event): void
    {
        $order = $event->getOrder();
        $this->trackConversion($order);
    }
}

// Inventory subscriber
class InventorySubscriber
{
    public function onPaymentCaptured(PaymentCapturedEvent $event): void
    {
        $order = $event->getOrder();
        $this->decrementStock($order);
    }
}

// All three subscribers react to same event!
```

**Adding New Feature:** Just add a new subscriber, no need to modify existing code!

---

## Payment Flow Events

### Complete Event Chain

```
User clicks "Place Order"
         │
         ▼
┌─────────────────────────────────┐
│  PaymentInitiatedEvent          │
│  Emitted by: OrderController    │
└────────┬────────────────────────┘
         │ triggers
         ▼
┌─────────────────────────────────┐
│  PaymentInitiatedEventHandler   │
│  • Creates PaymentIntent         │
│  • Stores transaction            │
│  • Returns client_secret         │
└────────┬────────────────────────┘
         │ emits
         ▼
┌─────────────────────────────────┐
│  PaymentMethodCreatedEvent      │
│  Emitted by: Handler             │
└────────┬────────────────────────┘
         │
         ▼
[User completes 3DS in browser]
         │
         ▼
┌─────────────────────────────────┐
│  PaymentConfirmedEvent          │
│  Emitted by: OrderController    │
└────────┬────────────────────────┘
         │ triggers
         ▼
┌─────────────────────────────────┐
│  PaymentConfirmedEventHandler   │
│  • Confirms PaymentIntent        │
│  • Checks status                 │
│  • Creates order if succeeded    │
└────────┬────────────────────────┘
         │ emits
         ▼
┌─────────────────────────────────┐
│  OrderCreatedEvent              │
│  Emitted by: Handler             │
└────────┬────────────────────────┘
         │ triggers multiple subscribers
         ├─────────┬─────────┬──────────┐
         ▼         ▼         ▼          ▼
    [Email]  [Inventory] [Analytics] [Accounting]
         │         │         │          │
         └─────────┴─────────┴──────────┘

[Background: Stripe sends webhook]
         │
         ▼
┌─────────────────────────────────┐
│  WebhookReceivedEvent           │
│  Emitted by: WebhookController  │
└────────┬────────────────────────┘
         │ triggers
         ▼
┌─────────────────────────────────┐
│  WebhookProcessingHandler       │
│  • Validates signature           │
│  • Routes to specific handler    │
└────────┬────────────────────────┘
         │ emits
         ▼
┌─────────────────────────────────┐
│  PaymentCapturedEvent           │
│  Emitted by: Handler             │
└────────┬────────────────────────┘
         │ triggers multiple subscribers
         ├─────────┬─────────┬──────────┐
         ▼         ▼         ▼          ▼
  [OrderStatus] [Email]  [Fulfillment] [Refund]
```

---

## Event Definitions

### 1. PaymentInitiatedEvent

**When:** User clicks "Place Order" button
**Emitted By:** OrderController
**Listeners:** PaymentInitiatedEventHandler

```php
namespace OxidSolutionCatalysts\Stripe\Event;

use OxidEsales\Eshop\Application\Model\Basket;
use OxidEsales\Eshop\Application\Model\User;

/**
 * Emitted when user initiates payment
 */
class PaymentInitiatedEvent
{
    private EventContext $context;
    private ?string $paymentIntentId = null;
    private ?string $clientSecret = null;
    private ?string $redirectUrl = null;

    public function __construct(EventContext $context)
    {
        $this->context = $context;
    }

    // Getters
    public function getContext(): EventContext
    {
        return $this->context;
    }

    public function getBasket(): Basket
    {
        return $this->context->getBasket();
    }

    public function getUser(): User
    {
        return $this->context->getUser();
    }

    // Setters (handlers set results)
    public function setPaymentIntentId(string $id): void
    {
        $this->paymentIntentId = $id;
    }

    public function setClientSecret(string $secret): void
    {
        $this->clientSecret = $secret;
    }

    public function setRedirectUrl(string $url): void
    {
        $this->redirectUrl = $url;
    }

    // Result getters
    public function getPaymentIntentId(): ?string
    {
        return $this->paymentIntentId;
    }

    public function getClientSecret(): ?string
    {
        return $this->clientSecret;
    }

    public function getRedirectUrl(): ?string
    {
        return $this->redirectUrl;
    }
}
```

---

### 2. PaymentConfirmedEvent

**When:** Payment method confirmed (after 3DS if required)
**Emitted By:** OrderController (after confirmation)
**Listeners:** PaymentConfirmedEventHandler

```php
namespace OxidSolutionCatalysts\Stripe\Event;

/**
 * Emitted when payment is confirmed
 */
class PaymentConfirmedEvent
{
    private string $paymentIntentId;
    private ?Order $order = null;
    private string $status;

    public function __construct(string $paymentIntentId)
    {
        $this->paymentIntentId = $paymentIntentId;
    }

    public function getPaymentIntentId(): string
    {
        return $this->paymentIntentId;
    }

    public function setOrder(Order $order): void
    {
        $this->order = $order;
    }

    public function getOrder(): ?Order
    {
        return $this->order;
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function isSuccessful(): bool
    {
        return $this->status === 'succeeded';
    }
}
```

---

### 3. OrderCreatedEvent

**When:** Order successfully created in OXID
**Emitted By:** PaymentConfirmedEventHandler
**Listeners:** EmailSubscriber, InventorySubscriber, AnalyticsSubscriber

```php
namespace OxidSolutionCatalysts\Stripe\Event;

use OxidEsales\Eshop\Application\Model\Order;

/**
 * Emitted when order is created
 */
class OrderCreatedEvent
{
    private Order $order;
    private string $paymentIntentId;

    public function __construct(Order $order, string $paymentIntentId)
    {
        $this->order = $order;
        $this->paymentIntentId = $paymentIntentId;
    }

    public function getOrder(): Order
    {
        return $this->order;
    }

    public function getOrderId(): string
    {
        return $this->order->getId();
    }

    public function getOrderNumber(): string
    {
        return $this->order->getFieldData('oxordernr');
    }

    public function getPaymentIntentId(): string
    {
        return $this->paymentIntentId;
    }

    public function getTotal(): float
    {
        return (float) $this->order->getFieldData('oxtotalordersum');
    }

    public function getCustomerEmail(): string
    {
        return $this->order->getFieldData('oxbillemail');
    }
}
```

---

### 4. PaymentCapturedEvent

**When:** Payment successfully captured (from webhook)
**Emitted By:** WebhookProcessingHandler
**Listeners:** OrderStatusSubscriber, FulfillmentSubscriber, EmailSubscriber

```php
namespace OxidSolutionCatalysts\Stripe\Event;

/**
 * Emitted when payment is captured
 */
class PaymentCapturedEvent
{
    private string $paymentIntentId;
    private string $orderId;
    private float $amount;
    private string $currency;
    private array $metadata;

    public function __construct(
        string $paymentIntentId,
        string $orderId,
        float $amount,
        string $currency,
        array $metadata = []
    ) {
        $this->paymentIntentId = $paymentIntentId;
        $this->orderId = $orderId;
        $this->amount = $amount;
        $this->currency = $currency;
        $this->metadata = $metadata;
    }

    public function getPaymentIntentId(): string
    {
        return $this->paymentIntentId;
    }

    public function getOrderId(): string
    {
        return $this->orderId;
    }

    public function getAmount(): float
    {
        return $this->amount;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }
}
```

---

### 5. WebhookReceivedEvent

**When:** Webhook received from Stripe
**Emitted By:** WebhookController
**Listeners:** WebhookProcessingHandler

```php
namespace OxidSolutionCatalysts\Stripe\Event;

use Stripe\Event as StripeEvent;

/**
 * Emitted when webhook is received
 */
class WebhookReceivedEvent
{
    private StripeEvent $stripeEvent;
    private string $payload;
    private bool $processed = false;

    public function __construct(StripeEvent $stripeEvent, string $payload)
    {
        $this->stripeEvent = $stripeEvent;
        $this->payload = $payload;
    }

    public function getStripeEvent(): StripeEvent
    {
        return $this->stripeEvent;
    }

    public function getEventType(): string
    {
        return $this->stripeEvent->type;
    }

    public function getEventId(): string
    {
        return $this->stripeEvent->id;
    }

    public function getPayload(): string
    {
        return $this->payload;
    }

    public function getData(): array
    {
        return $this->stripeEvent->data->object->toArray();
    }

    public function markAsProcessed(): void
    {
        $this->processed = true;
    }

    public function isProcessed(): bool
    {
        return $this->processed;
    }
}
```

---

## Event Handlers Implementation

### PaymentInitiatedEventHandler

**Full Implementation:**

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Stripe\EventHandler;

use OxidSolutionCatalysts\Stripe\Event\PaymentInitiatedEvent;
use OxidSolutionCatalysts\Stripe\Service\StripePaymentService;
use OxidSolutionCatalysts\Stripe\Service\TransactionService;
use OxidEsales\Eshop\Core\Registry;

/**
 * Handles PaymentInitiatedEvent
 * Creates Stripe PaymentIntent and stores transaction
 */
class PaymentInitiatedEventHandler
{
    private StripePaymentService $paymentService;
    private TransactionService $transactionService;

    public function __construct(
        StripePaymentService $paymentService,
        TransactionService $transactionService
    ) {
        $this->paymentService = $paymentService;
        $this->transactionService = $transactionService;
    }

    /**
     * Handle payment initiation
     */
    public function handle(PaymentInitiatedEvent $event): void
    {
        try {
            // Get data from event context (cached, no DB queries!)
            $basket = $event->getBasket();
            $user = $event->getUser();

            Registry::getLogger()->info('Processing PaymentInitiatedEvent', [
                'user_id' => $user->getId(),
                'basket_total' => $basket->getPrice()->getBruttoPrice(),
            ]);

            // Create PaymentIntent via service
            $paymentIntent = $this->paymentService->createPaymentIntent($basket, $user);

            // Store transaction record
            $this->transactionService->createTransaction([
                'user_id' => $user->getId(),
                'provider_order_id' => $paymentIntent['id'],
                'amount' => $basket->getPrice()->getBruttoPrice(),
                'currency' => $basket->getBasketCurrency()->name,
                'status' => 'pending',
            ]);

            // Set result in event (controller will access this)
            $event->setPaymentIntentId($paymentIntent['id']);
            $event->setClientSecret($paymentIntent['client_secret']);

            Registry::getLogger()->info('PaymentIntent created successfully', [
                'payment_intent_id' => $paymentIntent['id'],
            ]);

        } catch (\Exception $e) {
            Registry::getLogger()->error('PaymentInitiatedEvent handling failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e; // Re-throw for controller to handle
        }
    }
}
```

---

### OrderCreatedEventHandler

**Full Implementation:**

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Stripe\EventHandler;

use OxidSolutionCatalysts\Stripe\Event\OrderCreatedEvent;
use OxidEsales\Eshop\Core\Registry;

/**
 * Handles OrderCreatedEvent
 * Triggers side effects: email, analytics, etc.
 */
class OrderCreatedEventHandler
{
    /**
     * Handle order creation
     * This is a lightweight handler that just emits more specific events
     */
    public function handle(OrderCreatedEvent $event): void
    {
        $order = $event->getOrder();

        Registry::getLogger()->info('Order created', [
            'order_id' => $order->getId(),
            'order_number' => $event->getOrderNumber(),
            'total' => $event->getTotal(),
        ]);

        // Event will trigger multiple subscribers:
        // - EmailSubscriber (sends confirmation)
        // - AnalyticsSubscriber (tracks conversion)
        // - InventorySubscriber (decrements stock)
        // - AccountingSubscriber (records transaction)

        // Handler does nothing - subscribers do the work!
    }
}
```

---

## Event Subscribers (Side Effects)

### EmailSubscriber

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Stripe\Subscriber;

use OxidSolutionCatalysts\Stripe\Event\OrderCreatedEvent;
use OxidSolutionCatalysts\Stripe\Event\PaymentCapturedEvent;
use OxidEsales\Eshop\Core\Registry;
use OxidEsales\Eshop\Core\Email;

/**
 * Sends emails when events occur
 */
class EmailSubscriber
{
    private Email $email;

    public function __construct(Email $email)
    {
        $this->email = $email;
    }

    /**
     * Subscribe to events
     */
    public static function getSubscribedEvents(): array
    {
        return [
            OrderCreatedEvent::class => 'onOrderCreated',
            PaymentCapturedEvent::class => 'onPaymentCaptured',
        ];
    }

    /**
     * Send order confirmation email
     */
    public function onOrderCreated(OrderCreatedEvent $event): void
    {
        $order = $event->getOrder();

        Registry::getLogger()->info('Sending order confirmation email', [
            'order_id' => $order->getId(),
            'email' => $event->getCustomerEmail(),
        ]);

        $this->email->sendOrderEmailToUser($order);
    }

    /**
     * Send payment captured notification
     */
    public function onPaymentCaptured(PaymentCapturedEvent $event): void
    {
        Registry::getLogger()->info('Payment captured, notifying customer', [
            'order_id' => $event->getOrderId(),
        ]);

        // Send payment captured email if needed
    }
}
```

---

### AnalyticsSubscriber

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Stripe\Subscriber;

use OxidSolutionCatalysts\Stripe\Event\OrderCreatedEvent;
use OxidSolutionCatalysts\Stripe\Event\PaymentCapturedEvent;

/**
 * Tracks analytics events
 */
class AnalyticsSubscriber
{
    public static function getSubscribedEvents(): array
    {
        return [
            OrderCreatedEvent::class => 'onOrderCreated',
            PaymentCapturedEvent::class => 'onPaymentCaptured',
        ];
    }

    public function onOrderCreated(OrderCreatedEvent $event): void
    {
        // Track conversion in Google Analytics
        $this->trackConversion([
            'transaction_id' => $event->getOrderId(),
            'value' => $event->getTotal(),
            'currency' => 'EUR',
        ]);
    }

    public function onPaymentCaptured(PaymentCapturedEvent $event): void
    {
        // Track payment captured
        $this->trackEvent('payment_captured', [
            'order_id' => $event->getOrderId(),
            'amount' => $event->getAmount(),
        ]);
    }

    private function trackConversion(array $data): void
    {
        // Send to Google Analytics, Facebook Pixel, etc.
    }

    private function trackEvent(string $name, array $data): void
    {
        // Send event to analytics platform
    }
}
```

---

## Event Dispatcher Configuration

### Symfony EventDispatcher Setup

```yaml
# config/services.yaml

services:
    # Event Dispatcher
    Symfony\Component\EventDispatcher\EventDispatcher:
        class: Symfony\Component\EventDispatcher\EventDispatcher

    # Event Handlers
    OxidSolutionCatalysts\Stripe\EventHandler\PaymentInitiatedEventHandler:
        tags:
            - { name: event.listener, event: OxidSolutionCatalysts\Stripe\Event\PaymentInitiatedEvent }

    OxidSolutionCatalysts\Stripe\EventHandler\OrderCreatedEventHandler:
        tags:
            - { name: event.listener, event: OxidSolutionCatalysts\Stripe\Event\OrderCreatedEvent }

    # Subscribers (auto-register)
    OxidSolutionCatalysts\Stripe\Subscriber\EmailSubscriber:
        tags:
            - { name: event.subscriber }

    OxidSolutionCatalysts\Stripe\Subscriber\AnalyticsSubscriber:
        tags:
            - { name: event.subscriber }

    OxidSolutionCatalysts\Stripe\Subscriber\InventorySubscriber:
        tags:
            - { name: event.subscriber }
```

---

## Benefits of Event-Driven Architecture

### 1. Separation of Concerns

```
Controller: HTTP request/response
   ↓ emits event
Handler: Business logic
   ↓ emits event
Subscriber: Side effects (email, analytics)
```

Each layer has ONE job.

---

### 2. Easy to Extend

**Add new feature without touching existing code:**

```php
// Just add new subscriber!
class SmsNotificationSubscriber
{
    public static function getSubscribedEvents(): array
    {
        return [
            OrderCreatedEvent::class => 'onOrderCreated',
        ];
    }

    public function onOrderCreated(OrderCreatedEvent $event): void
    {
        $this->sendSms($event->getCustomerPhone(), 'Order confirmed!');
    }
}
```

No changes needed in:
- ❌ Controllers
- ❌ Event handlers
- ❌ Other subscribers

Just register the new subscriber and it works!

---

### 3. Easy to Test

**Mock the event dispatcher:**

```php
class OrderControllerTest extends TestCase
{
    public function testExecute(): void
    {
        // Mock event dispatcher
        $dispatcher = $this->createMock(EventDispatcher::class);

        // Assert correct event is dispatched
        $dispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(PaymentInitiatedEvent::class));

        $controller = new OrderController($dispatcher);
        $controller->execute();
    }
}
```

**Test handlers in isolation:**

```php
class PaymentInitiatedEventHandlerTest extends TestCase
{
    public function testHandle(): void
    {
        // Create event
        $event = new PaymentInitiatedEvent($context);

        // Handle
        $handler = new PaymentInitiatedEventHandler(...);
        $handler->handle($event);

        // Assert result
        $this->assertNotNull($event->getPaymentIntentId());
    }
}
```

---

### 4. Async Processing Ready

Events can be processed asynchronously via message queue:

```php
// Synchronous (immediate)
$dispatcher->dispatch($event);

// Asynchronous (queue)
$messageQueue->push($event);
// Processed by worker later
```

---

## Complete Example: Payment Flow

### 1. Controller Emits Event

```php
// OrderController.php
public function execute(): Response
{
    // Validate
    $basket = $this->validateBasket();
    $user = $this->validateUser();

    // Create context
    $context = new EventContext([
        'basket' => $basket,
        'user' => $user,
    ]);

    // Emit event
    $event = new PaymentInitiatedEvent($context);
    $this->dispatcher->dispatch($event);

    // Return response
    return $this->json([
        'client_secret' => $event->getClientSecret(),
    ]);
}
```

---

### 2. Handler Processes Event

```php
// PaymentInitiatedEventHandler.php
public function handle(PaymentInitiatedEvent $event): void
{
    $basket = $event->getBasket();
    $user = $event->getUser();

    // Business logic
    $paymentIntent = $this->paymentService->createPaymentIntent($basket, $user);

    // Set result
    $event->setPaymentIntentId($paymentIntent['id']);
    $event->setClientSecret($paymentIntent['client_secret']);
}
```

---

### 3. Multiple Subscribers React

```php
// EmailSubscriber.php
public function onOrderCreated(OrderCreatedEvent $event): void
{
    $this->sendEmail($event->getOrder());
}

// AnalyticsSubscriber.php
public function onOrderCreated(OrderCreatedEvent $event): void
{
    $this->trackConversion($event->getOrder());
}

// InventorySubscriber.php
public function onOrderCreated(OrderCreatedEvent $event): void
{
    $this->decrementStock($event->getOrder());
}
```

All triggered by ONE event!

---

## Summary

### Key Principles

1. **Controllers are thin** - Only validation and event emission
2. **Handlers contain business logic** - Reusable, testable
3. **Subscribers handle side effects** - Email, logging, analytics
4. **Events carry data** - No database queries in subscribers
5. **Loose coupling** - Components don't know about each other

### Architecture Benefits

- ✅ **Maintainable** - Each class has one responsibility
- ✅ **Testable** - Easy to mock and isolate
- ✅ **Extensible** - Add features without modifying existing code
- ✅ **Scalable** - Events can be queued for async processing
- ✅ **Debuggable** - Clear flow of events through system

---

## Next Steps

1. Read [CONTROLLER_INTEGRATION.md](CONTROLLER_INTEGRATION.md) for controller implementation
2. Read [SERVICE_LAYER.md](SERVICE_LAYER.md) for service implementation
3. Read [WEBHOOK_HANDLING.md](WEBHOOK_HANDLING.md) for webhook events

