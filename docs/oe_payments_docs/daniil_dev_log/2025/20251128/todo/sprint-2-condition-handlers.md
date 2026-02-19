# Sprint 2: Condition Handlers

**Sprint Goal:** Implement condition fulfillment handlers that resolve contract conditions
**Estimated Duration:** 2-3 hours
**Status:** NOT STARTED
**Depends On:** Sprint 1 (Contract Infrastructure)

---

## Architecture Reference

### PUML Sources
- `puml/04-02-payment-smart-contract-flow-standard.puml` lines 187-388: Condition resolution (parallel)
- `puml/05-order-state-contract-machine.puml` lines 85-127: PENDING state with condition resolution

### Documentation
- `03-building-payment-modules.md`: Handler patterns
- `architecture/handler-abstraction-pattern.md`: AbstractHandler, SOLID principles

### Key Concept from PUML
```
CONTRACT: PENDING
   │
   ├─► PaymentAuthorizationHandler → fulfills 'payment_authorized'
   ├─► FraudCheckHandler → fulfills 'fraud_check'
   └─► StockReservationHandler → fulfills 'stock_reserved'
   │
   ▼ (All conditions fulfilled)
CONTRACT: READY_TO_COMMIT
```

---

## Test Environment

```bash
# Run unit tests in Docker
docker compose exec php vendor/bin/phpunit tests/Unit/Component/EventSystem/Handler/Condition/

# Run specific test
docker compose exec php vendor/bin/phpunit tests/Unit/Component/EventSystem/Handler/Condition/PaymentAuthorizationConditionHandlerTest.php
```

---

## Tasks

### 2.1 PaymentConfirmedEvent
**Status:** [ ] NOT STARTED

**Test First:**
```php
// tests/Unit/Component/EventSystem/Event/Payment/PaymentConfirmedEventTest.php
class PaymentConfirmedEventTest extends TestCase
{
    public function testEventContainsPaymentIntentId(): void;
    public function testEventContainsContractId(): void;
    public function testEventContainsAmount(): void;
}
```

**Implementation:**
- [ ] Create `src/Component/EventSystem/Event/Payment/PaymentConfirmedEvent.php`
- [ ] Extend from base payment event
- [ ] Include paymentIntentId, amount, currency, contractId

---

### 2.2 PaymentAuthorizationConditionHandler
**Status:** [ ] NOT STARTED

**This is the KEY handler** - triggered when payment is confirmed (webhook, Stripe return, etc.)

**Test First:**
```php
// tests/Unit/Component/EventSystem/Handler/Condition/PaymentAuthorizationConditionHandlerTest.php
class PaymentAuthorizationConditionHandlerTest extends TestCase
{
    public function testFulfillsPaymentAuthorizedCondition(): void;
    public function testStoresPaymentDetailsInCondition(): void;
    public function testDispatchesContractReadyToCommitWhenAllConditionsFulfilled(): void;
    public function testDoesNotDispatchReadyToCommitWhenConditionsRemaining(): void;
    public function testIgnoresNonPaymentConfirmedEvents(): void;
}
```

**Implementation:**
```php
class PaymentAuthorizationConditionHandler extends AbstractHandler
{
    public function handle(object $event): void
    {
        if (!$event instanceof PaymentConfirmedEvent) {
            return;
        }

        $context = $event->getContext();
        $contract = $this->contractRepository->find($context->get('contractId'));

        // Fulfill the payment_authorized condition
        $contract->fulfillCondition('payment_authorized', [
            'payment_intent_id' => $context->get('paymentIntentId'),
            'amount' => $context->get('amount'),
            'currency' => $context->get('currency'),
        ]);

        $this->contractRepository->save($contract);

        // Check if all conditions are now fulfilled
        if ($contract->areAllConditionsFulfilled()) {
            $contract->transitionToReadyToCommit();
            $this->contractRepository->save($contract);

            $context->setContract($contract);
            $this->eventDispatcher->dispatch(new ContractReadyToCommitEvent($context, $contract));
        }
    }
}
```

**Acceptance Criteria:**
- Fulfills 'payment_authorized' condition
- Stores payment details in condition data
- Checks if all conditions fulfilled
- Dispatches ContractReadyToCommitEvent when ready

---

### 2.3 FraudCheckConditionHandler (Auto-Pass for MVP)
**Status:** [ ] NOT STARTED

For MVP, fraud check auto-passes. Can be extended later.

**Test First:**
```php
// tests/Unit/Component/EventSystem/Handler/Condition/FraudCheckConditionHandlerTest.php
class FraudCheckConditionHandlerTest extends TestCase
{
    public function testAutoPassesFraudCheckOnContractCreated(): void;
    public function testDispatchesConditionFulfilledEvent(): void;
}
```

**Implementation:**
```php
class FraudCheckConditionHandler extends AbstractHandler
{
    public function handle(object $event): void
    {
        if (!$event instanceof ContractCreatedEvent) {
            return;
        }

        $contract = $event->getContract();

        // Auto-pass fraud check for MVP
        $contract->fulfillCondition('fraud_check', [
            'result' => 'auto_passed',
            'score' => 100,
            'reason' => 'MVP auto-pass',
        ]);

        $this->contractRepository->save($contract);

        $this->eventDispatcher?->dispatch(
            new ContractConditionFulfilledEvent($event->getContext(), 'fraud_check')
        );
    }
}
```

---

### 2.4 StockReservationConditionHandler (Auto-Pass for MVP)
**Status:** [ ] NOT STARTED

For MVP, stock reservation auto-passes. Real implementation would reserve inventory.

**Test First:**
```php
// tests/Unit/Component/EventSystem/Handler/Condition/StockReservationConditionHandlerTest.php
class StockReservationConditionHandlerTest extends TestCase
{
    public function testAutoPassesStockReservationOnContractCreated(): void;
    public function testDispatchesConditionFulfilledEvent(): void;
}
```

**Implementation:**
```php
class StockReservationConditionHandler extends AbstractHandler
{
    public function handle(object $event): void
    {
        if (!$event instanceof ContractCreatedEvent) {
            return;
        }

        $contract = $event->getContract();

        // Auto-pass stock reservation for MVP
        // Real implementation would call InventoryService
        $contract->fulfillCondition('stock_reserved', [
            'result' => 'auto_passed',
            'reservation_id' => 'mvp_' . uniqid(),
        ]);

        $this->contractRepository->save($contract);

        $this->eventDispatcher?->dispatch(
            new ContractConditionFulfilledEvent($event->getContext(), 'stock_reserved')
        );
    }
}
```

---

### 2.5 ContractConditionFulfilledEvent
**Status:** [ ] NOT STARTED

**Test First:**
```php
// tests/Unit/Component/EventSystem/Event/Contract/ContractConditionFulfilledEventTest.php
class ContractConditionFulfilledEventTest extends TestCase
{
    public function testEventContainsConditionType(): void;
    public function testEventContainsContext(): void;
}
```

**Implementation:**
- [ ] Create `src/Component/EventSystem/Event/Contract/ContractConditionFulfilledEvent.php`

---

## Handler Registration

Register handlers to listen for appropriate events:

```yaml
# services.yaml
services:
    OxidSolutionCatalysts\Payments\Component\EventSystem\Handler\Condition\PaymentAuthorizationConditionHandler:
        tags:
            - { name: 'payment.event_handler', event: 'PaymentConfirmedEvent' }

    OxidSolutionCatalysts\Payments\Component\EventSystem\Handler\Condition\FraudCheckConditionHandler:
        tags:
            - { name: 'payment.event_handler', event: 'ContractCreatedEvent' }

    OxidSolutionCatalysts\Payments\Component\EventSystem\Handler\Condition\StockReservationConditionHandler:
        tags:
            - { name: 'payment.event_handler', event: 'ContractCreatedEvent' }
```

---

## Definition of Done

- [ ] All tests pass: `docker compose exec php vendor/bin/phpunit tests/Unit/Component/EventSystem/Handler/Condition/`
- [ ] Pre-commit check passes
- [ ] PaymentAuthorizationConditionHandler fulfills condition on PaymentConfirmedEvent
- [ ] FraudCheck and StockReservation auto-pass on ContractCreatedEvent
- [ ] ContractReadyToCommitEvent dispatched when all conditions met
- [ ] Handlers extend AbstractHandler

---

## Files Created/Modified

### New Files
- `src/Component/EventSystem/Event/Payment/PaymentConfirmedEvent.php`
- `src/Component/EventSystem/Event/Contract/ContractConditionFulfilledEvent.php`
- `src/Component/EventSystem/Handler/Condition/PaymentAuthorizationConditionHandler.php`
- `src/Component/EventSystem/Handler/Condition/FraudCheckConditionHandler.php`
- `src/Component/EventSystem/Handler/Condition/StockReservationConditionHandler.php`
- `tests/Unit/Component/EventSystem/Handler/Condition/PaymentAuthorizationConditionHandlerTest.php`
- `tests/Unit/Component/EventSystem/Handler/Condition/FraudCheckConditionHandlerTest.php`
- `tests/Unit/Component/EventSystem/Handler/Condition/StockReservationConditionHandlerTest.php`

### Modified Files
- `services.yaml` (register handlers)

---

## Notes

- FraudCheck and StockReservation are MVP auto-pass implementations
- Real fraud check would call FraudService (see PUML lines 270-318)
- Real stock reservation would call InventoryService (see PUML lines 320-387)
- Payment authorization is the critical condition - others can be auto-passed initially
