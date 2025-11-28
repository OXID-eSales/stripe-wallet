# Sprint 3: Order Creation Handler

**Sprint Goal:** Implement order creation from contract when all conditions fulfilled
**Estimated Duration:** 2-3 hours
**Status:** NOT STARTED
**Depends On:** Sprint 1 (Contract), Sprint 2 (Conditions)

---

## Architecture Reference

### PUML Sources
- `puml/04-02-payment-smart-contract-flow-standard.puml` lines 390-497: Order creation from contract
- `puml/05-order-state-contract-machine.puml` lines 164-206: CONTRACT_COMMITTED state

### Documentation
- `01-architecture-layers.md`: Service layer patterns
- `04-sdk-adapter-layer.md`: ShopOrderService pattern

### Key Concept from PUML (lines 390-440)
```
CONTRACT: READY_TO_COMMIT
   │
   ▼
OrderCreationHandler triggered
   │
   ├─► Creates oxorder (state: NOT_FINISHED)
   ├─► Assigns order number
   ├─► Links contract to order
   └─► Contract.commitToOrder(orderId)
   │
   ▼
CONTRACT: COMMITTED
   • Contract.OXORDERID = order.OXID
```

---

## Test Environment

```bash
# Run unit tests in Docker
docker compose exec php vendor/bin/phpunit tests/Unit/Component/EventSystem/Handler/OrderCreationHandlerTest.php

# Run with coverage
docker compose exec php vendor/bin/phpunit --coverage-html coverage tests/Unit/Component/EventSystem/Handler/OrderCreationHandlerTest.php
```

---

## Tasks

### 3.1 OrderFactory.createFromContract()
**Status:** [ ] NOT STARTED

**Test First:**
```php
// tests/Unit/Component/Order/OrderFactoryTest.php
class OrderFactoryTest extends TestCase
{
    public function testCreatesOrderFromContractBasketSnapshot(): void;
    public function testOrderHasCorrectTotals(): void;
    public function testOrderHasUserData(): void;
    public function testOrderStateIsNotFinished(): void;
    public function testOrderNumberIsAssigned(): void;
}
```

**Implementation:**
```php
interface OrderFactoryInterface
{
    public function createFromContract(PaymentContractInterface $contract): Order;
}

class OrderFactory implements OrderFactoryInterface
{
    public function createFromContract(PaymentContractInterface $contract): Order
    {
        $basketSnapshot = $contract->getBasketSnapshot();

        // Create Order from basket snapshot
        $order = oxNew(Order::class);

        // Set order data from snapshot
        $order->assign([
            'oxuserid' => $contract->getUserId(),
            // ... other fields from basket snapshot
        ]);

        // Order state: NOT_FINISHED (payment confirmed but not captured)
        $order->setFieldData('oxtransstatus', 'NOT_FINISHED');

        // Save to get order number
        $order->save();

        return $order;
    }
}
```

---

### 3.2 OrderCreationHandler
**Status:** [ ] NOT STARTED

**This is the KEY handler** - creates oxorder ONLY when contract is ready to commit.

**Test First:**
```php
// tests/Unit/Component/EventSystem/Handler/OrderCreationHandlerTest.php
class OrderCreationHandlerTest extends TestCase
{
    public function testCreatesOrderOnContractReadyToCommitEvent(): void;
    public function testUsesContractBasketSnapshot(): void;
    public function testLinksOrderToContract(): void;
    public function testTransitionsContractToCommitted(): void;
    public function testDispatchesOrderCreatedFromContractEvent(): void;
    public function testSetsOrderIdInContext(): void;
    public function testThrowsExceptionIfContractNotReady(): void;
    public function testIgnoresNonContractReadyToCommitEvents(): void;
}
```

**Implementation:**
```php
class OrderCreationHandler extends AbstractHandler
{
    public function __construct(
        ContractRepository $contractRepository,
        private OrderFactoryInterface $orderFactory,
        private OrderRepositoryInterface $orderRepository,
        ?EventDispatcher $eventDispatcher = null
    ) {
        parent::__construct($contractRepository, $eventDispatcher);
    }

    public function handle(object $event): void
    {
        // ONLY triggered when ALL conditions are fulfilled
        if (!$event instanceof ContractReadyToCommitEvent) {
            return;
        }

        $context = $event->getContext();
        $contract = $event->getContract();

        // Verify contract is ready
        if (!$contract->isReadyToCommit()) {
            throw new \RuntimeException('Contract not ready to commit');
        }

        // Create order from contract's basket snapshot
        $order = $this->orderFactory->createFromContract($contract);

        // Save order
        $this->orderRepository->save($order);

        // Link contract to order - THIS IS THE KEY MOMENT
        $contract->commitToOrder($order->getId());
        $this->contractRepository->save($contract);

        // Update context for subsequent handlers
        $context->set('orderId', $order->getId());
        $context->set('orderNumber', $order->getOrderNumber());
        $context->set('redirectTarget', 'thankyou');

        // Dispatch OrderCreatedFromContractEvent
        $this->eventDispatcher?->dispatch(new OrderCreatedFromContractEvent(
            $context,
            $order->getId(),
            $contract->getId()
        ));

        Registry::getLogger()->info('Order created from contract', [
            'order_id' => $order->getId(),
            'order_number' => $order->getOrderNumber(),
            'contract_id' => $contract->getId(),
        ]);
    }
}
```

---

### 3.3 OrderCreatedFromContractEvent
**Status:** [ ] NOT STARTED

**Test First:**
```php
// tests/Unit/Component/EventSystem/Event/Order/OrderCreatedFromContractEventTest.php
class OrderCreatedFromContractEventTest extends TestCase
{
    public function testEventContainsOrderId(): void;
    public function testEventContainsContractId(): void;
    public function testEventContainsContext(): void;
}
```

**Implementation:**
```php
readonly class OrderCreatedFromContractEvent implements OrderEventInterface
{
    public function __construct(
        private EventContext $context,
        private string $orderId,
        private string $contractId
    ) {}

    public function getContext(): EventContext { return $this->context; }
    public function getOrderId(): string { return $this->orderId; }
    public function getContractId(): string { return $this->contractId; }
}
```

---

### 3.4 Contract.commitToOrder() Method
**Status:** [ ] NOT STARTED

**Test First:**
```php
// tests/Unit/Component/Contract/PaymentContractTest.php (add to Sprint 1 tests)
public function testCommitToOrderSetsOrderId(): void;
public function testCommitToOrderTransitionsToCommitted(): void;
public function testCommitToOrderFailsIfNotReadyToCommit(): void;
public function testCommitToOrderSetsCommittedTimestamp(): void;
```

**Implementation:**
```php
class PaymentContract
{
    public function commitToOrder(string $orderId): void
    {
        if ($this->state !== ContractState::READY_TO_COMMIT) {
            throw new \RuntimeException('Contract must be READY_TO_COMMIT to commit to order');
        }

        $this->orderId = $orderId;
        $this->state = ContractState::COMMITTED;
        $this->committedAt = new \DateTimeImmutable();
    }
}
```

---

### 3.5 Payment Capture Handler (Contract → FULFILLED)
**Status:** [ ] NOT STARTED

After order is created and payment is captured, contract becomes FULFILLED.

**Test First:**
```php
// tests/Unit/Component/EventSystem/Handler/ContractFulfillmentHandlerTest.php
class ContractFulfillmentHandlerTest extends TestCase
{
    public function testFulfillsContractOnPaymentCapturedEvent(): void;
    public function testUpdatesOrderToPaid(): void;
    public function testDispatchesContractFulfilledEvent(): void;
}
```

**Implementation:**
```php
class ContractFulfillmentHandler extends AbstractHandler
{
    public function handle(object $event): void
    {
        if (!$event instanceof PaymentCapturedEvent) {
            return;
        }

        $context = $event->getContext();
        $contractId = $context->get('contractId');
        $contract = $this->contractRepository->find($contractId);

        if (!$contract || $contract->getState() !== ContractState::COMMITTED) {
            return;
        }

        // Fulfill contract
        $contract->fulfill();
        $this->contractRepository->save($contract);

        // Update order to paid
        $orderId = $contract->getOrderId();
        if ($orderId) {
            $order = $this->orderRepository->find($orderId);
            $order->setFieldData('oxpaid', date('Y-m-d H:i:s'));
            $order->setFieldData('oxtransstatus', 'OK');
            $this->orderRepository->save($order);
        }

        // Dispatch ContractFulfilledEvent
        $this->eventDispatcher?->dispatch(new ContractFulfilledEvent($context, $contract));
    }
}
```

---

## Event Chain

```
ContractReadyToCommitEvent
    │
    └─► OrderCreationHandler
            • Creates oxorder from basket snapshot
            • Contract.commitToOrder(orderId)
            • Contract → COMMITTED
            • Dispatches OrderCreatedFromContractEvent
                    │
                    └─► (Notification handlers, analytics, etc.)

PaymentCapturedEvent (from webhook or direct capture)
    │
    └─► ContractFulfillmentHandler
            • Contract → FULFILLED
            • Order.oxpaid = NOW()
            • Order.oxtransstatus = 'OK'
            • Dispatches ContractFulfilledEvent
```

---

## Definition of Done

- [ ] All tests pass: `docker compose exec php vendor/bin/phpunit tests/Unit/Component/EventSystem/Handler/OrderCreationHandlerTest.php`
- [ ] All tests pass: `docker compose exec php vendor/bin/phpunit tests/Unit/Component/Order/OrderFactoryTest.php`
- [ ] Pre-commit check passes
- [ ] Order created ONLY when contract is READY_TO_COMMIT
- [ ] Contract.OXORDERID set after order creation
- [ ] Contract transitions to COMMITTED
- [ ] OrderCreatedFromContractEvent dispatched

---

## Files Created/Modified

### New Files
- `src/Component/Order/OrderFactory.php`
- `src/Component/Order/OrderFactoryInterface.php`
- `src/Component/EventSystem/Event/Order/OrderCreatedFromContractEvent.php`
- `src/Component/EventSystem/Handler/OrderCreationHandler.php`
- `src/Component/EventSystem/Handler/ContractFulfillmentHandler.php`
- `tests/Unit/Component/Order/OrderFactoryTest.php`
- `tests/Unit/Component/EventSystem/Handler/OrderCreationHandlerTest.php`
- `tests/Unit/Component/EventSystem/Handler/ContractFulfillmentHandlerTest.php`

### Modified Files
- `src/Component/Contract/PaymentContract.php` (add commitToOrder, fulfill methods)
- `services.yaml` (register handlers)

---

## Notes

- Order is created from CONTRACT's basket snapshot, not current basket
- This ensures order matches what was agreed upon at contract creation
- Order number is assigned at creation time - no gaps for failed payments
- Contract.OXORDERID is NULL until this handler runs
