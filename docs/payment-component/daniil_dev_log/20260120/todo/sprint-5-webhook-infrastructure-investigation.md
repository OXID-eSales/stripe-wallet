# Sprint 5: Webhook Infrastructure Investigation

**Date:** 2026-01-20
**Priority:** Medium
**Estimated Effort:** 6-8 hours
**Type:** Architectural Investigation & Refactoring

---

## Core Development Principles

All code in this sprint MUST follow:

| Principle | Requirement |
|-----------|-------------|
| **TDD-First** | Write failing tests BEFORE implementation. Red → Green → Refactor |
| **SOLID** | Single Responsibility, Open/Closed, Liskov Substitution, Interface Segregation, Dependency Inversion |
| **Liskov Substitution** | Subtypes must be substitutable for their base types |
| **Dependency Injection** | Depend on abstractions, not concretions. Inject dependencies via constructor |
| **DRY** | Don't Repeat Yourself. Extract common logic to shared methods/classes |
| **Clean Code** | Meaningful names, small functions (15-25 lines), early returns (no else), single responsibility per method |
| **No Over-Engineering** | Only add what's needed now. No speculative features or premature abstractions |

### Testing Commands

Run from `payment-component/` or `stripe/` directory:

```bash
# Quick check (unit tests + style checks)
./bin/pre-commit-check.sh

# Full check (unit tests + integration tests + style checks)
./bin/pre-commit-check.sh --full
```

---

## Executive Summary

The component provides a generic webhook infrastructure:
- `WebhookProcessor` - processes incoming webhooks
- `WebhookRequestParser` - parses webhook requests
- `WebhookIdempotencyChecker` - checks for duplicate webhooks
- `WebhookEventDispatcher` - dispatches webhook events

However, Stripe implemented its own `WebhookProcessingService` with similar logic. **This is the same architectural violation** seen in Sprint 1 (handlers) and Sprint 3 (capture/refund services).

Stripe's webhook handling should **extend** the component's infrastructure, not duplicate it.

---

## Current State Analysis

### Component's Webhook Infrastructure

**WebhookProcessor:**
```php
interface WebhookProcessorInterface
{
    public function process(WebhookRequest $request): WebhookResult;
}

class WebhookProcessor implements WebhookProcessorInterface
{
    public function __construct(
        private readonly WebhookRequestParserInterface $parser,
        private readonly WebhookIdempotencyCheckerInterface $idempotencyChecker,
        private readonly WebhookEventDispatcherInterface $eventDispatcher,
        private readonly WebhookLogRepositoryInterface $logRepository
    ) {}

    public function process(WebhookRequest $request): WebhookResult
    {
        // 1. Parse request
        // 2. Check idempotency
        // 3. Dispatch event
        // 4. Log result
    }
}
```

**WebhookRequestParser:**
```php
interface WebhookRequestParserInterface
{
    public function parse(WebhookRequest $request): WebhookEvent;
}
```

**WebhookIdempotencyChecker:**
```php
interface WebhookIdempotencyCheckerInterface
{
    public function isAlreadyProcessed(string $eventId): bool;
    public function markAsProcessed(string $eventId): void;
}
```

### Stripe's WebhookProcessingService

**Location:** `stripe/src/Stripe/Service/WebhookProcessingService.php`

```php
class WebhookProcessingService
{
    public function __construct(
        WebhookContractFulfillmentHandlerInterface $contractFulfillmentHandler,
        ?EventDispatcherInterface $eventDispatcher = null,
        ?WebhookLogRepositoryInterface $webhookLogRepository = null,
        ?ContractRepositoryInterface $contractRepository = null,
        // ...
    ) {}

    public function processEvent(\Stripe\Event $event): void
    {
        // 1. Check idempotency via webhookLogRepository->existsByEventId()
        // 2. Log webhook received
        // 3. Route to specific handler based on event type
        // 4. Update webhook log status
    }

    // Individual handlers for each event type:
    private function handlePaymentIntentSucceeded(\Stripe\Event $event): void
    private function handlePaymentIntentFailed(\Stripe\Event $event): void
    private function handlePaymentIntentCanceled(\Stripe\Event $event): void  // BUG: doesn't update contract!
    private function handleChargeCaptured(\Stripe\Event $event): void
    private function handleChargeRefunded(\Stripe\Event $event): void
    private function handleDisputeCreated(\Stripe\Event $event): void
    private function handleCheckoutSessionCompleted(\Stripe\Event $event): void
}
```

---

## Code Duplication Analysis

### Idempotency Check

**Component's `WebhookIdempotencyChecker`:**
```php
public function isAlreadyProcessed(string $eventId): bool
{
    return $this->repository->existsByEventId($eventId);
}
```

**Stripe's inline check:**
```php
// WebhookProcessingService.php line 97-100
if ($this->webhookLogRepository !== null && $this->webhookLogRepository->existsByEventId($event->id)) {
    Registry::getLogger()->info('Webhook event already processed (idempotency check)', [...]);
    return;
}
```

**Duplication:** Same logic, but Stripe doesn't use the component's checker.

### Webhook Logging

**Component's approach:** Log via `WebhookLogRepository` in processor

**Stripe's approach:** Same, but inline in `WebhookProcessingService`:
```php
$this->webhookLogRepository->save(new WebhookLog(...));
```

**Duplication:** Same pattern, not abstracted.

### Event Routing

**Component's `WebhookEventDispatcher`:**
```php
public function dispatch(WebhookEvent $event): void
{
    // Dispatch to registered handlers via EventDispatcher
}
```

**Stripe's inline routing:**
```php
switch ($event->type) {
    case 'payment_intent.succeeded':
        $this->handlePaymentIntentSucceeded($event);
        break;
    case 'payment_intent.payment_failed':
        $this->handlePaymentIntentFailed($event);
        break;
    // ... more cases
}
```

**Duplication:** Component uses event system, Stripe uses switch statement.

---

## Recommended Architecture

### Option A: Abstract Webhook Processor (Recommended)

```php
// Component's abstract processor
abstract class AbstractWebhookProcessor implements WebhookProcessorInterface
{
    public function __construct(
        protected readonly WebhookLogRepositoryInterface $logRepository,
        protected readonly EventDispatcherInterface $eventDispatcher,
        protected readonly LoggerInterface $logger
    ) {}

    final public function process(WebhookRequest $request): WebhookResult
    {
        // 1. Parse request (provider implements)
        $event = $this->parseRequest($request);

        // 2. Check idempotency (shared)
        if ($this->isAlreadyProcessed($event->getId())) {
            return WebhookResult::skipped('Already processed');
        }

        // 3. Validate signature (provider implements)
        if (!$this->validateSignature($request, $event)) {
            return WebhookResult::failed('Invalid signature');
        }

        // 4. Log received
        $this->logWebhookReceived($event);

        // 5. Process event (provider implements)
        $result = $this->processEvent($event);

        // 6. Log result
        $this->logWebhookResult($event, $result);

        return $result;
    }

    // Shared methods
    protected function isAlreadyProcessed(string $eventId): bool
    {
        return $this->logRepository->existsByEventId($eventId);
    }

    protected function logWebhookReceived(WebhookEvent $event): void
    {
        $this->logRepository->save(WebhookLog::received($event));
    }

    protected function logWebhookResult(WebhookEvent $event, WebhookResult $result): void
    {
        $this->logRepository->updateStatus($event->getId(), $result->getStatus());
    }

    // Provider must implement
    abstract protected function parseRequest(WebhookRequest $request): WebhookEvent;
    abstract protected function validateSignature(WebhookRequest $request, WebhookEvent $event): bool;
    abstract protected function processEvent(WebhookEvent $event): WebhookResult;
}

// Stripe extends it
class StripeWebhookProcessor extends AbstractWebhookProcessor
{
    public function __construct(
        WebhookLogRepositoryInterface $logRepository,
        EventDispatcherInterface $eventDispatcher,
        LoggerInterface $logger,
        private readonly WebhookContractFulfillmentHandlerInterface $fulfillmentHandler,
        private readonly string $webhookSecret
    ) {
        parent::__construct($logRepository, $eventDispatcher, $logger);
    }

    protected function parseRequest(WebhookRequest $request): WebhookEvent
    {
        // Use Stripe SDK to parse
        $stripeEvent = \Stripe\Webhook::constructEvent(
            $request->getPayload(),
            $request->getHeader('Stripe-Signature'),
            $this->webhookSecret
        );

        return new StripeWebhookEvent($stripeEvent);
    }

    protected function validateSignature(WebhookRequest $request, WebhookEvent $event): bool
    {
        // Already validated in parseRequest for Stripe
        return true;
    }

    protected function processEvent(WebhookEvent $event): WebhookResult
    {
        // Route to specific handlers
        return match ($event->getType()) {
            'payment_intent.succeeded' => $this->handlePaymentSucceeded($event),
            'payment_intent.payment_failed' => $this->handlePaymentFailed($event),
            'payment_intent.canceled' => $this->handlePaymentCanceled($event),
            'charge.captured' => $this->handleChargeCaptured($event),
            'charge.refunded' => $this->handleChargeRefunded($event),
            'checkout.session.completed' => $this->handleCheckoutCompleted($event),
            'checkout.session.expired' => $this->handleCheckoutExpired($event),
            default => WebhookResult::skipped('Unhandled event type'),
        };
    }

    // Individual handlers that call fulfillmentHandler
    private function handlePaymentSucceeded(WebhookEvent $event): WebhookResult
    {
        $result = $this->fulfillmentHandler->handlePaymentSucceeded($event->getProviderOrderId());
        return $result ? WebhookResult::success() : WebhookResult::skipped('Contract not found or already processed');
    }

    // ... more handlers
}
```

### Benefits of This Architecture

1. **DRY:** Idempotency check, logging, and flow are shared
2. **Extensible:** Providers only implement parsing and event handling
3. **Testable:** Can test component logic separately from provider logic
4. **Consistent:** All providers follow same webhook flow
5. **Bug prevention:** Fixes like `payment_intent.canceled` handling are centralized

---

## Investigation Tasks

### Task 1: Compare Component vs Stripe Logic

| Component Class | Stripe Equivalent | Reusable Logic |
|----------------|-------------------|----------------|
| `WebhookProcessor` | `WebhookProcessingService` | Process flow, logging |
| `WebhookRequestParser` | Inline in controller | Request parsing |
| `WebhookIdempotencyChecker` | Inline check | Idempotency logic |
| `WebhookEventDispatcher` | Switch statement | Event routing |

### Task 2: Identify Extractable Methods

From `WebhookProcessingService`, identify which methods can be moved to component:

```bash
# List all methods in WebhookProcessingService
grep -n "private function\|protected function\|public function" \
  stripe/src/Stripe/Service/WebhookProcessingService.php
```

### Task 3: Check WebhookEvent Compatibility

```bash
# Compare WebhookEvent classes
diff payment-component/src/Webhook/WebhookEvent.php \
     stripe/src/Stripe/Adapter/StripeWebhookEvent.php
```

### Task 4: Analyze Controller Integration

Check how `StripeWebhookController` calls `WebhookProcessingService`:

```bash
grep -r "WebhookProcessingService" stripe/src/Stripe/Controller/
```

---

## Implementation Phases

### Phase 1: Investigation (2 hours)
1. Complete investigation tasks
2. Map all webhook flows
3. Document shared vs provider-specific logic

### Phase 2: Create Abstract Processor (2 hours)
1. Design `AbstractWebhookProcessor`
2. Extract shared logic from Stripe
3. Write unit tests

### Phase 3: Refactor Stripe (2-3 hours)
1. Create `StripeWebhookProcessor extends AbstractWebhookProcessor`
2. Update `StripeWebhookController` to use it
3. Remove old `WebhookProcessingService`
4. Write integration tests

### Phase 4: Cleanup (1 hour)
1. Remove unused component webhook classes if not needed
2. Update services.yaml
3. Update documentation

---

## Files Involved

### Component Webhook Infrastructure
```
payment-component/src/Webhook/WebhookProcessor.php
payment-component/src/Webhook/WebhookProcessorInterface.php
payment-component/src/Webhook/WebhookRequestParser.php
payment-component/src/Webhook/WebhookRequestParserInterface.php
payment-component/src/Webhook/WebhookIdempotencyChecker.php
payment-component/src/Webhook/WebhookIdempotencyCheckerInterface.php
payment-component/src/Webhook/WebhookEventDispatcher.php
payment-component/src/Webhook/WebhookEventDispatcherInterface.php
```

### Stripe Webhook Implementation
```
stripe/src/Stripe/Service/WebhookProcessingService.php
stripe/src/Stripe/Controller/StripeWebhookController.php
stripe/src/Stripe/Adapter/StripeWebhookEvent.php
stripe/src/Stripe/WebhookHandler/WebhookContractFulfillmentHandler.php
```

### Keep (Data Classes)
```
payment-component/src/Webhook/WebhookLog.php
payment-component/src/Webhook/WebhookEvent.php
payment-component/src/Webhook/WebhookResult.php
payment-component/src/Webhook/WebhookRequest.php
```

---

## Definition of Done

- [ ] Investigation tasks completed
- [ ] Architecture decision documented
- [ ] `AbstractWebhookProcessor` created with TDD
- [ ] `StripeWebhookProcessor` extends it
- [ ] Old `WebhookProcessingService` removed/refactored
- [ ] All tests pass
- [ ] PHPStan level 6 passes
- [ ] Bug from Sprint 1 (payment_intent.canceled) fixed in new architecture

---

## References

- Sprint 1: Handler architectural violations
- Sprint 3: Capture/Refund services investigation
- Architecture: `architecture/05-webhooks.md`
- Bug: Contract cancellation not handled (Sprint 1, BUG-1)
