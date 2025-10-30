# SPRINT 1 - TICKET 07: Event Dispatcher & Contract Lifecycle Handlers

**Project:** OXID eShop 7 Payment Component - Stripe Module
**Sprint:** 1
**Ticket:** 07
**Status:** 🟡 READY TO START
**Priority:** HIGH
**Estimated Effort:** 8-12 hours
**Dependencies:** TICKET-06 (Contract Layer) ✅ COMPLETE

---

## 🎯 Objective

Implement PSR-14 compliant Event Dispatcher and Contract Lifecycle Event Handlers using TDD-first approach to orchestrate the complete payment contract workflow from creation to fulfillment.

---

## 📋 Requirements

### Functional Requirements

1. **PSR-14 Event Dispatcher**
   - Dispatch domain events to registered listeners
   - Support priority-based listener execution
   - Handle stoppable events
   - Provide listener registration/removal

2. **Contract Lifecycle Handlers**
   - ContractCreationHandler - Create contracts from basket
   - ContractConditionResolverHandler - Start condition resolution
   - PaymentAuthorizationHandler - Authorize payment condition
   - FraudCheckHandler - Validate fraud check condition
   - StockReservationHandler - Reserve inventory condition
   - OrderCreationHandler - Create OXID order when ready
   - ContractFulfillmentHandler - Fulfill contract on capture
   - ContractCleanupHandler - Handle cancellation/expiration

3. **State Machine Integration**
   - Handlers trigger state transitions
   - Events emitted on state changes
   - Automatic state progression based on conditions

### Non-Functional Requirements

- **TDD-First:** All tests written before implementation
- **SOLID:** Each handler has single responsibility
- **Clean Code:** No redundant comments
- **Extendable:** No final classes (other modules can extend)
- **Fast Tests:** Unit tests < 1 second total
- **100% Coverage:** All code paths tested

---

## 🏗️ Architecture

### Event Flow Diagram

```
┌─────────────────────────────────────────────────────────────────┐
│                    PAYMENT INITIATION                            │
│  User clicks "Place Order" → PaymentInitiatedEvent              │
└────────────────────────┬────────────────────────────────────────┘
                         │
                         ▼
┌─────────────────────────────────────────────────────────────────┐
│               ContractCreationHandler                            │
│  1. Create basket snapshot                                       │
│  2. Create PaymentContract (DRAFT)                              │
│  3. Add conditions (payment_authorized, fraud_check)            │
│  4. Emit ContractCreatedEvent                                    │
└────────────────────────┬────────────────────────────────────────┘
                         │
                         ▼
┌─────────────────────────────────────────────────────────────────┐
│          ContractConditionResolverHandler                        │
│  1. Validate contract has conditions                             │
│  2. Transition to PENDING                                        │
│  3. Emit ContractTransitionedToPendingEvent                      │
└────────────────────────┬────────────────────────────────────────┘
                         │
            ┌────────────┴────────────┐
            │                         │
            ▼                         ▼
┌──────────────────────┐  ┌──────────────────────┐
│ PaymentAuthHandler   │  │ FraudCheckHandler    │
│ Fulfill condition    │  │ Fulfill condition    │
└──────────┬───────────┘  └──────────┬───────────┘
           │                         │
           └──────────┬──────────────┘
                      │
                      ▼
         All conditions fulfilled?
                      │
                      ▼
┌─────────────────────────────────────────────────────────────────┐
│              OrderCreationHandler                                │
│  1. Create OXID order from contract snapshot                    │
│  2. Assign order number                                          │
│  3. Contract.commitToOrder(orderId)                             │
│  4. Emit ContractCommittedEvent                                  │
└────────────────────────┬────────────────────────────────────────┘
                         │
                         ▼
               ⏳ Waiting for webhook...
                         │
                         ▼
┌─────────────────────────────────────────────────────────────────┐
│           ContractFulfillmentHandler                             │
│  (Triggered by WebhookReceivedEvent)                            │
│  1. Validate payment captured                                    │
│  2. Contract.fulfill()                                           │
│  3. Update order status to OK                                    │
│  4. Emit ContractFulfilledEvent                                  │
└─────────────────────────────────────────────────────────────────┘
```

---

## 📝 TDD Task Breakdown

### Phase 1: Event Dispatcher (PSR-14)

#### Task 1.1: EventDispatcher Interface & Tests
**File:** `tests/Unit/Component/EventSystem/EventDispatcherTest.php`

```php
public function testDispatchEventToRegisteredListener(): void
public function testDispatchEventToMultipleListeners(): void
public function testListenersExecutedInPriorityOrder(): void
public function testStoppableEventStopsExecution(): void
public function testRemoveListener(): void
public function testNoListenersRegistered(): void
```

**Implementation:** `src/Component/EventSystem/EventDispatcher.php`
- Implements PSR-14 `EventDispatcherInterface`
- Priority-based listener queue
- Stoppable event support

**Tests to Pass:** 6 tests minimum

---

#### Task 1.2: ListenerProvider Interface & Tests
**File:** `tests/Unit/Component/EventSystem/ListenerProviderTest.php`

```php
public function testGetListenersForEvent(): void
public function testGetListenersReturnsEmptyForUnknownEvent(): void
public function testListenersSortedByPriority(): void
```

**Implementation:** `src/Component/EventSystem/ListenerProvider.php`
- Implements PSR-14 `ListenerProviderInterface`
- Maps event types to listeners
- Priority management

**Tests to Pass:** 3 tests minimum

---

### Phase 2: Contract Creation Handler

#### Task 2.1: ContractCreationHandler Tests
**File:** `tests/Unit/Component/EventSystem/Handler/ContractCreationHandlerTest.php`

```php
public function testHandleCreatesContract(): void
public function testHandleAddsDefaultConditions(): void
public function testHandleAddsCustomConditions(): void
public function testHandleSavesContract(): void
public function testHandleEmitsContractCreatedEvent(): void
public function testHandleThrowsExceptionWhenBasketEmpty(): void
```

**Implementation:** `src/Component/EventSystem/Handler/ContractCreationHandler.php`

**Tests to Pass:** 6 tests minimum

---

### Phase 3: Condition Resolution Handlers

#### Task 3.1: ContractConditionResolverHandler Tests
**File:** `tests/Unit/Component/EventSystem/Handler/ContractConditionResolverHandlerTest.php`

```php
public function testTransitionsContractToPending(): void
public function testEmitsPendingEvent(): void
public function testThrowsExceptionWhenNoConditions(): void
public function testThrowsExceptionWhenAlreadyPending(): void
```

**Implementation:** `src/Component/EventSystem/Handler/ContractConditionResolverHandler.php`

**Tests to Pass:** 4 tests minimum

---

#### Task 3.2: PaymentAuthorizationHandler Tests
**File:** `tests/Unit/Component/EventSystem/Handler/PaymentAuthorizationHandlerTest.php`

```php
public function testFulfillsPaymentAuthorizedCondition(): void
public function testSetsProviderOrderId(): void
public function testEmitsReadyToCommitWhenAllFulfilled(): void
public function testDoesNotEmitWhenOtherConditionsPending(): void
public function testFailsConditionOnPaymentDeclined(): void
public function testEmitsContractFailedEventOnError(): void
```

**Implementation:** `src/Component/EventSystem/Handler/PaymentAuthorizationHandler.php`

**Tests to Pass:** 6 tests minimum

---

#### Task 3.3: FraudCheckHandler Tests
**File:** `tests/Unit/Component/EventSystem/Handler/FraudCheckHandlerTest.php`

```php
public function testFulfillsFraudCheckCondition(): void
public function testStoreFraudScoreInConditionData(): void
public function testFailsConditionOnHighRisk(): void
public function testEmitsReadyToCommitWhenAllFulfilled(): void
```

**Implementation:** `src/Component/EventSystem/Handler/FraudCheckHandler.php`

**Tests to Pass:** 4 tests minimum

---

#### Task 3.4: StockReservationHandler Tests (Optional)
**File:** `tests/Unit/Component/EventSystem/Handler/StockReservationHandlerTest.php`

```php
public function testFulfillsStockReservedCondition(): void
public function testStoresReservationId(): void
public function testFailsWhenStockUnavailable(): void
```

**Implementation:** `src/Component/EventSystem/Handler/StockReservationHandler.php`

**Tests to Pass:** 3 tests minimum

---

### Phase 4: Order Creation Handler

#### Task 4.1: OrderCreationHandler Tests
**File:** `tests/Unit/Component/EventSystem/Handler/OrderCreationHandlerTest.php`

```php
public function testCreatesOrderFromContract(): void
public function testAssignsOrderNumber(): void
public function testCommitsContractToOrder(): void
public function testSavesOrder(): void
public function testEmitsContractCommittedEvent(): void
public function testThrowsExceptionWhenNotReadyToCommit(): void
public function testThrowsExceptionWhenConditionsNotFulfilled(): void
```

**Implementation:** `src/Component/EventSystem/Handler/OrderCreationHandler.php`

**Tests to Pass:** 7 tests minimum

---

### Phase 5: Contract Fulfillment & Cleanup

#### Task 5.1: ContractFulfillmentHandler Tests
**File:** `tests/Unit/Component/EventSystem/Handler/ContractFulfillmentHandlerTest.php`

```php
public function testFulfillsContract(): void
public function testUpdatesOrderStatus(): void
public function testEmitsFulfilledEvent(): void
public function testOnlyFulfillsCommittedContract(): void
public function testIgnoresNonCaptureWebhooks(): void
```

**Implementation:** `src/Component/EventSystem/Handler/ContractFulfillmentHandler.php`

**Tests to Pass:** 5 tests minimum

---

#### Task 5.2: ContractCleanupHandler Tests
**File:** `tests/Unit/Component/EventSystem/Handler/ContractCleanupHandlerTest.php`

```php
public function testCancelsContractOnCancelledEvent(): void
public function testExpiresContractOnExpiredEvent(): void
public function testReleasesReservationsOnCleanup(): void
public function testDoesNotCleanupFulfilledContract(): void
```

**Implementation:** `src/Component/EventSystem/Handler/ContractCleanupHandler.php`

**Tests to Pass:** 4 tests minimum

---

## 🧪 Integration Tests

### Phase 6: End-to-End Contract Lifecycle

**File:** `tests/Integration/Component/EventSystem/ContractLifecycleTest.php`

```php
public function testCompleteContractLifecycleHappyPath(): void
{
    // 1. Dispatch PaymentInitiatedEvent
    // 2. Assert contract created (DRAFT)
    // 3. Assert contract transitioned to PENDING
    // 4. Assert conditions fulfilled
    // 5. Assert contract ready to commit
    // 6. Assert order created
    // 7. Assert contract committed
    // 8. Dispatch webhook event
    // 9. Assert contract fulfilled
    // 10. Assert order status OK
}

public function testContractFailureWhenPaymentDeclined(): void
public function testContractCancellationFlow(): void
public function testContractExpirationFlow(): void
```

**Tests to Pass:** 4 integration tests minimum

---

## 📐 Implementation Guidelines

### Handler Structure Template

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Component\EventSystem\Handler;

use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\EventInterface;
use OxidSolutionCatalysts\Payments\Component\Contract\PaymentContract;
use OxidSolutionCatalysts\Payments\Component\Repository\ContractRepository;
use OxidSolutionCatalysts\Payments\Component\EventSystem\EventDispatcher;

class ExampleHandler
{
    public function __construct(
        private ContractRepository $contractRepository,
        private EventDispatcher $eventDispatcher
    ) {
    }

    public function handle(EventInterface $event): void
    {
        // 1. Extract data from event
        // 2. Load contract if needed
        // 3. Apply business logic
        // 4. Update contract state
        // 5. Save contract
        // 6. Emit new events if needed
    }
}
```

### Key Principles

1. **Single Responsibility:** Each handler does ONE thing
2. **No Business Logic in Events:** Events are data carriers
3. **Handlers Orchestrate:** Handlers call services, not vice versa
4. **Always Save:** After modifying contract, always save
5. **Emit Events:** State changes should emit corresponding events
6. **Error Handling:** Fail gracefully, emit FailedEvent

---

## ✅ Acceptance Criteria

### Definition of Done

- [ ] EventDispatcher implements PSR-14
- [ ] All 8 handlers implemented with tests
- [ ] Minimum 40 unit tests passing
- [ ] 4 integration tests passing
- [ ] 100% code coverage for handlers
- [ ] All tests run in < 2 seconds
- [ ] No final classes (extendable)
- [ ] No redundant comments
- [ ] Documentation updated
- [ ] State machine diagram validated

### Test Coverage Requirements

| Component | Min Tests | Min Assertions |
|-----------|-----------|----------------|
| EventDispatcher | 6 | 15 |
| ListenerProvider | 3 | 8 |
| ContractCreationHandler | 6 | 12 |
| ConditionResolverHandler | 4 | 8 |
| PaymentAuthHandler | 6 | 12 |
| FraudCheckHandler | 4 | 8 |
| StockReservationHandler | 3 | 6 |
| OrderCreationHandler | 7 | 14 |
| FulfillmentHandler | 5 | 10 |
| CleanupHandler | 4 | 8 |
| Integration Tests | 4 | 20 |
| **TOTAL** | **52** | **121** |

---

## 🔗 Dependencies

### Completed (✅)
- TICKET-06: Contract Layer (PaymentContract, ContractState, ContractCondition)
- Event interfaces (18 events)
- EventContext implementation

### Required for This Ticket
- PSR-14 interfaces (via Composer)
- Mock payment service (for testing)
- Mock order factory (for testing)

### Blocks
- TICKET-08: Real payment adapter integration
- TICKET-09: Webhook controller implementation

---

## 📊 Testing Strategy

### Unit Test Approach

```php
// Test Pattern: Arrange-Act-Assert
public function testHandlerFulfillsCondition(): void
{
    // Arrange: Create mock dependencies
    $contract = $this->createTestContract();
    $event = new ContractTransitionedToPendingEvent($contract, $context);

    $repository = $this->createMock(ContractRepository::class);
    $repository->expects($this->once())
        ->method('save')
        ->with($this->callback(function($contract) {
            return $contract->areAllConditionsFulfilled();
        }));

    $handler = new PaymentAuthorizationHandler($repository, ...);

    // Act: Handle event
    $handler->handle($event);

    // Assert: Verify condition fulfilled
    $this->assertTrue($contract->getConditions()[0]->isFulfilled());
}
```

### Integration Test Approach

```php
public function testCompleteLifecycle(): void
{
    // Real implementations, no mocks
    $dispatcher = new EventDispatcher();
    $repository = new InMemoryContractRepository();

    // Register all handlers
    $dispatcher->addListener(
        PaymentInitiatedEvent::class,
        new ContractCreationHandler($repository, ...)
    );

    // ... register other handlers

    // Execute workflow
    $event = new PaymentInitiatedEvent(...);
    $dispatcher->dispatch($event);

    // Assert final state
    $contract = $repository->findById($contractId);
    $this->assertTrue($contract->getState()->isFulfilled());
}
```

---

## 🚀 Execution Plan

### Day 1: Event Dispatcher (3 hours)
- [ ] Write EventDispatcher tests (1h)
- [ ] Implement EventDispatcher (1h)
- [ ] Write ListenerProvider tests (0.5h)
- [ ] Implement ListenerProvider (0.5h)

### Day 2: Contract Creation & Resolution (3 hours)
- [ ] ContractCreationHandler TDD (1.5h)
- [ ] ContractConditionResolverHandler TDD (1.5h)

### Day 3: Condition Handlers (3 hours)
- [ ] PaymentAuthorizationHandler TDD (1.5h)
- [ ] FraudCheckHandler TDD (1h)
- [ ] StockReservationHandler TDD (0.5h)

### Day 4: Order & Fulfillment (2 hours)
- [ ] OrderCreationHandler TDD (1h)
- [ ] ContractFulfillmentHandler TDD (0.5h)
- [ ] ContractCleanupHandler TDD (0.5h)

### Day 5: Integration & Validation (1 hour)
- [ ] Write integration tests (0.5h)
- [ ] Run all tests (0.25h)
- [ ] Update documentation (0.25h)

**Total Estimated Effort:** 12 hours

---

## 📚 Reference Documentation

- **Architecture:** `docs/payment-component/01-architecture-layers.md`
- **State Machine:** `docs/payment-component/puml/05-order-state-contract-machine.puml`
- **Contract Layer:** `docs/payment-component/SPRINT-1-TICKET-06-payment-contract-layer.md`
- **PSR-14 Spec:** https://www.php-fig.org/psr/psr-14/

---

## 🎯 Success Metrics

- ✅ All 52+ unit tests passing
- ✅ All 4 integration tests passing
- ✅ 100% code coverage on handlers
- ✅ Test execution < 2 seconds
- ✅ Zero PHPStan/Psalm errors
- ✅ Contract lifecycle validated end-to-end

---

## 🔄 Rollback Plan

If implementation blocked:
1. Event Dispatcher can use simple array-based implementation
2. Handlers can be simplified (combine some)
3. Integration tests can be deferred
4. Minimum viable: ContractCreation + OrderCreation handlers only

---

**Status:** 🟡 READY TO START
**Next Steps:** Begin Phase 1 - EventDispatcher TDD
**Assignee:** Development Team
**Review Required:** After Phase 3 completion

*Created: 2025-10-30*
*Updated: 2025-10-30*
