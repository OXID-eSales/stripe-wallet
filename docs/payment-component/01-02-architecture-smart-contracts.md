# Smart-Contract Architecture Pattern

**Version:** 3.0.0
**Date:** 2025-10-20
**Target Platform:** OXID eShop 7.4+ (compatible with 7.5, 8.0+)
**Status:** Architectural Proposal
**Related Documents:**
- [01-architecture-layers.md](01-architecture-layers.md) - Base event-driven architecture
- [02-database-and-models.md](02-database-and-models.md) - Current database design

---

## Table of Contents

1. [Executive Summary](#executive-summary)
2. [Conceptual Overview](#conceptual-overview)
3. [Current Architecture Analysis](#current-architecture-analysis)
4. [Smart-Contract Approach](#smart-contract-approach)
5. [Hybrid Pattern (Recommended)](#hybrid-pattern-recommended)
6. [Pros & Cons Analysis](#pros--cons-analysis)
7. [Database Schema](#database-schema)
8. [Implementation Examples](#implementation-examples)
9. [Migration Strategy](#migration-strategy)
10. [Comparison Matrix](#comparison-matrix)
11. [Recommendations](#recommendations)

---

## Executive Summary

This document proposes introducing a **Smart-Contract pattern** as a layer above OXID's `oxorder` to manage the payment and fulfillment lifecycle. The key insight is:

> **"Clicking 'Place Order' should create a contract, not an order. The order is created only when the contract is fulfilled."**

### Key Innovation

**Traditional E-commerce Flow:**
```
User clicks "Place Order" → Order created (state: NOT_FINISHED) → Payment → Order updated (state: OK)
```

**Smart-Contract Flow:**
```
User clicks "Place Order" → Contract created (state: DRAFT) → Conditions resolved → Order created (state: OK)
```

### Strategic Benefits

- **True Domain-Driven Design**: Contract = Aggregate Root, Order = Read Model
- **Headless/API-First**: Perfect for GraphQL, MCP protocol, programmatic commerce
- **Clean State Management**: No intermediate order states polluting OXID core
- **Better Rollback**: Cancel contract vs. delete/rollback order
- **Future-Proof**: Aligns with modern e-commerce patterns (Shopify, Stripe Checkout)

### Recommendation

✅ **Implement the Hybrid Pattern** - Progressive enhancement that adds smart-contract layer while maintaining OXID compatibility.

---

## Conceptual Overview

### What is a Smart-Contract in E-commerce?

A **smart-contract** in this context (not blockchain) is a domain entity that:

1. **Captures Intent**: User's intention to purchase (basket snapshot, terms, conditions)
2. **Tracks Preconditions**: Payment authorization, fraud checks, stock availability, compliance
3. **Manages Lifecycle**: DRAFT → PENDING → COMMITTED → FULFILLED
4. **Triggers Order Creation**: Creates `oxorder` ONLY when all conditions are met
5. **Provides Audit Trail**: Complete history of fulfillment process

### Why "Contract" Terminology?

The term "contract" reflects the business reality:

- **Legal Perspective**: User and merchant enter into a binding agreement with conditions
- **Technical Perspective**: Executable specification of fulfillment requirements
- **Domain Perspective**: Aggregate root that owns the purchase lifecycle
- **Practical Perspective**: Container for all data needed before order creation

### Philosophical Shift

**Current Thinking:**
> "An order exists from the moment the user clicks 'Place Order', we just update its state."

**Smart-Contract Thinking:**
> "A contract exists when the user expresses intent. An order exists when that intent can be fulfilled."

This mirrors real-world commerce:
- You sign a purchase agreement (contract)
- Conditions are verified (payment clears, goods available, compliance checks)
- Purchase order is issued (order created)
- Goods are shipped (fulfillment)

---

## Current Architecture Analysis

### Current Flow (from 01-architecture-layers.md)

```
┌─────────────────────────────────────────────────────────────┐
│                    CURRENT ARCHITECTURE                      │
└─────────────────────────────────────────────────────────────┘

1. User clicks "Place Order"
   ↓
2. Controller emits PaymentInitiatedEvent
   ↓
3. PaymentInitiationHandler:
   - Creates oxorder (state: NOT_FINISHED)  ← Order exists immediately
   - Creates osc_payment_order_state (state: 500)
   - Calls PaymentService.initiatePayment()
   ↓
4. Payment processing (external redirect)
   ↓
5. Webhook: PaymentCapturedEvent
   ↓
6. PaymentCaptureHandler:
   - Updates oxorder (state: OK)  ← Order modified
   - Updates osc_payment_order_state (state: OK)
   ↓
7. Order ready for fulfillment
```

### Current Database State Tracking

From [02-database-and-models.md](02-database-and-models.md:400-419):

```sql
-- Current: Order state tracking
CREATE TABLE osc_payment_order_state (
    OXORDERID CHAR(32) NOT NULL UNIQUE,  -- FK to oxorder (must exist!)
    OXPAYMENTSTATE VARCHAR(32) NOT NULL,  -- NOT_FINISHED, 500, 600, OK
    OXPROVIDERORDERID VARCHAR(128),
    OXWEBHOOKWAITSINCE DATETIME,
    ...
);
```

**Key Issue**: `OXORDERID` foreign key requires `oxorder` to exist **first**. This couples payment lifecycle to order existence.

### Problems with Current Approach

#### 1. **Premature Order Creation**

```php
// Order created BEFORE payment confirmation
$order = $this->orderManager->createTemporaryOrder($basket, $user);
$order->setState(Order::ORDER_STATE_NOT_FINISHED);

// What if payment fails? Order exists in database with no payment.
// What if user abandons? Orphan order record.
// What if fraud detected? Need to cancel/delete order.
```

#### 2. **State Pollution in OXID Core**

```php
// Payment-specific states leak into oxorder
const ORDER_STATE_SESSIONPAYMENT_INPROGRESS = 500;
const ORDER_STATE_WAIT_FOR_WEBHOOK_EVENTS = 600;
const ORDER_STATE_ACDCINPROGRESS = 700;
const ORDER_STATE_TIMEOUT_FOR_WEBHOOK_EVENTS = 900;
```

These states are **payment module concerns**, not core order states. They pollute OXID's domain model.

#### 3. **Tight Coupling to OXID Core**

```php
// PaymentOrderState DEPENDS on oxorder existing
FOREIGN KEY (OXORDERID) REFERENCES oxorder(OXID) ON DELETE CASCADE
```

Cannot process payment without creating order first. Cannot separate payment component from OXID order lifecycle.

#### 4. **Complex Rollback Logic**

```php
// Payment failed - what to do with order?
if ($paymentFailed) {
    // Option 1: Delete order? (breaks referential integrity)
    $order->delete();

    // Option 2: Keep as failed? (clutters database)
    $order->setState(Order::ORDER_STATE_PAYMENT_ERROR);

    // Option 3: Mark as cancelled? (still an "order")
    $order->setCancelled();
}
```

No clean rollback strategy - order exists permanently.

#### 5. **Multi-Channel Complexity**

```php
// Different channels create orders differently
// Web: Creates order immediately
// API/GraphQL: Should it create order? Or return payment intent?
// MCP/AI: How does programmatic commerce work?
// Mobile app: Native payment flows?

// Current architecture forces all channels to create orders early
```

#### 6. **Order Number Assignment Issues**

```php
// Order number assigned when order created (state: NOT_FINISHED)
$order->setOrderNumber($this->getNextOrderNumber());

// But payment might fail! Now you have gaps in order numbers.
// Or worse: order numbers for orders that never completed.
```

---

## Smart-Contract Approach

### Three Architecture Options

#### Option A: Pure Smart-Contract (Radical)

**Philosophy**: Never create `oxorder` until contract is fulfilled.

```
User Action → Contract Created → Conditions Resolved → Order Created (Final State)
```

**Advantages:**
- ✅ Complete OXID core isolation
- ✅ Immutable orders (always in OK state)
- ✅ Perfect DDD alignment

**Disadvantages:**
- ❌ Breaking change for OXID ecosystem
- ❌ Admin UI needs rewrite
- ❌ Reporting tools break
- ❌ High migration cost

**Verdict:** ❌ Too radical for OXID integration

---

#### Option B: Shadow Order (Dual-Track)

**Philosophy**: Create both contract AND order, sync states.

```
User Action → Contract + Shadow Order → Conditions Resolved → Order Activated
```

**Advantages:**
- ✅ OXID compatibility maintained
- ✅ Contract benefits available

**Disadvantages:**
- ❌ State synchronization complexity
- ❌ Duplicate data management
- ❌ Two sources of truth

**Verdict:** ❌ Too complex, synchronization nightmare

---

#### Option C: Hybrid Smart-Contract (Recommended)

**Philosophy**: Contract manages lifecycle, order created at optimal point.

```
User Action → Contract (DRAFT) → Conditions Resolved → Order Created (NOT_FINISHED) → Payment Captured → Contract Fulfilled + Order OK
```

**Advantages:**
- ✅ OXID compatibility maintained
- ✅ Contract benefits available
- ✅ Progressive enhancement
- ✅ Clean separation of concerns

**Disadvantages:**
- ⚠️ Slightly more complex (but manageable)

**Verdict:** ✅ **RECOMMENDED** - Best balance of innovation and pragmatism

---

## Hybrid Pattern (Recommended)

### Architecture Overview

```
┌─────────────────────────────────────────────────────────────┐
│                HYBRID SMART-CONTRACT PATTERN                 │
└─────────────────────────────────────────────────────────────┘

PHASE 1: ORDER INTENT
──────────────────────
User clicks "Place Order"
  ↓
Create osc_payment_contract (state: DRAFT)
  - Captures: Basket snapshot, user data, terms
  - Conditions: payment_authorized, fraud_check, stock_reserved
  - No oxorder yet!
  ↓
Contract state: DRAFT → PENDING


PHASE 2: CONDITION RESOLUTION
──────────────────────────────
PaymentInitiatedEvent → Multiple handlers process in parallel:

├─ PaymentAuthorizationHandler:
│    → PaymentService.authorize()
│    → Contract.fulfillCondition('payment_authorized')
│
├─ FraudCheckHandler:
│    → FraudService.check()
│    → Contract.fulfillCondition('fraud_check')
│
└─ StockReservationHandler:
     → InventoryService.reserve()
     → Contract.fulfillCondition('stock_reserved')

  ↓
Contract monitors: Are all conditions fulfilled?


PHASE 3: ORDER CREATION (Optimal Point)
────────────────────────────────────────
ContractConditionsFulfilledEvent
  ↓
Create oxorder (state: NOT_FINISHED)
  - Order number assigned HERE (no gaps!)
  - Contract.commitToOrder(oxorder.OXID)
  ↓
Contract state: PENDING → COMMITTED
Create osc_payment_order_state (FK to both contract + order)


PHASE 4: FULFILLMENT
────────────────────
PaymentCapturedEvent (webhook)
  ↓
Contract.fulfill()
  - Marks contract as FULFILLED
  - Order.markAsOK()
  - Contract.fulfilledAt = NOW()
  ↓
Contract state: COMMITTED → FULFILLED
Order state: NOT_FINISHED → OK
  ↓
OrderReadyForFulfillmentEvent → Shipping, notifications, etc.
```

### State Machines

#### Contract State Machine

```
DRAFT
  ↓ (conditions added)
PENDING
  ↓ (all conditions met)
READY_TO_COMMIT
  ↓ (order created)
COMMITTED
  ↓ (payment captured)
FULFILLED
```

**Terminal States:**
- `FULFILLED` - Success
- `CANCELLED` - User/system cancelled
- `EXPIRED` - Timeout
- `FAILED` - Error

#### Order State Machine (Simplified)

```
NOT_FINISHED (only when contract committed)
  ↓
OK (only when contract fulfilled)
```

**Key Difference**: Order is never in intermediate payment states. Those belong to contract.

### Key Design Principles

#### 1. **Contract is Aggregate Root**

```php
class PaymentContract  // Aggregate Root
{
    private string $id;
    private string $userId;
    private ?string $orderId = null;  // NULL until committed!
    private string $state;
    private array $basketSnapshot;
    private array $conditions;
    private array $events;

    // Contract OWNS the lifecycle
    public function addCondition(ContractCondition $condition): void;
    public function fulfillCondition(string $type): void;
    public function commitToOrder(string $orderId): void;
    public function fulfill(): void;
    public function cancel(string $reason): void;
}
```

#### 2. **Order is Created by Contract**

```php
// NOT: Controller creates order
// YES: Contract creates order when ready

class OrderCreationHandler
{
    public function handle(ContractConditionsFulfilledEvent $event): void
    {
        $contract = $event->getContract();

        // All conditions met → Safe to create order
        $order = $this->orderFactory->createFromContract($contract);

        // Link contract to order
        $contract->commitToOrder($order->getId());

        // Emit event
        $this->dispatcher->dispatch(new OrderCreatedFromContractEvent($contract, $order));
    }
}
```

#### 3. **Conditions are Explicit**

```php
class ContractCondition
{
    const TYPE_PAYMENT_AUTHORIZED = 'payment_authorized';
    const TYPE_FRAUD_CHECK = 'fraud_check';
    const TYPE_STOCK_RESERVED = 'stock_reserved';
    const TYPE_COMPLIANCE_CHECK = 'compliance_check';
    const TYPE_ADDRESS_VALIDATED = 'address_validated';

    const STATUS_PENDING = 'pending';
    const STATUS_FULFILLED = 'fulfilled';
    const STATUS_FAILED = 'failed';

    public function __construct(
        public readonly string $type,
        public string $status = self::STATUS_PENDING,
        public array $data = [],
        public ?\DateTime $fulfilledAt = null,
        public ?string $failureReason = null
    ) {}
}
```

#### 4. **Basket Snapshot is Immutable**

```php
class ContractBasketSnapshot
{
    public function __construct(
        private readonly array $items,
        private readonly array $discounts,
        private readonly float $totalGross,
        private readonly float $totalNet,
        private readonly float $totalVat,
        private readonly string $currency,
        private readonly \DateTime $capturedAt
    ) {
        // Immutable - no setters
    }

    // Only getters
    public function getItems(): array { return $this->items; }
    public function getTotalGross(): float { return $this->totalGross; }

    // Helper to convert to oxorder format
    public function toOrderData(): array
    {
        return [
            'items' => $this->items,
            'discounts' => $this->discounts,
            'total' => $this->totalGross,
            // ... full mapping
        ];
    }
}
```

### Event Flow: Detailed

```php
// 1. Payment Initiated
class PaymentInitiationHandler
{
    public function handle(PaymentInitiatedEvent $event): void
    {
        // Create contract FIRST (not order!)
        $contract = new PaymentContract(
            userId: $event->getUser()->getId(),
            basketSnapshot: $this->createBasketSnapshot($event->getBasket()),
            state: PaymentContract::STATE_DRAFT
        );

        // Define conditions
        $contract->addCondition(new ContractCondition(
            type: ContractCondition::TYPE_PAYMENT_AUTHORIZED
        ));
        $contract->addCondition(new ContractCondition(
            type: ContractCondition::TYPE_FRAUD_CHECK
        ));
        $contract->addCondition(new ContractCondition(
            type: ContractCondition::TYPE_STOCK_RESERVED
        ));

        // Transition state
        $contract->transitionToPending();

        // Save
        $this->contractRepository->save($contract);

        // Emit event
        $this->dispatcher->dispatch(new ContractCreatedEvent($contract));
    }
}

// 2. Payment Authorized
class PaymentAuthorizationHandler
{
    public function handle(ContractCreatedEvent $event): void
    {
        $contract = $event->getContract();

        // Call payment provider
        $authResponse = $this->paymentService->authorize(
            amount: $contract->getBasketSnapshot()->getTotalGross(),
            currency: $contract->getBasketSnapshot()->getCurrency()
        );

        if ($authResponse->isSuccessful()) {
            // Fulfill condition
            $contract->fulfillCondition(
                type: ContractCondition::TYPE_PAYMENT_AUTHORIZED,
                data: ['authorization_id' => $authResponse->getAuthorizationId()]
            );

            $this->contractRepository->save($contract);

            // Check if all conditions met
            if ($contract->areAllConditionsFulfilled()) {
                $this->dispatcher->dispatch(
                    new ContractConditionsFulfilledEvent($contract)
                );
            }
        } else {
            $contract->failCondition(
                type: ContractCondition::TYPE_PAYMENT_AUTHORIZED,
                reason: $authResponse->getErrorMessage()
            );

            $this->dispatcher->dispatch(new ContractFailedEvent($contract));
        }
    }
}

// 3. All Conditions Met → Create Order
class OrderCreationHandler
{
    public function handle(ContractConditionsFulfilledEvent $event): void
    {
        $contract = $event->getContract();

        // NOW create oxorder (safe - all conditions verified)
        $order = $this->orderFactory->createFromContract($contract);
        $order->setState(Order::ORDER_STATE_NOT_FINISHED);
        $order->setOrderNumber($this->getNextOrderNumber());  // No gaps!
        $order->save();

        // Link contract to order
        $contract->commitToOrder($order->getId());
        $this->contractRepository->save($contract);

        // Create PaymentOrderState (links both)
        $orderState = new PaymentOrderState(
            orderId: $order->getId(),
            contractId: $contract->getId(),
            paymentState: PaymentOrderState::STATE_PAYMENT_IN_PROGRESS
        );
        $this->orderStateRepository->save($orderState);

        // Emit event
        $this->dispatcher->dispatch(
            new OrderCreatedFromContractEvent($contract, $order)
        );
    }
}

// 4. Payment Captured (Webhook)
class PaymentCaptureHandler
{
    public function handle(PaymentCapturedEvent $event): void
    {
        $providerOrderId = $event->getProviderOrderId();

        // Find contract by provider order ID
        $contract = $this->contractRepository->findByProviderOrderId($providerOrderId);

        if (!$contract) {
            throw new \RuntimeException("Contract not found");
        }

        // Fulfill contract
        $contract->fulfill();
        $this->contractRepository->save($contract);

        // Update order
        $order = $this->orderRepository->find($contract->getOrderId());
        $order->markOrderPaid();
        $order->setState(Order::ORDER_STATE_OK);
        $order->save();

        // Update order state
        $orderState = $this->orderStateRepository->findByOrderId($order->getId());
        $orderState->markAsCompleted();
        $this->orderStateRepository->save($orderState);

        // Emit completion event
        $this->dispatcher->dispatch(
            new ContractFulfilledEvent($contract, $order)
        );
    }
}
```

---

## Pros & Cons Analysis

### ✅ Advantages

#### 1. **Clean Separation of Concerns**

**Contract Domain:**
- Intent capture
- Condition management
- Payment processing
- State transitions

**Order Domain:**
- Fulfillment
- Shipping
- Invoicing
- Customer service

**Result:** Payment module can evolve independently of order management.

---

#### 2. **Improved Rollback/Cancellation**

**Current Approach:**
```php
// Payment failed - order exists, what to do?
if ($paymentFailed) {
    $order->delete();  // Breaks referential integrity
    // OR
    $order->setCancelled();  // Clutters database with failed orders
}
```

**Smart-Contract Approach:**
```php
// Payment failed - contract cancelled, no order ever created
if ($paymentFailed) {
    $contract->cancel('payment_declined');
    // No order exists - clean database
}
```

**Benefits:**
- No orphan orders
- No cluttered order history
- Clean audit trail
- Simple error recovery

---

#### 3. **Better Order Number Management**

**Current Approach:**
```php
// Order number assigned immediately
$order->setOrderNumber(12345);  // Even if payment fails!
$order->setState(Order::ORDER_STATE_NOT_FINISHED);

// Result: Gaps in order numbers for failed payments
// Order #12345 - CANCELLED
// Order #12346 - CANCELLED
// Order #12347 - OK
```

**Smart-Contract Approach:**
```php
// Order number assigned ONLY when contract fulfilled
if ($contract->areAllConditionsFulfilled()) {
    $order = $this->createOrder();
    $order->setOrderNumber($this->getNextOrderNumber());  // No gaps!
}

// Result: Sequential order numbers, no failed orders
// Order #12345 - OK
// Order #12346 - OK
// Order #12347 - OK
```

---

#### 4. **True Domain-Driven Design**

**DDD Principles Applied:**

```php
// Contract = Aggregate Root
class PaymentContract  // Aggregate Root
{
    private ContractId $id;
    private UserId $userId;
    private BasketSnapshot $basket;  // Value Object
    private Conditions $conditions;  // Entity Collection
    private ContractState $state;  // Value Object

    // Domain logic encapsulated
    public function canBeCommitted(): bool
    {
        return $this->conditions->areAllFulfilled()
            && $this->state->isPending();
    }

    // Invariants protected
    public function commitToOrder(OrderId $orderId): void
    {
        if (!$this->canBeCommitted()) {
            throw new ContractNotReadyException();
        }

        $this->orderId = $orderId;
        $this->state = ContractState::committed();
        $this->recordEvent(new ContractCommittedEvent($this));
    }
}

// Order = Separate Aggregate
class Order  // Different Aggregate Root
{
    // Order doesn't know about contract lifecycle
    // Clean separation
}
```

**Benefits:**
- Proper bounded contexts
- Clear aggregate boundaries
- Encapsulated domain logic
- Testable domain models

---

#### 5. **Headless/API-First Commerce**

**Traditional API Problem:**
```graphql
# What should this return?
mutation placeOrder {
  placeOrder(basketId: "123") {
    order {
      id              # Order exists immediately?
      status          # "NOT_FINISHED" - confusing for API consumers
      paymentUrl      # Need to redirect
    }
  }
}

# API consumer confused: "I have an order ID but order isn't complete?"
```

**Smart-Contract API:**
```graphql
mutation initiateCheckout {
  initiateCheckout(basketId: "123") {
    contract {
      id              # Contract ID (clear: this is not an order yet)
      status          # "PENDING" - clear meaning
      conditions {    # Explicit conditions
        paymentAuthorized { status, data }
        fraudCheck { status, data }
        stockReserved { status, data }
      }
      paymentUrl      # Redirect URL
    }
  }
}

# Later: Query contract status
query contractStatus($id: ID!) {
  contract(id: $id) {
    status          # PENDING, COMMITTED, FULFILLED
    order {         # NULL until fulfilled!
      id            # Only appears when contract fulfilled
      orderNumber
    }
  }
}
```

**Benefits:**
- Clear API semantics
- No confusion about order state
- Better mobile app UX
- Perfect for programmatic commerce (MCP, AI agents)

---

#### 6. **Explicit Condition Tracking**

**Current Approach: Implicit Conditions**
```php
// Conditions hidden in code, not data
if ($this->fraudService->check($order)) {
    if ($this->stockService->isAvailable($order)) {
        if ($this->paymentService->authorize($order)) {
            // All good - but no audit trail
        }
    }
}
```

**Smart-Contract: Explicit Conditions**
```php
// Conditions tracked in database
$contract->getConditions();
// [
//   {type: 'fraud_check', status: 'fulfilled', fulfilledAt: '2025-10-20 14:30:00'},
//   {type: 'stock_reserved', status: 'fulfilled', fulfilledAt: '2025-10-20 14:30:01'},
//   {type: 'payment_authorized', status: 'pending', fulfilledAt: null}
// ]
```

**Benefits:**
- Complete audit trail
- Transparent to customer ("Your order is processing: Payment pending...")
- Easy debugging ("Which condition failed?")
- Business analytics ("How many contracts fail at payment vs. fraud check?")

---

#### 7. **Simplified State Management**

**Current: 10+ States Across Multiple Entities**
```
oxorder states: NOT_FINISHED, 500, 600, 700, 800, 900, OK, ERROR
PaymentOrderState states: NOT_FINISHED, 500, 600, OK
PaymentTransaction states: pending, completed, failed, cancelled
```

**Smart-Contract: Clear State Hierarchy**
```
Contract states: DRAFT, PENDING, COMMITTED, FULFILLED, CANCELLED
Order states: NOT_FINISHED, OK (only when contract committed/fulfilled)
Transaction states: pending, completed, failed (unchanged)
```

**Benefits:**
- Fewer states overall
- Clear state ownership (contract owns payment states, order owns fulfillment states)
- No state synchronization bugs
- Easier to reason about

---

#### 8. **Better Testing**

**Current Testing Challenges:**
```php
// Must create order to test payment flow
public function testPaymentAuthorization()
{
    $order = $this->createOrder();  // Complex setup
    $order->setState(Order::ORDER_STATE_NOT_FINISHED);
    $order->save();

    $result = $this->paymentService->authorize($order);
    // Test depends on order existing
}
```

**Smart-Contract Testing:**
```php
// Can test contract lifecycle independently
public function testContractFulfillment()
{
    $contract = new PaymentContract();  // Pure domain object
    $contract->addCondition(new ContractCondition('payment_authorized'));
    $contract->fulfillCondition('payment_authorized');

    $this->assertTrue($contract->areAllConditionsFulfilled());
    // No database, no order, pure unit test
}
```

**Benefits:**
- Faster unit tests (no database required)
- Pure domain logic testing
- No complex fixtures
- TDD-friendly

---

#### 9. **Progressive Enhancement**

**Implementation Strategy:**
```
Sprint 1: Add contract table, basic contract creation (coexist with current flow)
Sprint 2: Add condition tracking
Sprint 3: Integrate with event handlers
Sprint 4: Migrate order creation to contract-based
Sprint 5: Deprecate old flow
```

**Benefits:**
- Non-breaking change
- Can be adopted incrementally
- Rollback possible at any stage
- A/B testing possible

---

### ❌ Disadvantages

#### 1. **Increased Complexity**

**Additional Concepts:**
- Contract entity (new aggregate root)
- Condition entity (new value objects)
- Contract state machine (new state logic)
- Contract-to-order conversion (new mapping logic)

**Code Impact:**
```
+1 aggregate root (PaymentContract)
+1 entity (ContractCondition)
+2 value objects (ContractState, BasketSnapshot)
+1 repository (ContractRepository)
+3 event handlers (ContractCreationHandler, OrderCreationHandler, ContractFulfillmentHandler)
+5 events (ContractCreatedEvent, ContractConditionsFulfilledEvent, etc.)
```

**Mitigation:**
- Clear documentation
- Strong typing (PHP 8.1+)
- Comprehensive tests
- Gradual team onboarding

---

#### 2. **Storage Overhead**

**Additional Database Tables:**
```sql
-- New contract table
CREATE TABLE osc_payment_contract (
    OXID CHAR(32) PRIMARY KEY,
    OXBASKETDATA JSON NOT NULL,  -- ~5-10 KB per contract
    OXCONDITIONS JSON NOT NULL,   -- ~1-2 KB
    -- Total: ~6-12 KB per contract
);

-- Modified order state table
ALTER TABLE osc_payment_order_state
ADD COLUMN OXCONTRACTID CHAR(32);  -- Link to contract
```

**Storage Impact:**
- +10-15% database size
- Basket snapshot duplicated (until contract fulfilled)
- Condition history stored

**Mitigation:**
- Archive fulfilled contracts after 90 days
- Compress basket snapshot JSON
- Index optimization
- Contract pruning strategy

---

#### 3. **Contract-to-Order Mapping Complexity**

**Challenge: Converting Contract Data to Order Format**

```php
class ContractToOrderConverter
{
    public function convert(PaymentContract $contract): Order
    {
        $order = new Order();

        // Map 50+ fields
        $order->setUserId($contract->getUserId());
        $order->setTotalAmount($contract->getBasketSnapshot()->getTotalGross());
        $order->setCurrency($contract->getBasketSnapshot()->getCurrency());

        // Map items
        foreach ($contract->getBasketSnapshot()->getItems() as $item) {
            $orderArticle = new OrderArticle();
            $orderArticle->setArticleId($item['articleId']);
            $orderArticle->setAmount($item['amount']);
            // ... 20+ fields per item
            $order->addArticle($orderArticle);
        }

        // Map discounts
        foreach ($contract->getBasketSnapshot()->getDiscounts() as $discount) {
            // ... discount mapping
        }

        // Map addresses
        // Map payment info
        // Map shipping info

        return $order;
    }
}
```

**Risk:**
- Mapping bugs (field mismatch)
- Data loss (forgotten fields)
- Type conversion errors

**Mitigation:**
- Comprehensive integration tests
- Schema validation
- Automated field mapping verification
- Strict type checking

---

#### 4. **OXID Admin UI Doesn't Show Contracts**

**Current Reality:**
```
OXID Admin → Orders → Shows all orders (including NOT_FINISHED)
```

**With Smart-Contracts:**
```
OXID Admin → Orders → Shows only fulfilled orders
                    → Contracts not visible!
```

**Impact:**
- Admin users can't see "pending" purchases
- No visibility into payment processing
- Customer service challenge ("Where's my order?")

**Mitigation:**
- Build contract admin UI (separate module)
- Add "Pending Contracts" section to admin
- Export contract reports
- Training for customer service

---

#### 5. **Event Handler Ordering Complexity**

**Challenge: Ensuring Correct Handler Execution Order**

```php
// These handlers must execute in sequence
1. PaymentAuthorizationHandler (fulfills payment condition)
2. FraudCheckHandler (fulfills fraud condition)
3. StockReservationHandler (fulfills stock condition)
4. OrderCreationHandler (waits for ALL conditions) ← Must fire AFTER all above

// Risk: OrderCreationHandler fires before all conditions fulfilled
```

**Mitigation:**
- Explicit event priorities in dispatcher
- Condition checking in OrderCreationHandler
- Integration tests for event ordering
- Use event sourcing pattern (ordered event log)

---

#### 6. **Learning Curve**

**Team Training Required:**
- DDD concepts (aggregate roots, bounded contexts)
- Event-driven patterns
- State machines
- Contract lifecycle

**Documentation Needed:**
- Architecture decision records
- Sequence diagrams
- Developer onboarding guide
- Troubleshooting playbook

**Time Impact:**
- 2-3 weeks for team ramp-up
- Mentoring required
- Code review overhead

---

#### 7. **Migration Complexity**

**Existing Installations:**
```php
// Old flow:
PaymentInitiatedEvent → Create Order → Payment

// New flow:
PaymentInitiatedEvent → Create Contract → Conditions → Create Order → Payment

// How to migrate existing orders?
// What about in-flight payments?
```

**Migration Challenges:**
- Dual codepaths during transition
- Data migration for existing NOT_FINISHED orders
- Rollback strategy if issues arise

**Mitigation:**
- Feature flags (enable contract flow per payment method)
- Gradual rollout (10% → 50% → 100%)
- Comprehensive rollback plan
- Extended testing period

---

#### 8. **Debugging Complexity**

**More Moving Parts:**
```
Current debug path:
PaymentInitiatedEvent → PaymentInitiationHandler → Order created → Done

Smart-Contract debug path:
PaymentInitiatedEvent → ContractCreationHandler → Contract created →
ContractCreatedEvent → PaymentAuthorizationHandler → Condition fulfilled →
ContractConditionsFulfilledEvent → OrderCreationHandler → Order created →
OrderCreatedEvent → ... (and so on)
```

**Challenge:**
- More events to trace
- More handlers to debug
- Condition state tracking

**Mitigation:**
- Structured logging with correlation IDs
- Event replay capability (event sourcing)
- Debug UI showing contract lifecycle
- OpenTelemetry tracing

---

#### 9. **Performance Overhead**

**Additional Operations:**
- Create contract (database write)
- Update contract conditions (multiple writes)
- Check if all conditions fulfilled (read + logic)
- Create order from contract (read contract + write order)

**Benchmark (Estimated):**
```
Current flow:    ~150ms (create order + payment)
Contract flow:   ~200ms (create contract + conditions + order + payment)
Overhead:        +50ms (+33%)
```

**Mitigation:**
- Database indexing (OXID, OXSTATE)
- Redis caching for contract state
- Async condition processing
- Batch condition updates

---

### Balanced Assessment

| Aspect | Impact | Severity | Mitigation Difficulty |
|--------|--------|----------|----------------------|
| **Advantages** | | | |
| Clean separation | 🟢 High | N/A | N/A |
| Better rollback | 🟢 High | N/A | N/A |
| DDD alignment | 🟢 Medium | N/A | N/A |
| API-first ready | 🟢 High | N/A | N/A |
| **Disadvantages** | | | |
| Complexity | 🟡 Medium | Medium | 🟢 Easy (docs + training) |
| Storage overhead | 🟡 Low | Low | 🟢 Easy (archival) |
| Mapping complexity | 🟡 Medium | High | 🟡 Medium (testing) |
| Admin UI gap | 🟡 Medium | Medium | 🟡 Medium (build UI) |
| Learning curve | 🟡 Medium | Medium | 🟡 Medium (time investment) |
| Migration complexity | 🟢 Low | High | 🟡 Medium (feature flags) |
| Debugging | 🟡 Medium | Medium | 🟢 Easy (logging + tooling) |
| Performance | 🟢 Low | Low | 🟢 Easy (caching) |

**Legend:**
- 🟢 Minor concern
- 🟡 Moderate concern
- 🔴 Major concern

**Overall Assessment:** The disadvantages are **manageable** with proper planning. None are deal-breakers.

---

## Database Schema

### Contract Tables

```sql
-- Main contract table
CREATE TABLE IF NOT EXISTS osc_payment_contract (
    OXID CHAR(32) NOT NULL PRIMARY KEY,
    OXSHOPID INT NOT NULL,
    OXUSERID CHAR(32) NOT NULL,  -- FK to oxuser
    OXORDERID CHAR(32) NULL,  -- FK to oxorder (NULL until committed!)

    -- Contract state
    OXSTATE VARCHAR(32) NOT NULL,  -- DRAFT, PENDING, COMMITTED, FULFILLED, CANCELLED
    OXSTATEREASON VARCHAR(255),  -- Reason for state (if failed/cancelled)

    -- Snapshot data
    OXBASKETDATA JSON NOT NULL,  -- Complete basket snapshot
    OXTERMS JSON,  -- Terms & conditions agreed
    OXMETADATA JSON,  -- Additional metadata (IP, user agent, etc.)

    -- Fulfillment conditions
    OXCONDITIONS JSON NOT NULL,  -- Array of conditions with status

    -- Provider data
    OXPROVIDER VARCHAR(32),  -- stripe, paypal, unzer, etc.
    OXPROVIDERORDERID VARCHAR(128),  -- Provider order/session ID

    -- Timestamps
    OXCREATED DATETIME NOT NULL,
    OXUPDATED DATETIME NOT NULL,
    OXCOMMITTEDAT DATETIME NULL,  -- When order created
    OXFULFILLEDAT DATETIME NULL,  -- When contract fulfilled
    OXEXPIRESAT DATETIME NULL,  -- Contract expiration

    -- Indexes
    INDEX IDX_STATE (OXSTATE),
    INDEX IDX_USER (OXUSERID),
    INDEX IDX_ORDER (OXORDERID),
    INDEX IDX_PROVIDER_ORDER (OXPROVIDERORDERID),
    INDEX IDX_CREATED (OXCREATED),
    INDEX IDX_EXPIRES (OXEXPIRESAT),

    -- Foreign keys
    FOREIGN KEY FK_USER (OXUSERID) REFERENCES oxuser(OXID) ON DELETE CASCADE,
    FOREIGN KEY FK_CONTRACT_ORDER (OXORDERID) REFERENCES oxorder(OXID) ON DELETE SET NULL

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Payment contract lifecycle management';
```

### Modified Order State Table

```sql
-- Add contract reference to existing table
ALTER TABLE osc_payment_order_state ADD COLUMN IF NOT EXISTS
    OXCONTRACTID CHAR(32) NULL COMMENT 'FK to payment contract',
    ADD INDEX IDX_CONTRACT (OXCONTRACTID),
    ADD FOREIGN KEY FK_CONTRACT (OXCONTRACTID)
        REFERENCES osc_payment_contract(OXID) ON DELETE SET NULL;
```

### Example Data

```json
-- osc_payment_contract example row
{
  "OXID": "abc123...",
  "OXSTATE": "PENDING",
  "OXBASKETDATA": {
    "items": [
      {
        "articleId": "art001",
        "title": "Product A",
        "amount": 2,
        "price": 29.99,
        "vat": 19.0
      }
    ],
    "discounts": [
      {
        "type": "voucher",
        "code": "SAVE10",
        "amount": -10.00
      }
    ],
    "totals": {
      "gross": 69.98,
      "net": 58.82,
      "vat": 11.16,
      "currency": "EUR"
    }
  },
  "OXCONDITIONS": [
    {
      "type": "payment_authorized",
      "status": "fulfilled",
      "data": {"authorization_id": "auth_xyz"},
      "fulfilledAt": "2025-10-20T14:30:15Z"
    },
    {
      "type": "fraud_check",
      "status": "fulfilled",
      "data": {"score": 98, "risk": "low"},
      "fulfilledAt": "2025-10-20T14:30:18Z"
    },
    {
      "type": "stock_reserved",
      "status": "pending",
      "data": null,
      "fulfilledAt": null
    }
  ]
}
```

---

## Implementation Examples

### Core Contract Model

```php
<?php

declare(strict_types=1);

namespace Osc\Payment\Component\Model;

use Osc\Payment\Component\ValueObject\ContractState;
use Osc\Payment\Component\ValueObject\BasketSnapshot;
use Osc\Payment\Component\Entity\ContractCondition;

/**
 * Payment Contract - Aggregate Root
 *
 * Manages the lifecycle from purchase intent to order creation.
 */
final class PaymentContract
{
    // States
    const STATE_DRAFT = 'draft';
    const STATE_PENDING = 'pending';
    const STATE_READY_TO_COMMIT = 'ready_to_commit';
    const STATE_COMMITTED = 'committed';
    const STATE_FULFILLED = 'fulfilled';
    const STATE_CANCELLED = 'cancelled';
    const STATE_EXPIRED = 'expired';
    const STATE_FAILED = 'failed';

    private ?string $id = null;
    private string $shopId;
    private string $userId;
    private ?string $orderId = null;
    private string $state;
    private ?string $stateReason = null;
    private BasketSnapshot $basketSnapshot;
    private array $conditions = [];
    private ?string $provider = null;
    private ?string $providerOrderId = null;
    private \DateTime $createdAt;
    private \DateTime $updatedAt;
    private ?\DateTime $committedAt = null;
    private ?\DateTime $fulfilledAt = null;
    private ?\DateTime $expiresAt = null;

    /** @var array Domain events */
    private array $events = [];

    public function __construct(
        string $shopId,
        string $userId,
        BasketSnapshot $basketSnapshot,
        string $state = self::STATE_DRAFT
    ) {
        $this->shopId = $shopId;
        $this->userId = $userId;
        $this->basketSnapshot = $basketSnapshot;
        $this->state = $state;
        $this->createdAt = new \DateTime();
        $this->updatedAt = new \DateTime();
        $this->expiresAt = new \DateTime('+24 hours');
    }

    /**
     * Add a condition that must be fulfilled
     */
    public function addCondition(ContractCondition $condition): void
    {
        if ($this->state !== self::STATE_DRAFT && $this->state !== self::STATE_PENDING) {
            throw new \LogicException(
                "Cannot add conditions to contract in state: {$this->state}"
            );
        }

        $this->conditions[] = $condition;
        $this->updatedAt = new \DateTime();
    }

    /**
     * Transition to pending state (conditions being resolved)
     */
    public function transitionToPending(): void
    {
        if ($this->state !== self::STATE_DRAFT) {
            throw new \LogicException("Cannot transition from {$this->state} to PENDING");
        }

        if (empty($this->conditions)) {
            throw new \LogicException("Cannot transition to PENDING without conditions");
        }

        $this->state = self::STATE_PENDING;
        $this->updatedAt = new \DateTime();

        $this->recordEvent(new ContractTransitionedToPendingEvent($this));
    }

    /**
     * Fulfill a specific condition
     */
    public function fulfillCondition(string $type, array $data = []): void
    {
        $conditionFulfilled = false;

        foreach ($this->conditions as $condition) {
            if ($condition->getType() === $type && !$condition->isFulfilled()) {
                $condition->fulfill($data);
                $conditionFulfilled = true;
                break;
            }
        }

        if (!$conditionFulfilled) {
            throw new \InvalidArgumentException("Condition not found or already fulfilled: {$type}");
        }

        $this->updatedAt = new \DateTime();

        $this->recordEvent(new ContractConditionFulfilledEvent($this, $type, $data));

        // Check if all conditions fulfilled
        if ($this->areAllConditionsFulfilled()) {
            $this->transitionToReadyToCommit();
        }
    }

    /**
     * Fail a specific condition
     */
    public function failCondition(string $type, string $reason): void
    {
        foreach ($this->conditions as $condition) {
            if ($condition->getType() === $type) {
                $condition->fail($reason);
                break;
            }
        }

        $this->state = self::STATE_FAILED;
        $this->stateReason = $reason;
        $this->updatedAt = new \DateTime();

        $this->recordEvent(new ContractFailedEvent($this, $type, $reason));
    }

    /**
     * Check if all conditions are fulfilled
     */
    public function areAllConditionsFulfilled(): bool
    {
        if (empty($this->conditions)) {
            return false;
        }

        foreach ($this->conditions as $condition) {
            if (!$condition->isFulfilled()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Transition to ready-to-commit state
     */
    private function transitionToReadyToCommit(): void
    {
        if ($this->state !== self::STATE_PENDING) {
            throw new \LogicException("Cannot transition from {$this->state} to READY_TO_COMMIT");
        }

        $this->state = self::STATE_READY_TO_COMMIT;
        $this->updatedAt = new \DateTime();

        $this->recordEvent(new ContractReadyToCommitEvent($this));
    }

    /**
     * Commit contract to order (links contract to oxorder)
     */
    public function commitToOrder(string $orderId): void
    {
        if ($this->state !== self::STATE_READY_TO_COMMIT) {
            throw new \LogicException(
                "Cannot commit contract in state: {$this->state}. Must be READY_TO_COMMIT."
            );
        }

        if (!$this->areAllConditionsFulfilled()) {
            throw new \LogicException("Cannot commit contract with unfulfilled conditions");
        }

        $this->orderId = $orderId;
        $this->state = self::STATE_COMMITTED;
        $this->committedAt = new \DateTime();
        $this->updatedAt = new \DateTime();

        $this->recordEvent(new ContractCommittedEvent($this, $orderId));
    }

    /**
     * Fulfill contract (payment captured, contract complete)
     */
    public function fulfill(): void
    {
        if ($this->state !== self::STATE_COMMITTED) {
            throw new \LogicException("Cannot fulfill contract in state: {$this->state}");
        }

        if (!$this->orderId) {
            throw new \LogicException("Cannot fulfill contract without order ID");
        }

        $this->state = self::STATE_FULFILLED;
        $this->fulfilledAt = new \DateTime();
        $this->updatedAt = new \DateTime();

        $this->recordEvent(new ContractFulfilledEvent($this));
    }

    /**
     * Cancel contract (user/system cancellation)
     */
    public function cancel(string $reason): void
    {
        if ($this->state === self::STATE_FULFILLED) {
            throw new \LogicException("Cannot cancel fulfilled contract");
        }

        $this->state = self::STATE_CANCELLED;
        $this->stateReason = $reason;
        $this->updatedAt = new \DateTime();

        $this->recordEvent(new ContractCancelledEvent($this, $reason));
    }

    /**
     * Mark contract as expired
     */
    public function expire(): void
    {
        if ($this->state === self::STATE_FULFILLED || $this->state === self::STATE_CANCELLED) {
            throw new \LogicException("Cannot expire contract in terminal state: {$this->state}");
        }

        $this->state = self::STATE_EXPIRED;
        $this->stateReason = 'Contract expired after 24 hours';
        $this->updatedAt = new \DateTime();

        $this->recordEvent(new ContractExpiredEvent($this));
    }

    /**
     * Check if contract is expired
     */
    public function isExpired(): bool
    {
        return $this->expiresAt && new \DateTime() > $this->expiresAt;
    }

    /**
     * Set provider information
     */
    public function setProvider(string $provider, string $providerOrderId): void
    {
        $this->provider = $provider;
        $this->providerOrderId = $providerOrderId;
        $this->updatedAt = new \DateTime();
    }

    // Getters
    public function getId(): ?string { return $this->id; }
    public function getShopId(): string { return $this->shopId; }
    public function getUserId(): string { return $this->userId; }
    public function getOrderId(): ?string { return $this->orderId; }
    public function getState(): string { return $this->state; }
    public function getStateReason(): ?string { return $this->stateReason; }
    public function getBasketSnapshot(): BasketSnapshot { return $this->basketSnapshot; }
    public function getConditions(): array { return $this->conditions; }
    public function getProvider(): ?string { return $this->provider; }
    public function getProviderOrderId(): ?string { return $this->providerOrderId; }
    public function getCreatedAt(): \DateTime { return $this->createdAt; }
    public function getUpdatedAt(): \DateTime { return $this->updatedAt; }
    public function getCommittedAt(): ?\DateTime { return $this->committedAt; }
    public function getFulfilledAt(): ?\DateTime { return $this->fulfilledAt; }
    public function getExpiresAt(): ?\DateTime { return $this->expiresAt; }

    /**
     * Get recorded domain events
     */
    public function getRecordedEvents(): array
    {
        return $this->events;
    }

    /**
     * Clear recorded domain events
     */
    public function clearRecordedEvents(): void
    {
        $this->events = [];
    }

    /**
     * Record a domain event
     */
    private function recordEvent(object $event): void
    {
        $this->events[] = $event;
    }
}
```

### Contract Condition Entity

```php
<?php

declare(strict_types=1);

namespace Osc\Payment\Component\Entity;

/**
 * Contract Condition
 *
 * Represents a precondition that must be fulfilled before contract can be committed.
 */
final class ContractCondition
{
    // Condition types
    const TYPE_PAYMENT_AUTHORIZED = 'payment_authorized';
    const TYPE_FRAUD_CHECK = 'fraud_check';
    const TYPE_STOCK_RESERVED = 'stock_reserved';
    const TYPE_COMPLIANCE_CHECK = 'compliance_check';
    const TYPE_ADDRESS_VALIDATED = 'address_validated';
    const TYPE_AGE_VERIFICATION = 'age_verification';
    const TYPE_CUSTOM = 'custom';

    // Statuses
    const STATUS_PENDING = 'pending';
    const STATUS_FULFILLED = 'fulfilled';
    const STATUS_FAILED = 'failed';

    private string $type;
    private string $status;
    private array $data;
    private \DateTime $createdAt;
    private ?\DateTime $fulfilledAt = null;
    private ?string $failureReason = null;

    public function __construct(
        string $type,
        string $status = self::STATUS_PENDING,
        array $data = []
    ) {
        $this->type = $type;
        $this->status = $status;
        $this->data = $data;
        $this->createdAt = new \DateTime();
    }

    public function fulfill(array $data = []): void
    {
        if ($this->status === self::STATUS_FULFILLED) {
            throw new \LogicException("Condition already fulfilled: {$this->type}");
        }

        $this->status = self::STATUS_FULFILLED;
        $this->data = array_merge($this->data, $data);
        $this->fulfilledAt = new \DateTime();
    }

    public function fail(string $reason): void
    {
        if ($this->status === self::STATUS_FULFILLED) {
            throw new \LogicException("Cannot fail already fulfilled condition: {$this->type}");
        }

        $this->status = self::STATUS_FAILED;
        $this->failureReason = $reason;
    }

    public function isFulfilled(): bool
    {
        return $this->status === self::STATUS_FULFILLED;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    // Getters
    public function getType(): string { return $this->type; }
    public function getStatus(): string { return $this->status; }
    public function getData(): array { return $this->data; }
    public function getCreatedAt(): \DateTime { return $this->createdAt; }
    public function getFulfilledAt(): ?\DateTime { return $this->fulfilledAt; }
    public function getFailureReason(): ?string { return $this->failureReason; }

    /**
     * Convert to array (for JSON storage)
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'status' => $this->status,
            'data' => $this->data,
            'createdAt' => $this->createdAt->format(\DateTime::ATOM),
            'fulfilledAt' => $this->fulfilledAt?->format(\DateTime::ATOM),
            'failureReason' => $this->failureReason,
        ];
    }

    /**
     * Create from array (from JSON storage)
     */
    public static function fromArray(array $data): self
    {
        $condition = new self(
            type: $data['type'],
            status: $data['status'],
            data: $data['data'] ?? []
        );

        $condition->createdAt = new \DateTime($data['createdAt']);

        if (!empty($data['fulfilledAt'])) {
            $condition->fulfilledAt = new \DateTime($data['fulfilledAt']);
        }

        if (!empty($data['failureReason'])) {
            $condition->failureReason = $data['failureReason'];
        }

        return $condition;
    }
}
```

### Contract Repository

```php
<?php

declare(strict_types=1);

namespace Osc\Payment\Component\Repository;

use Osc\Payment\Component\Model\PaymentContract;
use Osc\Payment\Component\Entity\ContractCondition;
use Osc\Payment\Component\ValueObject\BasketSnapshot;

final class ContractRepository
{
    public function __construct(
        private \Doctrine\DBAL\Connection $connection,
        private string $tableName = 'osc_payment_contract'
    ) {}

    public function save(PaymentContract $contract): void
    {
        if ($contract->getId()) {
            $this->update($contract);
        } else {
            $this->insert($contract);
        }
    }

    private function insert(PaymentContract $contract): void
    {
        $id = $this->generateId();

        $this->connection->insert($this->tableName, [
            'OXID' => $id,
            'OXSHOPID' => $contract->getShopId(),
            'OXUSERID' => $contract->getUserId(),
            'OXORDERID' => $contract->getOrderId(),
            'OXSTATE' => $contract->getState(),
            'OXSTATEREASON' => $contract->getStateReason(),
            'OXBASKETDATA' => json_encode($contract->getBasketSnapshot()->toArray()),
            'OXCONDITIONS' => json_encode(
                array_map(fn($c) => $c->toArray(), $contract->getConditions())
            ),
            'OXPROVIDER' => $contract->getProvider(),
            'OXPROVIDERORDERID' => $contract->getProviderOrderId(),
            'OXCREATED' => $contract->getCreatedAt()->format('Y-m-d H:i:s'),
            'OXUPDATED' => $contract->getUpdatedAt()->format('Y-m-d H:i:s'),
            'OXCOMMITTEDAT' => $contract->getCommittedAt()?->format('Y-m-d H:i:s'),
            'OXFULFILLEDAT' => $contract->getFulfilledAt()?->format('Y-m-d H:i:s'),
            'OXEXPIRESAT' => $contract->getExpiresAt()?->format('Y-m-d H:i:s'),
        ]);

        // Set ID on contract via reflection (hack, but needed)
        $reflection = new \ReflectionClass($contract);
        $property = $reflection->getProperty('id');
        $property->setAccessible(true);
        $property->setValue($contract, $id);
    }

    private function update(PaymentContract $contract): void
    {
        $this->connection->update(
            $this->tableName,
            [
                'OXORDERID' => $contract->getOrderId(),
                'OXSTATE' => $contract->getState(),
                'OXSTATEREASON' => $contract->getStateReason(),
                'OXCONDITIONS' => json_encode(
                    array_map(fn($c) => $c->toArray(), $contract->getConditions())
                ),
                'OXPROVIDER' => $contract->getProvider(),
                'OXPROVIDERORDERID' => $contract->getProviderOrderId(),
                'OXUPDATED' => $contract->getUpdatedAt()->format('Y-m-d H:i:s'),
                'OXCOMMITTEDAT' => $contract->getCommittedAt()?->format('Y-m-d H:i:s'),
                'OXFULFILLEDAT' => $contract->getFulfilledAt()?->format('Y-m-d H:i:s'),
            ],
            ['OXID' => $contract->getId()]
        );
    }

    public function find(string $id): ?PaymentContract
    {
        $data = $this->connection->fetchAssociative(
            "SELECT * FROM {$this->tableName} WHERE OXID = ?",
            [$id]
        );

        return $data ? $this->hydrate($data) : null;
    }

    public function findByProviderOrderId(string $providerOrderId): ?PaymentContract
    {
        $data = $this->connection->fetchAssociative(
            "SELECT * FROM {$this->tableName} WHERE OXPROVIDERORDERID = ?",
            [$providerOrderId]
        );

        return $data ? $this->hydrate($data) : null;
    }

    public function findByOrderId(string $orderId): ?PaymentContract
    {
        $data = $this->connection->fetchAssociative(
            "SELECT * FROM {$this->tableName} WHERE OXORDERID = ?",
            [$orderId]
        );

        return $data ? $this->hydrate($data) : null;
    }

    /**
     * Find expired contracts (for cleanup)
     */
    public function findExpired(\DateTime $before = null): array
    {
        $before = $before ?? new \DateTime();

        $rows = $this->connection->fetchAllAssociative(
            "SELECT * FROM {$this->tableName}
             WHERE OXEXPIRESAT < ?
             AND OXSTATE NOT IN (?, ?, ?)",
            [
                $before->format('Y-m-d H:i:s'),
                PaymentContract::STATE_FULFILLED,
                PaymentContract::STATE_CANCELLED,
                PaymentContract::STATE_EXPIRED,
            ]
        );

        return array_map(fn($row) => $this->hydrate($row), $rows);
    }

    private function hydrate(array $data): PaymentContract
    {
        $basketData = json_decode($data['OXBASKETDATA'], true);
        $basketSnapshot = BasketSnapshot::fromArray($basketData);

        $contract = new PaymentContract(
            shopId: (string)$data['OXSHOPID'],
            userId: $data['OXUSERID'],
            basketSnapshot: $basketSnapshot,
            state: $data['OXSTATE']
        );

        // Set ID
        $reflection = new \ReflectionClass($contract);
        $property = $reflection->getProperty('id');
        $property->setAccessible(true);
        $property->setValue($contract, $data['OXID']);

        // Hydrate conditions
        $conditionsData = json_decode($data['OXCONDITIONS'], true);
        foreach ($conditionsData as $conditionData) {
            $contract->addCondition(ContractCondition::fromArray($conditionData));
        }

        // Set other properties via reflection (simplified)
        // ... (full implementation would set all properties)

        return $contract;
    }

    private function generateId(): string
    {
        return bin2hex(random_bytes(16));
    }
}
```

---

## Migration Strategy

### Phase 1: Foundation (Sprint 1)

**Goal:** Add contract infrastructure without changing existing flow.

**Tasks:**
1. Create `osc_payment_contract` table
2. Implement `PaymentContract` model
3. Implement `ContractCondition` entity
4. Implement `ContractRepository`
5. Add contract events (ContractCreatedEvent, etc.)
6. Write unit tests for contract domain logic

**Outcome:** Contract system exists but not used yet. Zero impact on production.

---

### Phase 2: Dual-Path Implementation (Sprint 2-3)

**Goal:** Run both old and new flows in parallel.

**Tasks:**
1. Add feature flag: `payment.use_smart_contract`
2. Implement contract creation in `PaymentInitiationHandler`
3. Implement condition resolution handlers
4. Implement `OrderCreationHandler` (contract → order)
5. Add contract-based event flow
6. Keep old flow as fallback

**Code Example:**
```php
class PaymentInitiationHandler
{
    public function handle(PaymentInitiatedEvent $event): void
    {
        if ($this->featureFlags->isEnabled('payment.use_smart_contract')) {
            // New flow: Create contract
            $this->createContract($event);
        } else {
            // Old flow: Create order immediately
            $this->createOrder($event);
        }
    }
}
```

**Outcome:** Can A/B test contract flow with 10% of traffic.

---

### Phase 3: Gradual Rollout (Sprint 4-5)

**Goal:** Increase contract adoption, monitor metrics.

**Rollout Plan:**
```
Week 1: 10% traffic → Monitor errors, performance
Week 2: 25% traffic → Collect feedback
Week 3: 50% traffic → Performance optimization
Week 4: 75% traffic → Final testing
Week 5: 100% traffic → Full rollout
```

**Metrics to Monitor:**
- Contract creation success rate
- Condition fulfillment rates
- Time to order creation (performance)
- Error rates
- Database storage growth

---

### Phase 4: Deprecation (Sprint 6)

**Goal:** Remove old flow, contract-only.

**Tasks:**
1. Remove feature flag
2. Delete old order creation code
3. Update documentation
4. Archive old flow diagrams

**Outcome:** Clean codebase, single flow.

---

### Rollback Plan

**If Issues Arise:**
```
1. Disable feature flag → Revert to old flow
2. Investigate root cause
3. Fix issues
4. Re-enable feature flag for 10% traffic
5. Repeat rollout
```

**Rollback Triggers:**
- Error rate > 1%
- Performance degradation > 20%
- Order completion rate drops > 5%
- Customer complaints spike

---

## Comparison Matrix

### Current Architecture vs. Smart-Contract (Detailed)

| Feature | Current Architecture | Smart-Contract Architecture | Winner |
|---------|---------------------|---------------------------|--------|
| **Order Creation Timing** | Immediate (on "Place Order") | Deferred (after conditions met) | 🏆 Smart-Contract |
| **Order State Clarity** | NOT_FINISHED, 500-900, OK | Only NOT_FINISHED, OK | 🏆 Smart-Contract |
| **Rollback Complexity** | Delete order or mark cancelled | Cancel contract (no order yet) | 🏆 Smart-Contract |
| **Order Number Gaps** | Yes (failed payments get numbers) | No (numbers only for fulfilled) | 🏆 Smart-Contract |
| **OXID Coupling** | High (order created early) | Low (order created when ready) | 🏆 Smart-Contract |
| **API Semantics** | Confusing (order ID before payment) | Clear (contract ID → order ID) | 🏆 Smart-Contract |
| **Condition Tracking** | Implicit (in code) | Explicit (in database) | 🏆 Smart-Contract |
| **Audit Trail** | Scattered (order + state + tx) | Centralized (contract) | 🏆 Smart-Contract |
| **DDD Alignment** | Moderate | Excellent | 🏆 Smart-Contract |
| **Testability** | Requires database | Pure domain logic | 🏆 Smart-Contract |
| **Implementation Complexity** | Low (existing) | Medium (new layer) | 🏆 Current |
| **Storage Overhead** | Low (baseline) | Medium (+10-15%) | 🏆 Current |
| **Learning Curve** | None (existing) | Medium (2-3 weeks) | 🏆 Current |
| **Migration Risk** | None (existing) | Low-Medium | 🏆 Current |
| **OXID Admin Compatibility** | Full | Requires extensions | 🏆 Current |
| **Performance** | Baseline | +50ms (+33%) | 🏆 Current |
| **Backward Compatibility** | N/A | Requires dual-path | 🏆 Current |

**Score: Smart-Contract 10, Current 7**

### Use Case Suitability

| Use Case | Current | Smart-Contract | Reason |
|----------|---------|---------------|---------|
| **Simple checkout** | ✅ Good | ✅ Good | Both work |
| **Multi-step checkout** | ⚠️ OK | 🏆 Excellent | Contract tracks steps better |
| **Headless/API commerce** | ⚠️ OK | 🏆 Excellent | API semantics clearer |
| **Programmatic commerce (AI)** | ❌ Poor | 🏆 Excellent | Contract = explicit conditions |
| **High fraud risk** | ⚠️ OK | 🏆 Excellent | Explicit fraud check condition |
| **Complex payment flows** | ⚠️ OK | 🏆 Excellent | Multiple conditions supported |
| **Legacy OXID shops** | 🏆 Excellent | ⚠️ OK | Admin UI compatibility |
| **High-traffic shops** | 🏆 Excellent | ⚠️ OK | Performance overhead |
| **Audit/compliance** | ⚠️ OK | 🏆 Excellent | Complete audit trail |

---

## Recommendations

### For New Implementations: ✅ **Use Smart-Contract Pattern**

**Rationale:**
- Clean slate, no migration needed
- Future-proof architecture
- Better API-first design
- Aligns with modern e-commerce patterns

**Implementation Path:**
1. Start with hybrid pattern
2. Skip old flow entirely
3. Build admin UI for contracts
4. Focus on contract-first development

---

### For Existing Implementations: ⚠️ **Evaluate Carefully**

**Use Smart-Contract If:**
- ✅ Planning major refactor anyway
- ✅ Need headless/API commerce
- ✅ High fraud risk requires explicit checks
- ✅ Want better audit trail
- ✅ Team has DDD experience

**Stick with Current If:**
- ❌ Legacy codebase (OXID 6.x or earlier)
- ❌ No resources for migration (3-4 sprints)
- ❌ Simple checkout flows only
- ❌ Tight coupling to OXID admin UI

---

### Hybrid Approach: 🏆 **Recommended for Most**

**Best of Both Worlds:**
- Progressive enhancement (add contracts without breaking existing)
- Feature flag controlled (safe rollout)
- Can run both flows in parallel (A/B test)
- Rollback possible (disable feature flag)

**Timeline:**
- Sprint 1: Foundation (2 weeks)
- Sprint 2-3: Dual-path (4 weeks)
- Sprint 4-5: Gradual rollout (4 weeks)
- Sprint 6: Cleanup (1 week)
- **Total: ~11 weeks**

---

## Payment Provider Contract Patterns Analysis

This section analyzes how major payment providers implement contract-like patterns in their SDKs, validating our smart-contract architecture approach.

### Overview: Industry Adoption of Contract Patterns

Many modern payment providers have **independently evolved** contract-like patterns in their APIs, though they use different terminology:

| Provider | Contract Concept | Intent Object | Commitment Object | Our Term |
|----------|------------------|---------------|-------------------|----------|
| **Stripe** | Payment Intent | `PaymentIntent` (requires_confirmation) | `PaymentIntent` (succeeded) | Contract |
| **PayPal** | Order | `Order` (CREATED) | `Order` (COMPLETED) | Contract |
| **Amazon Pay** | Charge Permission | `ChargePermission` (Open) | `Charge` (Captured) | Contract |
| **Adyen** | Payment | `Payment` (Authorised) | `Payment` (SettledSuccessfully) | Contract |
| **Klarna** | Session | `Session` (created) | `Order` (captured) | Contract |
| **Square** | Payment | `Payment` (APPROVED) | `Payment` (COMPLETED) | Contract |

**Key Insight:** Major payment providers **already use two-phase patterns** that separate "intent to pay" from "payment completed". Our smart-contract architecture **aligns perfectly** with these industry patterns.

---

### 1. Stripe - Payment Intent Pattern

**Documentation:** https://stripe.com/docs/payments/payment-intents

#### Stripe's Contract: `PaymentIntent`

Stripe's `PaymentIntent` is essentially a **payment contract** that tracks the customer's intent to pay:

```php
// Phase 1: Create Payment Intent (Contract Created)
$paymentIntent = \Stripe\PaymentIntent::create([
    'amount' => 2000,
    'currency' => 'eur',
    'payment_method_types' => ['card'],
    'capture_method' => 'manual', // Two-step authorization
    'confirmation_method' => 'manual', // Requires confirmation
]);

// PaymentIntent State: requires_confirmation
// This is the "contract" - intent expressed, not yet committed
```

#### State Machine (Stripe's Contract States)

```
requires_payment_method → requires_confirmation → requires_action (3DS)
→ processing → requires_capture → succeeded
```

**Mapping to Our Pattern:**

| Stripe State | Our Contract State | Meaning |
|--------------|-------------------|---------|
| `requires_confirmation` | `PENDING` | Intent created, awaiting confirmation |
| `requires_action` | `PENDING` (condition: 3DS) | Needs customer action (3DS) |
| `requires_capture` | `COMMITTED` | Authorized, awaiting capture |
| `succeeded` | `FULFILLED` | Payment completed |
| `canceled` | `CANCELLED` | Intent cancelled |

#### Stripe's Conditions (Implicit)

Stripe tracks conditions implicitly within the PaymentIntent:

- **Payment Method Attached** (`payment_method` set)
- **Customer Confirmation** (`confirm()` called)
- **3D Secure Completed** (`requires_action` → `processing`)
- **Authorization Succeeded** (`requires_capture`)

**Analysis:** Stripe's PaymentIntent **IS a contract pattern**. It separates intent from fulfillment, tracks preconditions, and manages state transitions.

---

### 2. PayPal - Order Pattern

**Documentation:** https://developer.paypal.com/docs/api/orders/v2/

#### PayPal's Contract: `Order`

PayPal's `Order` object represents the **purchase intent** before funds are captured:

```php
// Phase 1: Create Order (Contract Created)
$order = $paypalClient->orders()->create([
    'intent' => 'CAPTURE', // or 'AUTHORIZE'
    'purchase_units' => [
        [
            'amount' => ['currency_code' => 'EUR', 'value' => '100.00'],
            'items' => [...],
        ]
    ],
    'application_context' => [
        'return_url' => 'https://example.com/return',
        'cancel_url' => 'https://example.com/cancel',
    ]
]);

// Order State: CREATED
// This is the "contract" - purchase defined, awaiting approval
```

#### State Machine (PayPal's Contract States)

```
CREATED → APPROVED → COMPLETED (if intent=CAPTURE)
CREATED → APPROVED → AUTHORIZED → CAPTURED (if intent=AUTHORIZE)
```

**Mapping to Our Pattern:**

| PayPal State | Our Contract State | Meaning |
|--------------|-------------------|---------|
| `CREATED` | `DRAFT` | Order created, no approval yet |
| `APPROVED` | `COMMITTED` | Customer approved, ready to capture |
| `COMPLETED` | `FULFILLED` | Payment captured |
| `VOIDED` | `CANCELLED` | Order cancelled |

#### PayPal's Conditions (Explicit in Approval Flow)

- **Customer Approval** (redirect to PayPal, customer approves)
- **Payment Source Validated** (PayPal checks funding)
- **Risk Check Passed** (PayPal's internal fraud checks)

**Analysis:** PayPal's Order **IS a contract pattern**. The `CREATED → APPROVED → COMPLETED` flow directly maps to `DRAFT → COMMITTED → FULFILLED`.

---

### 3. Amazon Pay - Charge Permission Pattern

**Documentation:** https://developer.amazon.com/docs/amazon-pay-api/charge-permission.html

#### Amazon Pay's Contract: `ChargePermission`

Amazon Pay uses a **two-tier contract pattern**:

1. **Charge Permission** = High-level contract (can create multiple charges)
2. **Charge** = Individual payment from the permission

```php
// Phase 1: Create Charge Permission (Contract Created)
$chargePermission = $amazonClient->chargePermissions()->create([
    'chargePermissionType' => 'OneTime', // or 'Recurring'
    'paymentDetails' => [
        'chargeAmount' => ['amount' => 100, 'currencyCode' => 'EUR'],
        'totalOrderAmount' => ['amount' => 100, 'currencyCode' => 'EUR'],
    ],
    'checkoutSessionId' => $sessionId,
]);

// ChargePermission State: Open
// This is the "contract" - permission granted, can create charges

// Phase 2: Create Charge (Commit Contract)
$charge = $amazonClient->charges()->create([
    'chargePermissionId' => $chargePermission->id,
    'chargeAmount' => ['amount' => 100, 'currencyCode' => 'EUR'],
    'captureNow' => false, // Two-step auth
]);

// Charge State: AuthorizationInitiated → Authorized → Captured
```

#### State Machine (Amazon's Contract States)

**Charge Permission:**
```
Chargeable → Closed (completed/expired/cancelled)
```

**Charge (Individual Contract):**
```
AuthorizationInitiated → Authorized → Captured
```

**Mapping to Our Pattern:**

| Amazon State | Our Contract State | Meaning |
|-------------|-------------------|---------|
| `Chargeable` (ChargePermission) | `PENDING` | Permission to charge granted |
| `Authorized` (Charge) | `COMMITTED` | Funds reserved |
| `Captured` (Charge) | `FULFILLED` | Payment completed |
| `Closed` | `CANCELLED` | Permission closed |

#### Amazon's Conditions (Explicit)

Amazon Pay requires **explicit tracking of fulfillment**:

- **Delivery Tracking** (MUST provide tracking number before capture confirmed)
- **Authorization Expiry** (7 days, can extend up to 30 days)
- **Capture Delay** (soft descriptor after shipment)

**Analysis:** Amazon Pay has the **most explicit contract pattern**. ChargePermission is a long-lived contract, individual Charges are short-term contracts. Requires delivery tracking as a **fulfillment condition**.

---

### 4. Adyen - Payment & Modification Pattern

**Documentation:** https://docs.adyen.com/online-payments/

#### Adyen's Contract: `Payment`

Adyen's `Payment` object with `captureDelayHours` implements a contract pattern:

```php
// Phase 1: Create Payment with Delayed Capture (Contract Created)
$payment = $adyenClient->payments()->create([
    'amount' => ['currency' => 'EUR', 'value' => 10000], // cents
    'reference' => 'ORDER-123',
    'paymentMethod' => [...],
    'returnUrl' => 'https://example.com/return',
    'captureDelayHours' => 168, // 7 days (manual capture)
]);

// Payment State: Authorised
// This is the "contract" - authorized, awaiting capture

// Phase 2: Capture (Fulfill Contract)
$capture = $adyenClient->payments()->capture([
    'originalReference' => $payment->pspReference,
    'modificationAmount' => ['currency' => 'EUR', 'value' => 10000],
]);
```

#### State Machine (Adyen's Contract States)

```
Pending → Authorised → [SentForSettle → SettledSuccessfully]
                    → [Cancelled]
```

**Mapping to Our Pattern:**

| Adyen State | Our Contract State | Meaning |
|-----------|--------------------|---------|
| `Authorised` | `COMMITTED` | Payment authorized |
| `SentForSettle` | `COMMITTED` | Capture requested |
| `SettledSuccessfully` | `FULFILLED` | Payment completed |
| `Cancelled` | `CANCELLED` | Authorization cancelled |

#### Adyen's Conditions (Implicit)

- **3D Secure Completion** (if required, redirect flow)
- **Risk Check Passed** (Adyen's fraud detection)
- **Capture Window** (7-30 days depending on config)

**Analysis:** Adyen's payment with `captureDelayHours` **IS a contract pattern**. The authorization is a commitment that can be fulfilled (captured) or cancelled.

---

### 5. Klarna - Session & Order Pattern

**Documentation:** https://docs.klarna.com/klarna-payments/

#### Klarna's Contract: `Session` → `Order`

Klarna uses a **two-phase contract**:

1. **Session** = Intent (customer information gathered)
2. **Order** = Commitment (created after approval)

```php
// Phase 1: Create Session (Contract Draft)
$session = $klarnaClient->sessions()->create([
    'purchase_country' => 'DE',
    'purchase_currency' => 'EUR',
    'locale' => 'de-DE',
    'order_amount' => 10000,
    'order_lines' => [...],
]);

// Session State: created
// This is the "draft contract" - collecting customer info

// Phase 2: Create Order (Commit Contract)
$order = $klarnaClient->orders()->create([
    'authorization_token' => $session->token,
    'purchase_country' => 'DE',
    'purchase_currency' => 'EUR',
    'order_amount' => 10000,
    'order_lines' => [...],
]);

// Order State: AUTHORIZED
// This is the "committed contract"

// Phase 3: Capture (Fulfill Contract)
$capture = $klarnaClient->orders()->capture($order->id, [
    'captured_amount' => 10000,
]);
```

#### State Machine (Klarna's Contract States)

**Session:**
```
created → (customer completes checkout)
```

**Order:**
```
AUTHORIZED → PART_CAPTURED → CAPTURED
          → CANCELLED
```

**Mapping to Our Pattern:**

| Klarna State | Our Contract State | Meaning |
|-------------|-------------------|---------|
| Session `created` | `DRAFT` | Customer info collection |
| Order `AUTHORIZED` | `COMMITTED` | Customer approved |
| Order `CAPTURED` | `FULFILLED` | Payment completed |
| Order `CANCELLED` | `CANCELLED` | Authorization cancelled |

#### Klarna's Conditions (Explicit)

- **Credit Check** (Klarna's risk assessment)
- **Customer Approval** (customer completes checkout)
- **Delivery Tracking** (recommended for capture)

**Analysis:** Klarna has a **clear two-phase contract**. Session = draft, Order = commitment. Requires explicit capture after delivery.

---

### 6. Square - Payment Intent Pattern

**Documentation:** https://developer.squareup.com/docs/payments-api/

#### Square's Contract: `Payment` with `APPROVED` State

Square's `Payment` object with autocomplete disabled acts as a contract:

```php
// Phase 1: Create Payment (Contract Created)
$payment = $squareClient->payments()->create([
    'source_id' => $cardNonce,
    'idempotency_key' => uniqid(),
    'amount_money' => ['amount' => 10000, 'currency' => 'EUR'],
    'autocomplete' => false, // Two-step: approve then complete
]);

// Payment State: APPROVED
// This is the "contract" - approved, awaiting completion

// Phase 2: Complete Payment (Fulfill Contract)
$complete = $squareClient->payments()->complete($payment->id);

// Payment State: COMPLETED
```

#### State Machine (Square's Contract States)

```
APPROVED → COMPLETED
        → CANCELED
        → FAILED
```

**Mapping to Our Pattern:**

| Square State | Our Contract State | Meaning |
|-------------|-------------------|---------|
| `APPROVED` | `COMMITTED` | Payment approved, awaiting completion |
| `COMPLETED` | `FULFILLED` | Payment completed |
| `CANCELED` | `CANCELLED` | Payment cancelled |

#### Square's Conditions (Implicit)

- **Payment Source Validated** (card/bank account verified)
- **Risk Check Passed** (Square's fraud detection)
- **Completion Window** (must complete within 7 days)

**Analysis:** Square's two-step payment with `autocomplete: false` **IS a contract pattern**. Approved = committed, completed = fulfilled.

---

### Cross-Provider Comparison

| Feature | Stripe | PayPal | Amazon Pay | Adyen | Klarna | Square |
|---------|--------|--------|------------|-------|--------|--------|
| **Contract Object** | PaymentIntent | Order | ChargePermission | Payment | Session+Order | Payment |
| **Intent State** | requires_confirmation | CREATED | Chargeable | Pending | Session created | - |
| **Committed State** | requires_capture | APPROVED | Authorized | Authorised | AUTHORIZED | APPROVED |
| **Fulfilled State** | succeeded | COMPLETED | Captured | SettledSuccessfully | CAPTURED | COMPLETED |
| **Two-Phase Flow** | ✅ Yes | ✅ Yes | ✅ Yes | ✅ Yes | ✅ Yes | ✅ Yes |
| **Explicit Conditions** | ❌ Implicit | ❌ Implicit | ✅ Delivery tracking | ❌ Implicit | ⚠️ Credit check | ❌ Implicit |
| **State Machine** | ✅ Complex (8 states) | ✅ Simple (4 states) | ✅ Complex (2 objects) | ✅ Medium (5 states) | ✅ Medium (6 states) | ✅ Simple (4 states) |
| **Contract Lifecycle** | Minutes-Days | Minutes-Hours | Days-Weeks | Days-Weeks | Days | Days |
| **Cancellation** | ✅ Yes | ✅ Yes | ✅ Yes | ✅ Yes | ✅ Yes | ✅ Yes |
| **Partial Capture** | ✅ Yes | ✅ Yes | ✅ Yes | ✅ Yes | ✅ Yes | ❌ No |

---

### Industry Validation: Why This Matters

#### 1. **All Major Providers Use Two-Phase Patterns**

Every analyzed provider (Stripe, PayPal, Amazon, Adyen, Klarna, Square) implements a **two-phase commit pattern**:

```
Phase 1: Intent/Authorization (Contract Created)
Phase 2: Capture/Completion (Contract Fulfilled)
```

Our smart-contract pattern **aligns perfectly** with industry standards.

#### 2. **State Machines Are Universal**

All providers use state machines to track payment lifecycle:

- **Initial State** (intent expressed)
- **Intermediate States** (conditions being resolved)
- **Terminal States** (success, failure, cancellation)

Our contract state machine (`DRAFT → PENDING → COMMITTED → FULFILLED`) **matches this pattern**.

#### 3. **Conditions Exist (But Often Hidden)**

Most providers have implicit conditions:
- **Stripe:** Payment method attached, confirmation received, 3DS completed
- **PayPal:** Customer approval, risk check passed
- **Amazon Pay:** Delivery tracking (explicit!)
- **Adyen:** 3DS completion, risk check
- **Klarna:** Credit check, customer approval
- **Square:** Payment source validated, risk check

Our smart-contract pattern **makes these conditions explicit** in code and database.

#### 4. **Separation of Concerns Is Standard**

Providers separate:
- **Intent** (what customer wants to buy)
- **Authorization** (can customer pay?)
- **Capture** (actually take money)

Our smart-contract pattern **extends this separation** to the shop order:
- **Contract** (intent + authorization conditions)
- **Order** (fulfillment + shipping)

---

### Mapping Provider Patterns to Our Smart-Contract

Here's how provider SDKs map to our contract lifecycle:

```php
// OUR PATTERN: Contract Creation
$contract = new PaymentContract($basket, $user);
$contract->addCondition('payment_authorized');
$contract->transitionToPending();

// STRIPE: Create PaymentIntent
$paymentIntent = \Stripe\PaymentIntent::create([...]);
// Maps to: Contract DRAFT → PENDING

// PAYPAL: Create Order
$order = $paypalClient->orders()->create([...]);
// Maps to: Contract DRAFT → PENDING

// AMAZON: Create ChargePermission
$chargePermission = $amazonClient->chargePermissions()->create([...]);
// Maps to: Contract DRAFT → PENDING
```

```php
// OUR PATTERN: Condition Fulfillment
$contract->fulfillCondition('payment_authorized', $authData);

// STRIPE: Confirm PaymentIntent
$paymentIntent->confirm();
// Maps to: Contract condition fulfilled

// PAYPAL: Approve Order (by customer)
// Happens on PayPal site
// Maps to: Contract condition fulfilled

// AMAZON: Create Charge
$charge = $amazonClient->charges()->create([...]);
// Maps to: Contract condition fulfilled
```

```php
// OUR PATTERN: Order Creation (All Conditions Met)
if ($contract->areAllConditionsFulfilled()) {
    $order = $orderFactory->createFromContract($contract);
    $contract->commitToOrder($order->getId());
}

// STRIPE: Check PaymentIntent status
if ($paymentIntent->status === 'requires_capture') {
    // Order creation point
}

// PAYPAL: Check Order status
if ($order->status === 'APPROVED') {
    // Order creation point
}

// AMAZON: Check Charge status
if ($charge->statusDetails->state === 'Authorized') {
    // Order creation point
}
```

```php
// OUR PATTERN: Contract Fulfillment
$contract->fulfill();
$order->markAsOK();

// STRIPE: Capture PaymentIntent
$paymentIntent->capture();
// Maps to: Contract FULFILLED

// PAYPAL: Capture Order
$paypalClient->orders()->capture($orderId);
// Maps to: Contract FULFILLED

// AMAZON: Capture Charge
$amazonClient->charges()->capture($chargeId);
// Maps to: Contract FULFILLED
```

---

### Key Insights from Provider Analysis

#### 1. **Our Pattern Is Industry-Standard**

✅ The smart-contract pattern is **not novel** - it's how modern payment providers work internally.

✅ We're **exposing** what providers already do, making it **explicit** in our architecture.

#### 2. **Providers Already Separate Intent from Fulfillment**

✅ Stripe's `PaymentIntent` (requires_confirmation → succeeded)
✅ PayPal's `Order` (CREATED → APPROVED → COMPLETED)
✅ Amazon's `ChargePermission` → `Charge` (Chargeable → Authorized → Captured)

**Our contribution:** Extend this pattern to the **shop order lifecycle**.

#### 3. **Conditions Exist But Are Hidden**

⚠️ Most providers have **implicit conditions** tracked internally:
- Credit checks
- Fraud scoring
- 3DS authentication
- Payment source validation

**Our contribution:** Make conditions **explicit** and **trackable** in our domain model.

#### 4. **Two-Phase Commit Is Universal**

✅ Every provider uses a two-phase pattern to prevent duplicate charges:

```
Phase 1: Reserve funds (authorization) - Reversible
Phase 2: Transfer funds (capture) - Final
```

**Our contribution:** Apply two-phase pattern to **order creation**:

```
Phase 1: Create contract (intent) - Cancellable
Phase 2: Create order (commitment) - Real
```

#### 5. **State Machines Are Critical**

✅ Providers rely on **state machines** to manage payment lifecycle.

**Our contribution:** Implement **explicit state machine** for contract lifecycle with clear state transitions.

---

### Recommendations Based on Provider Analysis

#### 1. **Adopt Provider Terminology Mappings**

Document explicit mappings between provider states and our contract states:

```php
class StripeStateMapper
{
    public function toContractState(string $stripeStatus): string
    {
        return match($stripeStatus) {
            'requires_confirmation' => PaymentContract::STATE_PENDING,
            'requires_action' => PaymentContract::STATE_PENDING,
            'requires_capture' => PaymentContract::STATE_COMMITTED,
            'succeeded' => PaymentContract::STATE_FULFILLED,
            'canceled' => PaymentContract::STATE_CANCELLED,
        };
    }
}
```

#### 2. **Use Provider Contract IDs**

Store provider contract IDs (PaymentIntent ID, Order ID, etc.) in our contract:

```sql
CREATE TABLE osc_payment_contract (
    OXPROVIDER VARCHAR(32),  -- 'stripe', 'paypal', etc.
    OXPROVIDERORDERID VARCHAR(128),  -- Provider's contract ID
    -- ...
);
```

#### 3. **Leverage Provider State Machines**

Don't reinvent the wheel - **map** provider state machines to our contract:

```php
// Stripe webhook handler
public function handlePaymentIntentSucceeded(PaymentIntent $intent): void
{
    $contract = $this->contractRepo->findByProviderOrderId($intent->id);
    $contract->fulfill(); // Maps Stripe 'succeeded' → Contract FULFILLED
}
```

#### 4. **Expose Provider Conditions**

Make provider conditions explicit in our contract:

```php
// Stripe requires 3DS
if ($paymentIntent->status === 'requires_action') {
    $contract->addCondition(new ContractCondition(
        type: 'stripe_3ds',
        data: ['client_secret' => $paymentIntent->client_secret]
    ));
}
```

#### 5. **Build Provider-Specific Adapters**

Each provider adapter should map its contract pattern to ours:

```php
interface ProviderContractAdapter
{
    public function createContract(PaymentContract $contract): ProviderContract;
    public function getContractState(string $providerContractId): ContractState;
    public function fulfillContract(string $providerContractId): void;
}
```

---

### Conclusion from Provider Analysis

**Finding:** All major payment providers (Stripe, PayPal, Amazon Pay, Adyen, Klarna, Square) independently evolved **contract-like patterns** in their APIs.

**Validation:** Our smart-contract architecture **aligns perfectly** with industry standards. We're not inventing a new pattern - we're **exposing and extending** what payment providers already do.

**Recommendation:** **Strongly adopt** the smart-contract pattern. It's not just a good idea - it's **how modern payment processing works**.

**Next Steps:**
1. Document provider state mappings
2. Build provider adapters with contract awareness
3. Expose provider conditions in our contract model
4. Test contract flow with all 6 analyzed providers

---

## Conclusion

The **Smart-Contract pattern** represents a **significant architectural improvement** for payment processing in OXID eShop:

### Key Takeaways

1. **Philosophically Sound:** Separates intent (contract) from commitment (order)
2. **Pragmatically Viable:** Hybrid pattern maintains OXID compatibility
3. **Future-Proof:** Perfect for API-first, headless, programmatic commerce
4. **Manageable Complexity:** Disadvantages are mitigated with proper planning

### Decision Framework

**Choose Smart-Contract If:**
```
(Need API-first OR Need headless OR High fraud risk)
AND
(Team has DDD skills OR Willing to invest in training)
AND
(OXID 7.4+ OR Planning upgrade)
```

**Stick with Current If:**
```
Legacy system (OXID 6.x)
OR
No budget for 11-week migration
OR
Simple checkout only
```

### Final Recommendation

✅ **Implement the Hybrid Smart-Contract Pattern**

The benefits significantly outweigh the costs, especially for:
- Modern e-commerce platforms
- API-first architectures
- Multi-channel commerce
- High-value transactions requiring explicit approval steps

The hybrid approach allows progressive enhancement without breaking changes, making it a **low-risk, high-reward architectural investment**.

---

**Related Documents:**
- [01-architecture-layers.md](01-architecture-layers.md) - Base event-driven architecture
- [02-database-and-models.md](02-database-and-models.md) - Database design
- [06-onepage-checkout.md](06-onepage-checkout.md) - Checkout implementation

**Status:** ✅ Ready for Architecture Review

**Next Steps:**
1. Team review and decision
2. Proof-of-concept implementation (1 sprint)
3. Performance benchmarking
4. Migration plan finalization
