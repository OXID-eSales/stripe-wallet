# Sprint 1: Payment Component Code Analysis & Cleanup

**Date:** 2026-01-20
**Priority:** High (contains bugs)
**Estimated Effort:** 4-6 hours
**Risk Level:** Medium (requires careful refactoring)

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

Following the successful removal of the redundant `Order` DTO (see `reports/redundant-order-code-removal.md`), a comprehensive analysis of the `payment-component` codebase revealed:

1. **CRITICAL BUG**: Contract cancellation is not handled - cancelled payments leave contracts in stale states
2. **Architectural violations**: Stripe duplicates component handler logic instead of extending
3. **Multiple areas** requiring investigation before removal/refactoring

**This sprint focuses on:**
- Part A: Bug fixes (HIGH PRIORITY)
- Part B: Architectural refactoring for handlers (MEDIUM PRIORITY)
- Part C: Spawned 6 dedicated sprints for thorough investigation

---

## Decisions Made (Q&A Session Results)

| Decision | Choice | Rationale |
|----------|--------|-----------|
| BUG-1: ContractCleanupHandler | **Option A: Wire up** | Separation of concerns - webhook updates state, handler performs cleanup |
| BUG-2: Expired sessions | **EXPIRED state** | Semantically distinct from CANCELLED (timeout vs user action) |
| VIOLATION-1: Handler pattern | **Template Method** | Cleaner, enforces the pattern, prevents duplication |
| Event handling | **Keep separate events** | `ContractCreatedEvent` vs `ContractDraftCompletedEvent` - abstract `getHandledEventClass()` |
| VIOLATION-2 | **Defer to Sprint 2** | Investigation needed for condition fulfillment flow |
| VIOLATION-3 | **Defer to Sprint 3** | Investigation needed for authorization flow |
| Development approach | **Strict TDD** | Write failing tests first, then implementation |

**Confirmed Scope:**
1. BUG-1: `handlePaymentCanceled()` + wire `ContractCleanupHandler`
2. BUG-2: `checkout.session.expired` → EXPIRED state
3. REFACTOR: `ContractCreationHandler` with Template Method, abstract `getHandledEventClass()`

> **Related Sprints Created:**
> - **Sprint 2:** Condition handlers TDD implementation (Stock, Fraud)
> - **Sprint 3:** Capture/Refund services investigation
> - **Sprint 4:** CheckoutOrchestrator removal
> - **Sprint 5:** Webhook infrastructure investigation
> - **Sprint 6:** Controller architecture investigation
> - **Sprint 7:** PaymentCustomer repository removal

---

## PART A: BUG FIXES (HIGH PRIORITY)

### BUG-1: Contract Cancellation Never Updates Contract State

**Severity:** HIGH
**Impact:** Cancelled payments leave contracts in stale states (PENDING, COMMITTED) forever

#### Evidence

**`WebhookProcessingService.php` lines 663-673:**
```php
private function handlePaymentIntentCanceled(\Stripe\Event $event): void
{
    $paymentIntent = $event->data->object;

    Registry::getLogger()->info('Payment intent canceled', [
        'payment_intent_id' => $paymentIntent->id,
    ]);

    // Sprint 8: order_state table removed - no legacy update needed
    // Cancellation is tracked via contract.OXSTATE = 'cancelled'
    // ^^^ THIS CODE DOESN'T EXIST! Comment is misleading.
}
```

**Comparison with `handlePaymentIntentFailed()` (lines 618-654):**
```php
private function handlePaymentIntentFailed(\Stripe\Event $event): void
{
    // ... validation ...

    // Sprint 6: Try contract-aware failure handling first
    $contractResult = $this->contractFulfillmentHandler->handlePaymentFailed(
        $paymentIntentId,
        $failureReason
    );
    // ^^^ CORRECTLY updates contract state to FAILED
}
```

**`WebhookContractFulfillmentHandler.php`:**
- Has `handlePaymentFailed()` method - calls `$contract->fail()`
- Has `handlePaymentSucceeded()` method - calls fulfillment service
- Has `handleChargeCaptured()` method - handles captures
- Has `handleChargeRefunded()` method - handles refunds
- **MISSING: `handlePaymentCanceled()` method!**

#### Fix Required

1. Add `handlePaymentCanceled(string $providerOrderId, string $reason): ?bool` to `WebhookContractFulfillmentHandlerInterface`
2. Implement in `WebhookContractFulfillmentHandler`:
   ```php
   public function handlePaymentCanceled(string $providerOrderId, string $reason): ?bool
   {
       $contract = $this->findContractByProviderOrderId($providerOrderId);
       if ($contract === null) {
           return null;
       }
       if ($contract->getState()->isTerminal()) {
           return false;
       }
       if ($contract instanceof PaymentContract) {
           $contract->cancel($reason);
           $this->contractRepository->save($contract);
           return true;
       }
       return false;
   }
   ```
3. Update `WebhookProcessingService::handlePaymentIntentCanceled()` to call this handler
4. Handle `checkout.session.expired` event similarly

#### Related: ContractCleanupHandler

The component's `ContractCleanupHandler` listens to `ContractTerminatedEventInterface` (which `ContractCancelledEvent` and `ContractExpiredEvent` implement).

**DECISION:** Option A - Wire up `ContractCleanupHandler` for separation of concerns:
- Webhook handler: Updates contract state to CANCELLED
- ContractCleanupHandler: Performs post-cancellation cleanup (triggered by ContractCancelledEvent)

---

### BUG-2: checkout.session.expired Not Handled

**Severity:** MEDIUM
**Impact:** Expired checkout sessions don't update contract state

When a Stripe Checkout Session expires (default: 24 hours), Stripe sends `checkout.session.expired` webhook. This is not handled:

```php
// WebhookProcessingService.php - switch statement
case 'checkout.session.completed':
    $this->handleCheckoutSessionCompleted($event);
    break;
// MISSING: case 'checkout.session.expired':
```

#### Fix Required

1. Add case for `checkout.session.expired` in the switch statement
2. Look up contract by session ID or metadata
3. Call `$contract->expire()` to set EXPIRED state

**DECISION:** Use dedicated EXPIRED state (not CANCELLED):
- Semantically distinct: user didn't cancel, session timed out
- Requires `PaymentContract::expire()` method (verify it exists or add)

---

## PART B: ARCHITECTURAL VIOLATIONS (MEDIUM PRIORITY)

### VIOLATION-1: StripeContractCreationHandler Duplicates Component Logic

**Problem:** `StripeContractCreationHandler` implements `HandlerInterface` directly instead of extending `ContractCreationHandler`.

#### Code Comparison

**Component's `ContractCreationHandler` (lines 26-62):**
```php
public function handle(object $event): void
{
    $context = $event->getContext();

    $userId = $context->get('userId');
    if (!is_string($userId) || $userId === '') {
        throw new InvalidArgumentException('User ID is required');
    }

    $basket = $context->get('basket');
    if (!is_object($basket)) {
        throw new InvalidArgumentException('Basket is required');
    }

    $conditionTypes = $context->get('conditionTypes', []);
    $validatedConditionTypes = array_values(array_filter($conditionTypes, 'is_string'));

    $contract = $this->contractService->createContract(
        $userId, $basket, $validatedConditionTypes
    );

    $context->setContract($contract);

    $this->eventDispatcher->dispatch(new ContractCreatedEvent($contract, $context));
}
```

**Stripe's `StripeContractCreationHandler` (lines 48-120):**
```php
public function handle(object $event): void
{
    // SAME validation logic duplicated
    $userId = $context->get('userId');
    if (!is_string($userId) || $userId === '') {
        throw new InvalidArgumentException('User ID is required');
    }

    $basket = $context->get('basket');
    if (!is_object($basket)) {
        throw new InvalidArgumentException('Basket is required');
    }

    $conditionTypes = $context->get('conditionTypes', []);
    $validatedConditionTypes = array_values(array_filter($conditionTypes, 'is_string'));

    // SAME contract creation
    $contract = $this->contractService->createContract(
        $userId, $basket, $validatedConditionTypes
    );

    // Stripe-specific additions:
    $this->metadataService->storeDeliveryAddressMetadata($contract, $basket);
    $this->metadataService->storeSecurityMetadata($contract, $context);
    $this->contractRepository->save($contract);

    $context->setContract($contract);

    // Different event dispatched
    $this->eventDispatcher->dispatch(new ContractDraftCompletedEvent($contract, $context));
}
```

#### Architectural Fix Options

**DECISION: Template Method Pattern with Abstract Event Class**

```php
// Component's ContractCreationHandler becomes abstract
abstract class ContractCreationHandler implements HandlerInterface
{
    public function __construct(
        protected readonly ContractServiceInterface $contractService,
        protected readonly EventDispatcherInterface $eventDispatcher
    ) {}

    /**
     * Template method - final to enforce the pattern
     */
    final public function handle(object $event): void
    {
        $context = $event->getContext();

        // Shared validation
        $userId = $context->get('userId');
        if (!is_string($userId) || $userId === '') {
            throw new InvalidArgumentException('User ID is required');
        }

        $basket = $context->get('basket');
        if (!is_object($basket)) {
            throw new InvalidArgumentException('Basket is required');
        }

        $conditionTypes = $context->get('conditionTypes', []);
        $validatedConditionTypes = array_values(array_filter($conditionTypes, 'is_string'));

        // Shared contract creation
        $contract = $this->contractService->createContract(
            $userId, $basket, $validatedConditionTypes
        );

        // Hook for provider-specific logic (metadata, etc.)
        $this->afterContractCreated($contract, $context);

        $context->setContract($contract);

        // Provider dispatches appropriate event
        $this->dispatchContractEvent($contract, $context);
    }

    /**
     * Provider specifies which event class it handles
     */
    abstract public static function getHandledEventClass(): string;

    /**
     * Hook for provider-specific post-creation logic
     * Default: no-op, override in provider handlers
     */
    protected function afterContractCreated(
        PaymentContractInterface $contract,
        EventContextInterface $context
    ): void {
        // No-op by default
    }

    /**
     * Provider dispatches its own event type
     */
    abstract protected function dispatchContractEvent(
        PaymentContractInterface $contract,
        EventContextInterface $context
    ): void;
}

// Stripe extends it
class StripeContractCreationHandler extends ContractCreationHandler
{
    public function __construct(
        ContractServiceInterface $contractService,
        EventDispatcherInterface $eventDispatcher,
        private readonly MetadataServiceInterface $metadataService,
        private readonly ContractRepositoryInterface $contractRepository
    ) {
        parent::__construct($contractService, $eventDispatcher);
    }

    public static function getHandledEventClass(): string
    {
        return PaymentInitiatedEvent::class; // Stripe listens to this
    }

    protected function afterContractCreated(
        PaymentContractInterface $contract,
        EventContextInterface $context
    ): void {
        $this->metadataService->storeDeliveryAddressMetadata($contract, $context->get('basket'));
        $this->metadataService->storeSecurityMetadata($contract, $context);
        $this->contractRepository->save($contract);
    }

    protected function dispatchContractEvent(
        PaymentContractInterface $contract,
        EventContextInterface $context
    ): void {
        // Stripe dispatches ContractDraftCompletedEvent (not ContractCreatedEvent)
        $this->eventDispatcher->dispatch(new ContractDraftCompletedEvent($contract, $context));
    }
}
```

**Benefits of this approach:**
1. **DRY**: Validation and contract creation logic shared
2. **Open/Closed**: Component closed for modification, open for extension
3. **Explicit contracts**: `getHandledEventClass()` makes event binding clear
4. **Flexibility**: Providers can dispatch different events (`ContractDraftCompletedEvent` vs `ContractCreatedEvent`)

---

### VIOLATION-2: ContractConditionResolverHandler - DEFERRED TO SPRINT 2

**Status:** ⏸️ DEFERRED - Investigation will be part of Sprint 2 (Condition Handlers TDD)

**Reason:** This handler relates to condition fulfillment flow. Sprint 2 will implement Stock and Fraud condition handlers with TDD, and this investigation should be done in that context.

**Questions to answer in Sprint 2:**
1. Where does Stripe fulfill the `payment_authorized` condition?
2. Is condition resolution happening correctly in all flows?
3. Should `ContractConditionResolverHandler` be wired up or is inline handling correct?

---

### VIOLATION-3: PaymentAuthorizationHandler - DEFERRED TO SPRINT 3

**Status:** ⏸️ DEFERRED - Investigation will be part of Sprint 3 (Capture/Refund Services)

**Reason:** This handler relates to payment authorization flow. Sprint 3 investigates capture/refund services which are closely related to authorization patterns.

**Questions to answer in Sprint 3:**
1. Does Stripe's flow bypass `PaymentAuthorizationHandler`?
2. Is there reusable logic that should be extracted?
3. Should component provide abstract authorization handler?

---

## PART C: CODE REQUIRING SEPARATE SPRINTS

The remaining code has been moved to dedicated sprints for proper investigation and refactoring:

### Moved to Sprint 2: Condition Handlers
**File:** `sprint-2-condition-handlers-implementation.md`

| File | Status |
|------|--------|
| `StockReservationHandler.php` | TDD implementation planned |
| `StockReleaseHandler.php` | TDD implementation planned |
| `FraudCheckHandler.php` | TDD implementation planned |
| `FraudScoringService.php` | Will be used by handlers |
| `StockManagementService.php` | Will be used by handlers |

### Moved to Sprint 3: Capture/Refund Services
**File:** `sprint-3-capture-refund-services-investigation.md`

| File | Status |
|------|--------|
| `PaymentCaptureService.php` | Architectural investigation needed |
| `PaymentRefundService.php` | Architectural investigation needed |

**Issue:** Stripe duplicates logic instead of extending component services.

### Moved to Sprint 4: CheckoutOrchestrator Removal
**File:** `sprint-4-remove-checkout-orchestrator.md`

| File | Status |
|------|--------|
| `CheckoutOrchestrator.php` | Safe to remove (never called) |
| `CheckoutOrchestratorInterface.php` | Safe to remove |
| `Result/CheckoutResult.php` | Safe to remove |
| `Result/OrderConfirmationResult.php` | Safe to remove |

### Moved to Sprint 5: Webhook Infrastructure
**File:** `sprint-5-webhook-infrastructure-investigation.md`

| File | Status |
|------|--------|
| `Webhook/WebhookProcessor.php` | Stripe should extend this |
| `Webhook/WebhookRequestParser.php` | Stripe should extend this |
| `Webhook/WebhookIdempotencyChecker.php` | Stripe should extend this |
| `Webhook/WebhookEventDispatcher.php` | Stripe should extend this |

**Issue:** Stripe duplicates webhook infrastructure instead of extending component classes.

### Moved to Sprint 6: Controller Architecture
**File:** `sprint-6-controller-architecture-investigation.md`

| File | Status |
|------|--------|
| `Controller/AbstractController.php` | Investigate if needed |
| `Controller/BaseController.php` | Investigate if needed |
| `Controller/BaseControllerInterface.php` | Investigate if needed |
| `Controller/Webhook/WebhookController.php` | Investigate if needed |

**Question:** Should component provide abstract controllers that Stripe extends?

### Moved to Sprint 7: Repository Removal
**File:** `sprint-7-remove-payment-customer-repository.md`

| File | Status |
|------|--------|
| `Repository/PaymentCustomerRepositoryInterface.php` | Safe to remove (vaulting not implemented) |
| `Repository/DoctrinePaymentCustomerRepository.php` | Safe to remove (no DB table) |

---

## Implementation Plan

### Phase 1: Fix Bugs (HIGH PRIORITY)
1. Implement `handlePaymentCanceled()` in `WebhookContractFulfillmentHandler`
2. Update `WebhookProcessingService` to call it for `payment_intent.canceled`
3. Add handling for `checkout.session.expired`
4. Write tests for cancellation/expiration flows
5. Verify contracts are properly updated

### Phase 2: Architectural Refactoring (MEDIUM PRIORITY)
1. Refactor `ContractCreationHandler` to use Template Method pattern
2. Update `StripeContractCreationHandler` to extend it
3. Investigate and document `ContractConditionResolverHandler` usage
4. Investigate and document `PaymentAuthorizationHandler` usage
5. Wire up `ContractCleanupHandler` if needed

### Phase 3: Clean Up (LOW PRIORITY)
1. Clean up services.yaml commented entries (after other sprints complete)
2. Remove orphaned test files (after other sprints complete)

> **Note:** All code removal has been moved to dedicated sprints (4, 6, 7) for proper investigation.

---

## Files Summary

### DO NOT REMOVE - Need Bug Fixes
| File | Action |
|------|--------|
| `ContractCleanupHandler.php` | Keep - may need to wire up after bug fix |

### DO NOT REMOVE - Need Architectural Review
| File | Action |
|------|--------|
| `ContractCreationHandler.php` | Keep - Stripe should extend this |
| `ContractConditionResolverHandler.php` | Keep - investigate usage |
| `PaymentAuthorizationHandler.php` | Keep - investigate usage |

### ALL CODE MOVED TO DEDICATED SPRINTS

No direct code removal in Sprint 1. All items have dedicated sprints:

| Sprint | Content | Priority |
|--------|---------|----------|
| **Sprint 2** | Condition handlers TDD implementation | Medium |
| **Sprint 3** | Capture/Refund services investigation | Medium |
| **Sprint 4** | CheckoutOrchestrator removal | Low |
| **Sprint 5** | Webhook infrastructure investigation | Medium |
| **Sprint 6** | Controller architecture investigation | Low |
| **Sprint 7** | PaymentCustomer repository removal | Low |

---

## Verification Steps

### After Bug Fixes
1. Create test payment and cancel it
2. Verify contract state changes to CANCELLED
3. Create test checkout session and let it expire
4. Verify contract state changes to EXPIRED
5. Run unit tests for new handler methods

### After Architectural Refactoring
1. Run full test suite
2. Verify Stripe checkout flow still works
3. Verify contract creation includes Stripe metadata

### After Code Removal
1. Run PHPStan: `composer phpstan`
2. Run Unit Tests: `composer test-unit`
3. Run Integration Tests: `composer test-integration`
4. Verify services.yaml has no broken references
5. Test Stripe checkout flow end-to-end

---

## References

- Previous cleanup: `reports/redundant-order-code-removal.md`
- **Sprint 2:** `sprint-2-condition-handlers-implementation.md` (Condition Handlers)
- **Sprint 3:** `sprint-3-capture-refund-services-investigation.md` (Capture/Refund Services)
- **Sprint 4:** `sprint-4-remove-checkout-orchestrator.md` (CheckoutOrchestrator Removal)
- **Sprint 5:** `sprint-5-webhook-infrastructure-investigation.md` (Webhook Infrastructure)
- **Sprint 6:** `sprint-6-controller-architecture-investigation.md` (Controller Architecture)
- Architecture docs: `architecture/01-architecture-layers.md`
- Contract state machine: `Contract/ContractState.php`
- Webhook handler: `stripe/src/Stripe/WebhookHandler/WebhookContractFulfillmentHandler.php`
