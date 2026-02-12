# Report: UCP/ACP Uses Event System, Not Direct Service Calls

**Date:** 2026-02-12
**Context:** Sprint 47 (ACP + UCP + MCP Support)
**Goal:** Prove that the planned UCP/ACP functionality uses the event-driven architecture and not direct data or service calls from controllers.

---

## Summary

The UCP/ACP architecture is fully event-driven. Controllers are thin dispatchers that create `EventContext`, dispatch domain events, and read results from context. All business logic lives in event handlers tagged via `services.yaml`. No controller directly calls `ContractService`, `ContractRepository`, or `StripeAdapter`.

---

## 1. Controllers Are "Event-Only" — Zero Business Logic

### UcpCheckoutController (Sprint 47, B7)

The controller follows a strict 5-step pattern:

```
1. Validate auth        → McpAuthGuard (injected)
2. Validate headers     → UcpRequestValidator (injected)
3. Create context       → EventContext with ONLY DATA
4. Dispatch event       → $this->eventDispatcher->dispatch($event)
5. Read result          → $context->get('responseData')
```

Key code (from sprint-47-acp-ucp-mcp-support.md, B7):

```php
// 3. Create context — ONLY DATA, NO LOGIC
$context = new EventContext([
    'httpMethod' => $method,
    'pathSegments' => $segments,
    'requestBody' => json_decode($rawBody ?: '{}', true) ?? [],
    'agentContext' => $authResult->getAgentContext(),
    'ucpHeaders' => $headers,
]);

// 4. Dispatch event — HANDLER DOES THE WORK
$event = new UcpCheckoutRequestEvent($context);
$this->eventDispatcher->dispatch($event);

// 5. Read result from context
$statusCode = $context->get('httpStatusCode') ?? 200;
$responseData = $context->get('responseData');
```

The controller **never** calls `AcpCheckoutServiceInterface` directly. It has no dependency on it.

### McpController (Sprint 47, B1)

Same pattern — dispatches `McpRequestReceivedEvent`, handled by `McpRequestHandler`.

### ProductFeedController (Sprint 48, B7)

Same pattern — dispatches `ProductFeedRequestEvent`, handled by `ProductFeedRequestHandler`.

---

## 2. Event Chain Is the Only Path to Business Logic

### UCP Request Flow

```
UcpCheckoutController
  → dispatches UcpCheckoutRequestEvent(EventContext)
    → UcpCheckoutRequestHandler.handle()
      → AcpCheckoutServiceInterface.createCheckout() / getCheckout() / etc.
        → AbstractAcpCheckoutService (payment-component)
          → ContractService + ContractRepository + EventDispatcher
```

### MCP/ACP Request Flow

```
McpController
  → dispatches McpRequestReceivedEvent(EventContext)
    → McpRequestHandler.handle()
      → McpServer.handleRequest()
        → ACP Tool (e.g., CreateCheckoutTool)
          → AcpCheckoutServiceInterface.createCheckout()
            → AbstractAcpCheckoutService
              → ContractService + EventDispatcher
```

### Product Feed Request Flow

```
ProductFeedController
  → dispatches ProductFeedRequestEvent(EventContext)
    → ProductFeedRequestHandler.handle()
      → AcpProductServiceInterface.listProducts()
      → ProductFeedGeneratorInterface.generate()
```

**No controller** has a direct reference to `ContractService`, `ContractRepository`, or `StripeAdapter`.

---

## 3. Existing Codebase Proves This Pattern

The same event-only controller pattern is already implemented and battle-tested in production:

| Controller | Event Dispatched | Handler |
|---|---|---|
| `StripeOrderController.executeStripePayment()` | `StripePaymentExecuteEvent` | `StripePaymentExecuteHandler` |
| `StripeOrderController.createCheckoutSession()` | `StripeCheckoutSessionRequestEvent` | `StripeCheckoutSessionHandler` |
| `StripeOrderController.checkoutSuccess()` | `StripeCheckoutReturnEvent` | `StripeCheckoutReturnHandler` |
| `StripeOrderController.stripeReturn()` | `StripePaymentReturnEvent` | `StripePaymentReturnHandler` |
| `OrderActionDispatcher.dispatchRefund()` | `StripeRefundRequestEvent` | `StripeRefundRequestHandler` |
| `OrderActionDispatcher.dispatchCapture()` | `StripeCaptureRequestEvent` | `StripeCaptureRequestHandler` |
| `OrderActionDispatcher.dispatchCancel()` | `StripeCancelAuthorizationRequestEvent` | `StripeCancelAuthorizationRequestHandler` |

The existing `StripeOrderController` (lines 17-30) explicitly documents:

> *"This controller is THIN - it only: 1. Validates input, 2. Creates EventContext with data, 3. Dispatches appropriate event, 4. Returns result from context. ALL business logic is in event handlers..."*

---

## 4. services.yaml Confirms Event-Driven DI Wiring

### Existing Event System Registration (services.yaml lines 35-51)

```yaml
OxidEsales\PaymentComponent\EventSystem\EventListenerProviderInterface:
  class: OxidEsales\PaymentComponent\EventSystem\EventListenerProvider
  arguments:
    - !tagged_iterator payment.event_handler

OxidEsales\PaymentComponent\EventSystem\EventDispatcherInterface:
  class: OxidEsales\PaymentComponent\EventSystem\EventDispatcher
  arguments:
    - '@OxidEsales\PaymentComponent\EventSystem\EventListenerProviderInterface'
```

### New ACP/UCP Handlers Are Tagged Identically

```yaml
OxidEsales\PaymentComponent\Mcp\Handler\McpRequestHandler:
    tags:
        - { name: payment.event_handler, priority: 100 }

OxidEsales\Payments\Stripe\Mcp\Handler\UcpCheckoutRequestHandler:
    tags:
        - { name: payment.event_handler, priority: 100 }

OxidEsales\Payments\Stripe\Mcp\Handler\ProductFeedRequestHandler:
    tags:
        - { name: payment.event_handler, priority: 100 }
```

All handlers collected via `!tagged_iterator payment.event_handler` — the same DI pattern used by all existing handlers.

---

## 5. Agent Notifications (Sprint 50) Also Use Events

The `AgentNotificationHandler` subscribes to **existing contract lifecycle events**:

```php
private const HANDLED_EVENTS = [
    ContractCommittedEvent::class,
    ContractFulfilledEvent::class,
    ContractCancelledEvent::class,
    ContractFailedEvent::class,
];
```

Tagged with low priority so it runs after core handlers:

```yaml
OxidEsales\PaymentComponent\Mcp\Handler\AgentNotificationHandler:
    tags:
        - { name: payment.event_handler, priority: 10 }
```

---

## 6. Contrast: What Direct Service Calls Would Look Like

If the architecture used direct calls, controllers would look like:

```php
// HYPOTHETICAL — NOT how it's built
class UcpCheckoutController {
    public function __construct(
        private readonly AcpCheckoutServiceInterface $checkoutService,
        private readonly ContractRepositoryInterface $contractRepo,
    ) {}

    public function handleRequest(): void {
        $contract = $this->checkoutService->createCheckout($body);
        $this->contractRepo->save($contract);
    }
}
```

Instead, the **actual** controller depends only on `EventDispatcherInterface`:

```php
// ACTUAL — event-only
class UcpCheckoutController {
    public function __construct(
        private readonly McpAuthGuardInterface $authGuard,
        private readonly UcpRequestValidator $requestValidator,
        private readonly UcpResponseFormatterInterface $responseFormatter,
        private readonly EventDispatcherInterface $eventDispatcher
    ) {}

    public function handleRequest(): void {
        $event = new UcpCheckoutRequestEvent($context);
        $this->eventDispatcher->dispatch($event);
        $responseData = $context->get('responseData');
    }
}
```

The only non-event dependencies are for input validation (`authGuard`, `requestValidator`) and output formatting (`responseFormatter`), not for business logic.

---

## 7. Core Event System Components (payment-component)

| Component | File | Purpose |
|---|---|---|
| `EventDispatcherInterface` | `EventSystem/EventDispatcherInterface.php` | Core dispatch contract |
| `EventDispatcher` | `EventSystem/EventDispatcher.php` | Routes events to listeners by class |
| `EventListenerProvider` | `EventSystem/EventListenerProvider.php` | DI bridge — collects tagged handlers |
| `EventContext` | `EventSystem/Event/EventContext.php` | Key-value data transport between controller and handler |
| `HandlerInterface` | `EventSystem/Handler/HandlerInterface.php` | Unified handler contract with `getHandledEventClass()` |

---

## 8. Evidence Summary Table

| Evidence | Confirms Event-Driven |
|---|---|
| All 3 new controllers (MCP, UCP, ProductFeed) only call `$this->eventDispatcher->dispatch()` | Controllers have zero business logic |
| All handlers tagged `payment.event_handler` in services.yaml | Wired through DI event system |
| `EventContext` is the only data transport between controller and handler | No return values, no direct service calls |
| Existing production controllers (StripeOrderController, OrderActionDispatcher) use identical pattern | Proven architecture, not theoretical |
| AgentNotificationHandler subscribes to contract events | Webhook delivery is also event-driven |
| Sprint 47 principle: *"Build the thinnest possible layer that connects ACP/MCP and UCP protocols to the existing event-driven architecture"* | Architectural intent is explicit |
| Handler chains: one handler dispatches downstream events (e.g., `StripeCheckoutReturnHandler` → `PaymentAuthorizedEvent`) | Multi-step flows composed via events |
| Priority-based execution (services.yaml tags: priority 100, 90, 80, ...) | Deterministic handler ordering without coupling |

---

## Conclusion

The UCP/ACP functionality is fully event-driven by design and implementation:

1. **Controllers** are thin dispatchers — they create `EventContext`, fire an event, and read results
2. **Handlers** contain all business logic — they're registered via DI tags and auto-discovered
3. **EventContext** is the sole data exchange mechanism — no return values flow through the event chain
4. **The existing production codebase** already uses this identical pattern for all checkout, refund, capture, and cancellation flows
5. **No controller** has a direct dependency on `ContractService`, `ContractRepository`, `StripeAdapter`, or any business service
