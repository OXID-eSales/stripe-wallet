# Module Principles

This document covers the design principles that govern the stripe module. Understanding these is essential before extending or modifying the system.

For the full architectural specification, see [`docs/architecture/00-overview.md`](../architecture/00-overview.md).

---

## 1. Contract-First Mental Model

The stripe module does **not** create orders when the user clicks "Place Order." Instead, it creates a **PaymentContract** — a state machine that tracks the payment lifecycle. The order is created early (during contract draft completion) so that an order number is available before the user is redirected to Stripe.

```
Traditional:  User clicks → Order created → Payment → Order updated
This module:  User clicks → Contract(DRAFT) → Order(NOT_FINISHED) → Stripe session
              → User pays → Contract advances → Order finalized
```

**Why this matters for extension developers:** If you need to hook into the payment flow, you hook into **contract events**, not order events. The contract is the source of truth; the order is a downstream effect.

---

## 2. The Boundary Rule

Every piece of code belongs to exactly one of two modules:

| Question | payment-component | stripe |
|----------|-------------------|--------|
| Is it provider-agnostic? | Yes | No |
| Does it define an interface? | Yes (mostly) | Only Stripe-specific ones |
| Does it own database tables? | Yes (all 6 tables) | No (zero migrations) |
| Does it know about Stripe SDK? | Never | Yes |
| Does it define domain events? | Yes (contract + payment events) | Yes (Stripe-specific events only) |

**Rule:** If code could work with PayPal instead of Stripe, it belongs in payment-component. If it touches `\Stripe\StripeClient` or Stripe API concepts, it belongs in the stripe module.

---

## 3. Contract Lifecycle (Developer Perspective)

Each state transition is a hook point. The markers below show where your extension code can intercept.

```
┌─────────┐
│  DRAFT  │  Contract created, conditions added
└────┬────┘
     │  ← HOOK: ContractDraftCompletedEvent
     │    EarlyOrderCreationHandler creates order (NOT_FINISHED)
     ▼
┌──────────────┐
│ NOT_FINISHED │  Order exists, OXORDERID set on contract
└──────┬───────┘
       │  transitionToPending()
       │  ← HOOK: ContractTransitionedToPendingEvent
       ▼
┌───────────┐
│  PENDING  │  Stripe Checkout Session created, user redirected
└─────┬─────┘
      │  User pays, returns to shop
      │  ← HOOK: PaymentAuthorizedEvent
      ▼
┌────────────┐
│ AUTHORIZED │  (only in manual-capture mode)
└──────┬─────┘
       │  Conditions fulfilled (payment_authorized, fraud_check)
       │  ← HOOK: ContractReadyToCommitEvent
       ▼
┌──────────────────┐
│ READY_TO_COMMIT  │  All conditions met
└────────┬─────────┘
         │  StripeOrderCreationHandler finalizes order
         │  ← HOOK: ContractCommittedEvent
         ▼
┌───────────┐
│ COMMITTED │  Order finalized (OXORDERNR assigned, OXTRANSSTATUS set)
└─────┬─────┘
      │  Payment captured
      │  ← HOOK: ContractFulfilledEvent
      ▼
┌───────────┐
│ FULFILLED │  Terminal state — payment complete
└───────────┘

Alternative terminals: CANCELLED, EXPIRED, FAILED
```

---

## 4. Event-Driven Architecture (Practical Guide)

All business logic runs through event handlers. Controllers are thin — they build an event, dispatch it, and read the result from the event context.

### 4.1 The Handler Contract

Every handler implements `HandlerInterface` from payment-component:

```php
// payment-component/src/EventSystem/Handler/HandlerInterface.php

interface HandlerInterface
{
    public function handle(object $event): void;
    public static function getHandledEventClass(): string;
}
```

Priority is **not** part of the interface. It is resolved optionally:

```php
// EventListenerProvider checks via method_exists()
$priority = method_exists($handler, 'getPriority') ? $handler->getPriority() : 0;
```

Higher priority = runs first. Use `getPriority(): int` on your handler class if you need ordering control.

### 4.2 Registering a Handler

Handlers are registered in `services.yaml` with the `payment.event_handler` tag:

```yaml
# services.yaml
Your\Module\Handler\SubscriptionValidationHandler:
    tags:
        - { name: payment.event_handler, priority: 95 }
```

The `EventListenerProvider` collects all tagged handlers via Symfony's `!tagged_iterator`:

```yaml
OxidEsales\PaymentComponent\EventSystem\EventListenerProviderInterface:
    class: OxidEsales\PaymentComponent\EventSystem\EventListenerProvider
    arguments:
        - !tagged_iterator payment.event_handler
```

### 4.3 Event Context

Events carry an `EventContextInterface` — a request-scoped key-value store that passes data between handlers in the same dispatch chain:

```php
// Reading context in a handler
public function handle(object $event): void
{
    $context = $event->getContext();
    $contract = $context->getContract();      // PaymentContractInterface|null
    $basket = $context->getBasket();          // object|null
    $user = $context->getUser();              // object|null
    $orderId = $context->getOrderId();        // string|null
    $custom = $context->get('myKey');          // mixed

    // Writing to context for downstream handlers
    $context->set('subscriptionId', 'sub_abc123');
}
```

**Important:** Context keys are stringly-typed. Use constants to avoid typos. A misspelled key silently returns `null`.

### 4.4 Dispatching Events

```php
use OxidEsales\PaymentComponent\EventSystem\EventDispatcherInterface;

$event = new SomeEvent($contract, $context);
$this->eventDispatcher->dispatch($event);
// After dispatch, read results from $context
```

---

## 5. Registered Handler Chain

The complete handler chain as registered in `services.yaml`:

### Stripe-Specific Handlers

| Handler | Event | Priority | Purpose |
|---------|-------|----------|---------|
| `StripeContractCreationHandler` | `StripeCheckoutSessionRequestEvent` | 100 | Creates contract, stores metadata, dispatches `ContractDraftCompletedEvent` |
| `StripeCheckoutSessionHandler` | `StripeCheckoutSessionRequestEvent` | 0 | Creates Stripe Checkout Session using contract data |
| `StripeCheckoutReturnHandler` | `StripeCheckoutReturnEvent` | 100 | Processes return from Stripe, dispatches `PaymentAuthorizedEvent` |
| `StripePaymentReturnHandler` | `StripePaymentReturnEvent` | 0 | Handles direct payment return flow |
| `StripePaymentStatusHandler` | `StripePaymentExecuteEvent` | 0 | Checks payment status after execution |
| `StripeOrderCreationHandler` | `ContractReadyToCommitEvent` | 80 | Finalizes order, transitions contract to COMMITTED |
| `StripeCaptureRequestHandler` | `StripeCaptureRequestEvent` | 0 | Captures authorized payment |
| `StripeCancelAuthorizationRequestHandler` | `StripeCancelAuthorizationRequestEvent` | 0 | Cancels payment authorization |
| `StripeRefundRequestHandler` | `StripeRefundRequestEvent` | 0 | Processes refund request |

### Payment-Component Handlers (registered by stripe)

| Handler | Event | Priority | Purpose |
|---------|-------|----------|---------|
| `EarlyOrderCreationHandler` | `ContractDraftCompletedEvent` | 100 | Creates OXID order early (NOT_FINISHED), stores order number |
| `PaymentAuthorizedEventHandler` | `PaymentAuthorizedEvent` | 90 | Fulfills `payment_authorized` condition, checks if contract is ready |
| `FraudCheckHandler` | `PaymentAuthorizedEvent` | 85 | Runs Stripe Radar fraud check, fulfills `fraud_check` condition |
| `OrderPaymentCompletedHandler` | `ContractFulfilledEvent` | 0 | Updates OXID order payment state after fulfillment |

### Event Flow (Checkout Session)

```
StripeCheckoutSessionRequestEvent
  → StripeContractCreationHandler (100)
      → ContractDraftCompletedEvent
          → EarlyOrderCreationHandler (100)
  → StripeCheckoutSessionHandler (0)

[User pays on Stripe, returns]

StripeCheckoutReturnEvent
  → StripeCheckoutReturnHandler (100)
      → PaymentAuthorizedEvent
          → PaymentAuthorizedEventHandler (90)
              → ContractReadyToCommitEvent
                  → StripeOrderCreationHandler (80)
                      → ContractCommittedEvent
          → FraudCheckHandler (85)
```

---

## 6. Admin Operations (Capture / Refund / Transaction History)

### Partial Capture and Refund

Both capture and refund support partial amounts via `?float $amount` parameter:
- `null` = full capture/refund (backward compatible)
- `float > 0` = partial amount

**Stripe constraints:**
- Capture: `amount <= PaymentIntent.amount` (partial capture releases remainder — one-time, irreversible)
- Refund: `total_refunded + amount <= Charge.amount_captured` (multiple partial refunds allowed)

### Transaction Storage Strategy (B+)

| Layer | Source | Purpose |
|-------|--------|---------|
| **Display** | Stripe API (`getStripeTransactionHistory()`) | Always fresh, covers Dashboard actions |
| **Audit log** | `oe_payments_transaction` DB table | Records events from our code (auth, capture, refund) |
| **Self-healing** | `reconcilePaymentState()` on admin view | Fixes OXPAID when Stripe says succeeded but DB says 0000 |

**Why not DB-only display?** Actions on Stripe Dashboard (partial capture, refund) bypass our webhook chain. The Stripe API is the single source of truth for what actually happened.

**Why keep DB recording?** Audit trail, multi-provider compatibility (`oe_payments_transaction` is provider-agnostic), offline reporting capability.

### Admin Controller Architecture

```
OrderRefund (OXID admin controller — no constructor DI)
├── getViewDataProvider() → OrderRefundViewDataProvider
│   ├── getStripeTransactionHistory(Order) — Stripe API display
│   ├── getPaymentIntent(Order) — cached PI fetch
│   ├── getLastCharge(Order) — cached charge fetch
│   ├── isOrderCapturable(Order) — PI status check
│   └── getRemainingRefundableAmount(Order) — from charge data
├── getActionDispatcher() → OrderActionDispatcher
│   ├── dispatchCapture(Order, piId, reason, ?amount)
│   ├── dispatchRefund(Order, reason, description, ?amount)
│   └── dispatchCancel(Order, piId, reason)
└── reconcilePaymentState(Order) — OXPAID self-healing
```

---

## 7. Key Design Patterns

### Template Method (ContractCreationHandler)

The base `ContractCreationHandler` in payment-component handles validation and contract creation. Stripe extends it and overrides two hooks:

```php
// payment-component defines the template
abstract class ContractCreationHandler implements HandlerInterface
{
    public function handle(object $event): void { /* creates contract, calls hooks */ }

    // Override these in your provider module:
    protected function afterContractCreated(
        PaymentContractInterface $contract,
        EventContextInterface $context
    ): void {}

    abstract protected function dispatchContractEvent(
        PaymentContractInterface $contract,
        EventContextInterface $context
    ): void;
}
```

See: `src/Stripe/EventSystem/Handler/StripeContractCreationHandler.php`

### Lazy Proxy (LazyStripeAdapter)

`LazyStripeAdapter` defers `StripeClient` initialization until first use. This avoids connecting to Stripe on every request — only when a payment operation actually happens.

See: `src/Stripe/Adapter/LazyStripeAdapter.php`

### Repository Interfaces

All data access goes through repository interfaces defined in payment-component. The Doctrine implementations are wired via `services.yaml`. This means your extension can override a repository binding without touching any handler code.

---

## 8. File Layout Conventions

| Convention | Example |
|-----------|---------|
| One class per file | `StripeCheckoutSessionHandler.php` |
| Interface suffix | `CheckoutSessionServiceInterface.php` |
| Handler suffix | `StripeContractCreationHandler.php` |
| Event suffix | `StripeCheckoutSessionRequestEvent.php` |
| Service suffix | `CheckoutSessionService.php` |
| Tests mirror src | `tests/Unit/Stripe/EventSystem/Handler/StripeContractCreationHandlerTest.php` |
| Factory in Service/Factory | `src/Stripe/Service/Factory/StripeAdapterFactory.php` |
| Result objects in Service/Result | `src/Stripe/Service/Result/CheckoutSessionResult.php` |
