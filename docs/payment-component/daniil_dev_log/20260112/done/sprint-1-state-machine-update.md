# Sprint 1: Contract-Order State Machine Update (Early Order Creation)

**Sprint Goal:** Update state machine to create order early (after CONTRACT_DRAFT, before CONTRACT_PENDING)
**Ticket:** STRP-74
**Status:** PENDING

---

## Core Development Requirements

### TDD-First Approach
- **Write failing tests FIRST** - No implementation before test exists
- **Red-Green-Refactor** cycle strictly followed
- Test naming: `test[Feature]_[Scenario]_[ExpectedResult]()`

### SOLID Principles
- **S**ingle Responsibility - Each class has ONE reason to change
- **O**pen/Closed - Open for extension, closed for modification
- **L**iskov Substitution - Subtypes must be substitutable for base types
- **I**nterface Segregation - Prefer small, specific interfaces
- **D**ependency Inversion - Depend on abstractions, not concretions

### Clean Code Standards
- Methods: 15-25 lines maximum
- No else expressions - Use early returns
- Meaningful names - Self-documenting code
- DRY - Don't Repeat Yourself
- PSR-12 code style
- PHPStan level 6 compliance

### No Overengineering
- Only implement what's needed for THIS ticket
- No speculative generalization
- No "future-proofing" unless explicitly requested
- Simple solutions preferred over complex ones

---

## Architecture Reference

### PUML Sources
- `puml/01-contract-order-state-machine-v2.puml` - Updated state machine

### Documentation
- `docs/payment-component/architecture/01-architecture-layers.md`
- `docs/payment-component/architecture/00-overview.md`

---

## State Machine Change Summary

### Previous Flow
```
CONTRACT_DRAFT -> CONTRACT_PENDING -> CONTRACT_READY -> CONTRACT_COMMITTED -> NOT_FINISHED
                                                        ↑
                                                        Order created here (late)
```

### New Flow
```
CONTRACT_DRAFT -> NOT_FINISHED -> CONTRACT_PENDING -> CONTRACT_READY -> CONTRACT_COMMITTED
                  ↑
                  Order created here (early)
```

### Key Changes
1. Order created after CONTRACT_DRAFT completes (early creation)
2. New state: `not_finished` between `draft` and `pending`
3. Contract.OXORDERID set immediately when order is created
4. After CONTRACT_COMMITTED: PaymentInitiatedEvent OR OrderCancelledEvent (beyond scope)

---

## Tasks

### 1.1 Update PaymentContract State Machine

**Status:** [ ] NOT STARTED

**Test First:**
```php
// tests/Unit/Component/Contract/PaymentContractStateTransitionTest.php
class PaymentContractStateTransitionTest extends TestCase
{
    public function testContractCanTransitionFromDraftToNotFinished(): void;
    public function testContractCanTransitionFromNotFinishedToPending(): void;
    public function testContractCannotTransitionFromDraftToPendingDirectly(): void;
    public function testContractRequiresOrderIdForNotFinishedTransition(): void;
    public function testContractStoresOrderIdOnNotFinishedTransition(): void;
}
```

**Implementation:**
- [ ] Add `STATE_NOT_FINISHED = 'not_finished'` constant
- [ ] Update state machine transitions:
  - `draft` -> `not_finished` (requires orderId)
  - `not_finished` -> `pending`
- [ ] Add `transitionToNotFinished(string $orderId)` method
- [ ] Update `getAllowedTransitions()` method

**Acceptance Criteria:**
- Contract cannot skip from DRAFT to PENDING
- Contract requires orderId for NOT_FINISHED transition
- All existing tests still pass

---

### 1.2 Add ContractDraftCompletedEvent

**Status:** [ ] NOT STARTED

**Test First:**
```php
// tests/Unit/Component/EventSystem/Event/Contract/ContractDraftCompletedEventTest.php
class ContractDraftCompletedEventTest extends TestCase
{
    public function testEventContainsContract(): void;
    public function testEventContainsBasketSnapshot(): void;
    public function testEventIsDispatchable(): void;
}
```

**Implementation:**
- [ ] Create `src/Component/EventSystem/Event/Contract/ContractDraftCompletedEvent.php`
- [ ] Event carries: contract, basketSnapshot
- [ ] Implement `getContract()`, `getBasketSnapshot()` methods

**Acceptance Criteria:**
- Event is immutable
- Event can be dispatched via EventDispatcher
- Event follows existing event patterns in codebase

---

### 1.3 Create EarlyOrderCreationHandler

**Status:** [ ] NOT STARTED

**Test First:**
```php
// tests/Unit/Component/EventSystem/Handler/EarlyOrderCreationHandlerTest.php
class EarlyOrderCreationHandlerTest extends TestCase
{
    public function testHandlerCreatesOrderOnContractDraftCompletedEvent(): void;
    public function testHandlerSetsOrderStatusNotFinished(): void;
    public function testHandlerLinksContractToOrder(): void;
    public function testHandlerTransitionsContractToNotFinished(): void;
    public function testHandlerDispatchesOrderCreatedEvent(): void;
    public function testHandlerDoesNothingIfContractNotInDraftState(): void;
}
```

**Implementation:**
- [ ] Create `src/Component/EventSystem/Handler/EarlyOrderCreationHandler.php`
- [ ] Extends `AbstractHandler` (per handler-abstraction-pattern)
- [ ] Subscribes to `ContractDraftCompletedEvent`
- [ ] Dependencies (injected):
  - `OrderCreationServiceInterface`
  - `ContractRepositoryInterface`
  - `EventDispatcherInterface`

**Handler Logic (pseudocode):**
```php
public function handle(ContractDraftCompletedEvent $event): void
{
    $contract = $event->getContract();

    // Guard: Only process contracts in DRAFT state
    if (!$contract->isInState(PaymentContract::STATE_DRAFT)) {
        return;
    }

    // Create order from basket snapshot
    $order = $this->orderCreationService->createFromBasketSnapshot(
        $event->getBasketSnapshot()
    );

    // Transition contract to NOT_FINISHED with order link
    $contract->transitionToNotFinished($order->getId());
    $this->contractRepository->save($contract);

    // Dispatch OrderCreatedEvent
    $this->eventDispatcher->dispatch(new OrderCreatedEvent($order, $contract));
}
```

**Acceptance Criteria:**
- Handler follows Single Responsibility
- Dependencies injected via constructor
- Guard clause for state check (no else)
- All tests pass

---

### 1.4 Update ContractTransitionToPendingHandler

**Status:** [ ] NOT STARTED

**Test First:**
```php
// tests/Unit/Component/EventSystem/Handler/ContractTransitionToPendingHandlerTest.php
class ContractTransitionToPendingHandlerTest extends TestCase
{
    public function testHandlerTransitionsFromNotFinishedToPending(): void;
    public function testHandlerDoesNotTransitionFromDraftToPending(): void;
    public function testHandlerRequiresOrderIdBeforeTransition(): void;
    public function testHandlerDispatchesContractTransitionedToPendingEvent(): void;
}
```

**Implementation:**
- [ ] Update existing handler or create new one
- [ ] Subscribes to `OrderCreatedEvent`
- [ ] Validates contract is in NOT_FINISHED state
- [ ] Transitions to PENDING

**Acceptance Criteria:**
- Cannot transition from DRAFT directly to PENDING
- Requires contract to have orderId set
- All tests pass

---

### 1.5 Update Event Listeners Registration

**Status:** [ ] NOT STARTED

**Implementation:**
- [ ] Register `EarlyOrderCreationHandler` in `EventListenerProvider`
- [ ] Update `ContractTransitionToPendingHandler` subscription
- [ ] Verify event ordering (draft complete -> order created -> transition to pending)

**Test:**
```php
// tests/Integration/EventSystem/EarlyOrderCreationFlowTest.php
class EarlyOrderCreationFlowTest extends TestCase
{
    public function testFullFlowFromDraftToNotFinishedToPending(): void;
}
```

---

### 1.6 Update services.yaml

**Status:** [ ] NOT STARTED

**Implementation:**
- [ ] Wire `EarlyOrderCreationHandler` with dependencies
- [ ] Add `ContractDraftCompletedEvent` to event subscriber configuration

---

## Definition of Done

- [ ] All new unit tests pass
- [ ] All existing unit tests pass
- [ ] Pre-commit check passes: `./bin/pre-commit-check.sh`
- [ ] PHPStan level 6 passes
- [ ] PHPCS (PSR-12) passes
- [ ] State machine matches updated PUML diagram
- [ ] Integration test verifies full flow

---

## Test Commands

```bash
# Run unit tests for contract
docker compose exec php php vendor/bin/phpunit -c extensions/stripe/tests/phpunit.xml \
  extensions/stripe/tests/Unit/Component/Contract/

# Run specific test file
docker compose exec php php vendor/bin/phpunit -c extensions/stripe/tests/phpunit.xml \
  extensions/stripe/tests/Unit/Component/EventSystem/Handler/EarlyOrderCreationHandlerTest.php

# Run all unit tests
docker compose exec php php vendor/bin/phpunit -c extensions/stripe/tests/phpunit.xml --testsuite Unit

# Pre-commit check
./bin/pre-commit-check.sh
```

---

## Files to Create/Modify

### New Files
- `src/Component/EventSystem/Event/Contract/ContractDraftCompletedEvent.php`
- `src/Component/EventSystem/Handler/EarlyOrderCreationHandler.php`
- `tests/Unit/Component/Contract/PaymentContractStateTransitionTest.php`
- `tests/Unit/Component/EventSystem/Event/Contract/ContractDraftCompletedEventTest.php`
- `tests/Unit/Component/EventSystem/Handler/EarlyOrderCreationHandlerTest.php`
- `tests/Integration/EventSystem/EarlyOrderCreationFlowTest.php`

### Modified Files
- `src/Component/Contract/PaymentContract.php` - Add NOT_FINISHED state and transitions
- `src/Component/EventSystem/Handler/ContractTransitionToPendingHandler.php` - Update subscription
- `src/Component/EventSystem/EventListenerProvider.php` - Register new handler
- `src/services.yaml` - Wire new services

---

## Notes

- This sprint focuses ONLY on the state machine update
- PaymentInitiatedEvent and OrderCancelledEvent after COMMITTED are beyond scope
- Existing order creation logic will be moved/reused, not rewritten
- Follow existing patterns in codebase for consistency

---

## Dependency Injection Pattern

All handlers MUST follow this DI pattern:

```php
final class EarlyOrderCreationHandler extends AbstractHandler
{
    public function __construct(
        private readonly OrderCreationServiceInterface $orderCreationService,
        private readonly ContractRepositoryInterface $contractRepository,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            ContractDraftCompletedEvent::class => 'handle',
        ];
    }

    public function handle(ContractDraftCompletedEvent $event): void
    {
        // Implementation with early returns, no else
    }
}
```

---

## Liskov Substitution Principle Reminder

When creating new event classes:
- Must extend base `Event` class or implement `EventInterface`
- Must be fully substitutable where parent type is expected
- No exceptions or different behavior from base contract

When creating new handlers:
- Must extend `AbstractHandler` if one exists
- Must implement same interface as sibling handlers
- Can add methods but cannot remove or weaken inherited ones
