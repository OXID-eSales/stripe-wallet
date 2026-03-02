# Controller → EventSystem Integration Tests

**Date:** 2025-11-26
**Status:** Completed
**Test File:** `tests/Integration/Component/Controller/ControllerEventSystemIntegrationTest.php`

## Overview

Created comprehensive integration tests that verify the complete flow from OXID Controllers through the EventSystem to the Handler chain, ensuring events are triggered and correct handlers are executed.

## Test Coverage

### OrderController Flow (9 tests)

| Test | Description |
|------|-------------|
| `testOrderControllerFlow_DispatchesPaymentInitiatedEvent` | Verifies `PaymentInitiatedEvent` is dispatched when order is executed |
| `testOrderControllerFlow_TriggersContractCreationHandler` | Verifies `ContractCreationHandler` is called |
| `testOrderControllerFlow_CreatesContractInDatabase` | Verifies contract is persisted to database |
| `testOrderControllerFlow_DispatchesContractCreatedEvent` | Verifies `ContractCreatedEvent` is dispatched after contract creation |
| `testOrderControllerFlow_TriggersConditionResolverHandler` | Verifies `ContractConditionResolverHandler` is called |
| `testOrderControllerFlow_TransitionsContractToPending` | Verifies contract transitions to PENDING state |
| `testOrderControllerFlow_TriggersPaymentAuthorizationHandler` | Verifies `PaymentAuthorizationHandler` is called |
| `testOrderControllerFlow_ExecutesHandlersInCorrectOrder` | Verifies handler execution order |
| `testOrderControllerFlow_DispatchesEventsInCorrectOrder` | Verifies event dispatch order |

### ThankyouController Flow (2 tests)

| Test | Description |
|------|-------------|
| `testThankyouControllerFlow_DispatchesOrderCompletedEvent` | Verifies `OrderCompletedEvent` is dispatched on thank you page |
| `testThankyouControllerFlow_WithoutContractId_DoesNotDispatchEvent` | Verifies no event when contract ID missing |

### Complete Flow Tests (3 tests)

| Test | Description |
|------|-------------|
| `testCompleteFlow_OrderToThankyou_ExecutesAllHandlers` | Full flow from OrderController to ThankyouController |
| `testCompleteFlow_ContractStatePersistsInDatabase` | Verifies contract state changes persist in DB |
| `testEventContext_CarriesDataThroughHandlerChain` | Verifies context data propagates through handlers |

### Edge Cases (2 tests)

| Test | Description |
|------|-------------|
| `testOrderControllerFlow_WithEmptyBasket_DoesNotDispatchEvents` | No events for empty basket |
| `testOrderControllerFlow_WithInvalidUser_DoesNotDispatchEvents` | No events for invalid user |

**Total: 16 tests, 43 assertions**

## Test Results

```
Controller Event System Integration
 ✔ OrderControllerFlow DispatchesPaymentInitiatedEvent
 ✔ OrderControllerFlow TriggersContractCreationHandler
 ✔ OrderControllerFlow CreatesContractInDatabase
 ✔ OrderControllerFlow DispatchesContractCreatedEvent
 ✔ OrderControllerFlow TriggersConditionResolverHandler
 ✔ OrderControllerFlow TransitionsContractToPending
 ✔ OrderControllerFlow TriggersPaymentAuthorizationHandler
 ✔ OrderControllerFlow ExecutesHandlersInCorrectOrder
 ✔ OrderControllerFlow DispatchesEventsInCorrectOrder
 ✔ ThankyouControllerFlow DispatchesOrderCompletedEvent
 ✔ ThankyouControllerFlow WithoutContractId DoesNotDispatchEvent
 ✔ CompleteFlow OrderToThankyou ExecutesAllHandlers
 ✔ CompleteFlow ContractStatePersistsInDatabase
 ✔ OrderControllerFlow WithEmptyBasket DoesNotDispatchEvents
 ✔ OrderControllerFlow WithInvalidUser DoesNotDispatchEvents
 ✔ EventContext CarriesDataThroughHandlerChain

OK (16 tests, 43 assertions)
```

## Architecture Verified

### Event Flow Chain

```
OrderController::execute()
    ↓
CheckoutOrchestrator::processCheckout()
    ↓
EventDispatcher::dispatch(PaymentInitiatedEvent)
    ↓
ContractCreationHandler::handle()
    → Creates Contract (DRAFT)
    → Dispatches ContractCreatedEvent
    ↓
ContractConditionResolverHandler::handle()
    → Resolves conditions
    → Transitions to PENDING
    → Dispatches ContractTransitionedToPendingEvent
    ↓
PaymentAuthorizationHandler::handle()
    → Verifies payment authorization
    → Transitions to READY_TO_COMMIT
    ↓
OrderCreationHandler::handle()
    → Creates order
    → Transitions to COMMITTED
```

### ThankyouController Flow

```
ThankyouController::render()
    ↓
CheckoutOrchestrator::processOrderCompletion()
    ↓
EventDispatcher::dispatch(OrderCompletedEvent)
    ↓
ContractFulfillmentHandler::handle()
    → Verifies order completion
    → Transitions to FULFILLED
```

## Key Implementation Details

### Handler Execution Tracking

The test uses a custom EventDispatcher subclass to track event dispatch:

```php
$this->eventDispatcher = new class($this->listenerProvider, $testCase) extends EventDispatcher {
    private ControllerEventSystemIntegrationTest $testCase;

    public function dispatch(EventInterface $event): EventInterface
    {
        $this->testCase->logEventDispatched($event);
        return parent::dispatch($event);
    }
};
```

### Handler Registration via EventListenerProvider

Uses real `EventListenerProvider` to register handlers:

```php
$this->listenerProvider->addListener(
    PaymentInitiatedEvent::class,
    [$this->contractCreationHandler, 'handle']
);

$this->listenerProvider->addListener(
    ContractCreatedEvent::class,
    [$this->conditionResolverHandler, 'handle']
);
```

### Event Order Verification

```php
$this->assertEquals([
    PaymentInitiatedEvent::class,
    ContractCreatedEvent::class,
    ContractTransitionedToPendingEvent::class,
    ContractReadyToCommitEvent::class,
], $this->dispatchedEvents);
```

### Handler Order Verification

```php
$this->assertEquals([
    ContractCreationHandler::class,
    ContractConditionResolverHandler::class,
    PaymentAuthorizationHandler::class,
    OrderCreationHandler::class,
], $this->handlerExecutionOrder);
```

## Running the Tests

```bash
# Run only controller-eventsystem integration tests
docker compose exec php php vendor/bin/phpunit -c extensions/stripe/tests/phpunit.xml \
    extensions/stripe/tests/Integration/Component/Controller/ControllerEventSystemIntegrationTest.php --testdox

# Run all integration tests
./bin/pre-commit-check.sh --full
```

## Related Documentation

- Architecture: `docs/payment-component/01-architecture-layers.md`
- Event System: `docs/payment-component/08-event-system.md`
- Flow Diagram: `docs/payment-component/puml/04-02-payment-smart-contract-flow-standard.puml`
- Handlers: `docs/payment-component/09-event-handlers.md`
- Full Data Persistence Test: `docs/payment-component/daniil_dev_log/20251126/FULL-DATA-PERSISTENCE-TEST.md`
- E2E Checkout Test: `docs/payment-component/daniil_dev_log/20251126/E2E-CHECKOUT-FLOW-TEST.md`

## Files Created

| File | Description |
|------|-------------|
| `tests/Integration/Component/Controller/ControllerEventSystemIntegrationTest.php` | 16 integration tests |

## Pre-Commit Results

```
======================================
SUMMARY
======================================

✓ ALL CHECKS PASSED
Status: COMMITABLE

Tests: 932, Assertions: 2562, Skipped: 62, Incomplete: 1
```
