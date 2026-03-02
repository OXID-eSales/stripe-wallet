# Event System Architecture

**Date:** 2026-02-04
**Based on:** Actual code analysis

---

## Overview

The payment system uses a PSR-14 compatible event-driven architecture where:
- Controllers emit events (thin controllers)
- Handlers subscribe to events and execute business logic
- Events carry context data between handlers

---

## Event Dispatcher

**Location:** `payment-component/src/EventSystem/EventDispatcher.php`

```php
interface EventDispatcherInterface
{
    public function dispatch(EventInterface $event): EventInterface;
    public function addListener(string $eventClass, callable $listener, int $priority = 0): void;
}
```

**Features:**
- Priority-based handler execution (higher first)
- Event propagation control (stopPropagation)
- Context passing between handlers

---

## Event Hierarchy

### Base Interfaces (payment-component)

```
EventInterface
├── ContractEventInterface
│   ├── ContractCreatedEventInterface
│   ├── ContractTransitionedToPendingEventInterface
│   ├── ContractDraftCompletedEventInterface
│   ├── ContractConditionFulfilledEventInterface
│   ├── ContractReadyToCommitEventInterface
│   ├── ContractCommittedEventInterface
│   ├── ContractFulfilledEventInterface
│   ├── ContractTerminatedEventInterface
│   │   ├── ContractCancelledEventInterface
│   │   ├── ContractExpiredEventInterface
│   │   └── ContractFailedEventInterface
│
└── PaymentEventInterface
    ├── PaymentInitiatedEventInterface
    ├── PaymentAuthorizedEventInterface
    ├── PaymentCapturedEventInterface
    ├── PaymentFailedEventInterface
    ├── PaymentRefundedEventInterface
    ├── OrderCreatedEventInterface
    ├── OrderCompletedEventInterface
    └── WebhookReceivedEventInterface
```

### Stripe-Specific Events

```
EventInterface
├── StripeCheckoutSessionRequestEvent
├── StripeCheckoutReturnEvent
├── StripePaymentExecuteEvent
├── StripePaymentReturnEvent
├── StripeCaptureRequestEvent
├── StripeCancelAuthorizationRequestEvent
├── StripeRefundRequestEvent
└── Stripe3DSRequiredEvent
```

---

## Event Context

Events carry an `EventContext` object for passing data between handlers:

```php
interface EventContextInterface
{
    public function get(string $key, mixed $default = null): mixed;
    public function set(string $key, mixed $value): void;
    public function has(string $key): bool;
    public function all(): array;
}
```

**Common Context Keys:**
- `contractId` - PaymentContract ID
- `orderId` - OXID order ID
- `userId` - Customer user ID
- `redirectUrl` - URL for redirects
- `provider` - Payment provider name
- `providerOrderId` - Provider's payment/order ID

---

## Handler Interface

All handlers implement:

```php
interface HandlerInterface
{
    public function handle(EventInterface $event): void;
    public function getHandledEventClass(): string;
}
```

**Base Class:** `AbstractHandler`
```php
abstract class AbstractHandler implements HandlerInterface
{
    public function __construct(
        protected ContractRepositoryInterface $contractRepository,
        protected EventDispatcherInterface $eventDispatcher
    ) {}
}
```

---

## Handler Registration

Handlers are registered via dependency injection with tags:

```yaml
# services.yaml
Stripe\EventSystem\Handler\StripeContractCreationHandler:
    tags:
        - { name: 'payment.event_handler', priority: 100 }

Stripe\EventSystem\Handler\StripeCheckoutSessionHandler:
    tags:
        - { name: 'payment.event_handler', priority: 0 }
```

**Priority Rules:**
- Higher priority executes first
- Same priority: registration order
- Contract creation handlers: priority 100
- Order creation handlers: priority 80
- Standard handlers: default (0)

---

## Event Handlers by Module

### payment-component Handlers

| Handler | Event | Purpose |
|---------|-------|---------|
| ContractCreationHandler | ContractCreatedEvent | Base contract creation (abstract) |
| **EarlyOrderCreationHandler** | **ContractDraftCompletedEvent** | **Creates OXID order early, stores order_number in metadata** |
| ContractConditionResolverHandler | ContractConditionFulfilledEvent | Checks all conditions, transitions state |
| ContractCleanupHandler | Timer/Cron | Expires stale contracts |
| FraudCheckHandler | PaymentAuthorizedEvent | Executes fraud checks |
| OrderPaymentCompletedHandler | ContractFulfilledEvent | Updates OXPAID timestamp |
| PaymentAuthorizationHandler | PaymentAuthorizedEvent | Handles authorization flow |
| PaymentAuthorizedEventHandler | PaymentAuthorizedEvent | Fulfills payment_authorized condition |
| GenericContractCreationHandler | ContractCreatedEvent | Generic contract setup |

**Note:** `EarlyOrderCreationHandler` is critical for the Stripe integration - it creates the order before the Stripe Checkout Session is created, allowing the order number to be sent to Stripe in the PaymentIntent metadata.

### stripe Handlers

| Handler | Event | Priority | Purpose |
|---------|-------|----------|---------|
| StripeContractCreationHandler | StripeCheckoutSessionRequestEvent | 100 | Creates contract with Stripe metadata |
| StripeCheckoutSessionHandler | StripeCheckoutSessionRequestEvent | 0 | Creates Checkout Session |
| StripeCheckoutReturnHandler | StripeCheckoutReturnEvent | 100 | Validates return from Checkout |
| StripeOrderCreationHandler | ContractReadyToCommitEvent | 80 | Creates OXID order |
| StripePaymentStatusHandler | StripePaymentExecuteEvent | - | Verifies Payment Element status |
| StripePaymentReturnHandler | StripePaymentReturnEvent | - | Handles Payment Element return |
| StripeCaptureRequestHandler | StripeCaptureRequestEvent | - | Manual capture from admin |
| StripeCancelAuthorizationRequestHandler | StripeCancelAuthorizationRequestEvent | - | Cancel authorization |
| StripeRefundRequestHandler | StripeRefundRequestEvent | - | Process refunds |

---

## Event Flow Diagrams

### Checkout Session Flow (with Early Order Creation)

```
User clicks "Checkout with Stripe"
         │
         ▼
┌─────────────────────────────────────┐
│ StripeOrderController               │
│ dispatch(StripeCheckoutSessionReq)  │
└─────────────────────────────────────┘
         │
         ▼ priority: 100
┌─────────────────────────────────────┐
│ StripeContractCreationHandler       │
│ - Create PaymentContract (DRAFT)    │
│ - Add conditions                    │
│ - Save to repository                │
│ - Set context['contractId']         │
│ - dispatch(ContractDraftCompleted)  │
└─────────────────────────────────────┘
         │
         ▼ triggered by ContractDraftCompletedEvent
┌─────────────────────────────────────┐
│ EarlyOrderCreationHandler           │
│ - Create OXID order                 │
│ - Store order_number in metadata    │
│ - DRAFT → NOT_FINISHED → PENDING    │
│ - Save contract with orderId        │
└─────────────────────────────────────┘
         │
         ▼ priority: 0
┌─────────────────────────────────────┐
│ StripeCheckoutSessionHandler        │
│ - Get contractId from context       │
│ - Get orderId and order_number      │
│ - Call CheckoutSessionService       │
│   (includes order_number in Stripe) │
│ - Set context['redirectUrl']        │
└─────────────────────────────────────┘
         │
         ▼
┌─────────────────────────────────────┐
│ Controller reads context            │
│ - Redirect to context['redirectUrl']│
│ (Stripe shows order_number)         │
└─────────────────────────────────────┘
```

### Webhook Payment Success Flow

```
Stripe webhook: payment_intent.succeeded
         │
         ▼
┌─────────────────────────────────────┐
│ WebhookController                   │
│ - Receive POST                      │
│ - Call StripeWebhookProcessor       │
└─────────────────────────────────────┘
         │
         ▼
┌─────────────────────────────────────┐
│ StripeWebhookProcessor              │
│ - Verify signature                  │
│ - Check idempotency                 │
│ - Route to handler                  │
└─────────────────────────────────────┘
         │
         ▼
┌─────────────────────────────────────┐
│ WebhookContractFulfillmentHandler   │
│ handlePaymentSucceeded():           │
│ - Find contract by providerOrderId  │
│ - Fulfill payment_authorized        │
│ - Dispatch ConditionFulfilledEvent  │
└─────────────────────────────────────┘
         │
         ▼
┌─────────────────────────────────────┐
│ ContractConditionResolverHandler    │
│ - Check all conditions              │
│ - Transition to READY_TO_COMMIT     │
│ - Dispatch ReadyToCommitEvent       │
└─────────────────────────────────────┘
         │
         ▼ priority: 80
┌─────────────────────────────────────┐
│ StripeOrderCreationHandler          │
│ - Detect existing orderId           │
│   (created by EarlyOrderCreation)   │
│ - SKIP order creation               │
│ - Update OXTRANSID with PaymentIntent│
│ - Set OXPAID timestamp              │
│ - Transition to COMMITTED           │
│ - Call fulfillment service          │
└─────────────────────────────────────┘
         │
         ▼
┌─────────────────────────────────────┐
│ ContractFulfillmentService          │
│ - Transition to FULFILLED           │
│ - Dispatch FulfilledEvent           │
└─────────────────────────────────────┘
         │
         ▼
┌─────────────────────────────────────┐
│ OrderPaymentCompletedHandler        │
│ - Update OXPAID on order (if needed)│
└─────────────────────────────────────┘
```

---

## Template Method Pattern in Handlers

**StripeContractCreationHandler extends ContractCreationHandler:**

```php
// payment-component
abstract class ContractCreationHandler extends AbstractHandler
{
    public function handle(EventInterface $event): void
    {
        $contract = $this->createContract($event);
        $this->addConditions($contract);
        $this->contractRepository->save($contract);
        $this->afterContractCreated($contract, $event);  // Template method
        $this->dispatchContractEvent($contract);         // Template method
    }

    abstract protected function afterContractCreated(
        PaymentContractInterface $contract,
        EventInterface $event
    ): void;

    abstract protected function dispatchContractEvent(
        PaymentContractInterface $contract
    ): void;
}

// stripe
class StripeContractCreationHandler extends ContractCreationHandler
{
    protected function afterContractCreated(
        PaymentContractInterface $contract,
        EventInterface $event
    ): void {
        // Store Stripe-specific metadata
        $contract->setMetadata('payment_method', $this->getPaymentMethod($event));
        $event->getContext()->set('contractId', $contract->getId());
    }

    protected function dispatchContractEvent(
        PaymentContractInterface $contract
    ): void {
        // Dispatch Stripe-specific events if needed
    }
}
```

---

## Event Context Best Practices

1. **Set early, read late:** Handlers with higher priority set context values
2. **Use consistent keys:** contractId, orderId, userId are standard
3. **Don't modify events:** Use context for communication
4. **Check before reading:** Use `$context->has()` before `$context->get()`

```php
// Handler A (priority 100)
public function handle(EventInterface $event): void
{
    $contract = $this->createContract();
    $event->getContext()->set('contractId', $contract->getId());
}

// Handler B (priority 0)
public function handle(EventInterface $event): void
{
    $contractId = $event->getContext()->get('contractId');
    if (!$contractId) {
        throw new \RuntimeException('Contract not created');
    }
    // Use contractId...
}
```
