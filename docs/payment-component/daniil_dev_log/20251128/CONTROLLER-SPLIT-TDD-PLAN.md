# OrderController Split: TDD Implementation Plan (Revised v2)

**Date:** November 28, 2025
**Developer:** Daniil (Claude Code)
**Status:** PLANNING (REVISED v2)

---

## Critical Insight: Contract BEFORE Order

The PUML diagrams show **Contract-First Architecture**:

```
┌─────────────────────────────────────────────────────────────────────┐
│                  CONTRACT-FIRST FLOW (Per PUML)                     │
├─────────────────────────────────────────────────────────────────────┤
│                                                                     │
│  1. Customer clicks "Place Order"                                   │
│     │                                                               │
│     ▼                                                               │
│  CONTRACT created (state: DRAFT)                                    │
│     • Basket snapshot captured                                      │
│     • Conditions defined (payment, fraud, stock)                    │
│     • OXORDERID = NULL  ← NO ORDER YET!                             │
│     │                                                               │
│     ▼                                                               │
│  CONTRACT → PENDING                                                 │
│     • Conditions being resolved in parallel                         │
│     │                                                               │
│     ├─► PaymentAuthorizationHandler → fulfills 'payment_authorized' │
│     ├─► FraudCheckHandler → fulfills 'fraud_check'                  │
│     └─► StockReservationHandler → fulfills 'stock_reserved'         │
│     │                                                               │
│     ▼ (All conditions fulfilled)                                    │
│  CONTRACT → READY_TO_COMMIT                                         │
│     │                                                               │
│     ▼                                                               │
│  **NOW CREATE oxorder**  ← Order created HERE, not before!          │
│     • Order number assigned (no gaps!)                              │
│     • Order state: NOT_FINISHED                                     │
│     │                                                               │
│     ▼                                                               │
│  CONTRACT → COMMITTED                                               │
│     • Contract.OXORDERID = order.OXID                               │
│     │                                                               │
│     ▼ (Payment captured via webhook or return)                      │
│  CONTRACT → FULFILLED                                               │
│     • Order state: OK                                               │
│     • oxpaid = NOW()                                                │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘
```

---

## Bartek's Approach vs Contract-First

| Aspect | Contract-First (Target) | Bartek's Current |
|--------|------------------------|------------------|
| **Order created** | After payment authorized | Before payment |
| **Order number gaps** | None (only successful) | Possible (pending cancelled) |
| **Rollback** | Cancel contract only | Cancel/storno order |
| **Checkout Session** | Contract holds order intent | Order created with `pending` status |
| **State tracking** | Contract conditions | Order metadata |

---

## The Reconciliation: How to Merge

Bartek's `createCheckoutSession()` creates order BEFORE payment because Stripe Checkout Session needs order metadata. We need to adapt this to Contract-First:

### Option A: Contract with Order-Intent (Hybrid)

```
1. Create CONTRACT with basket snapshot
2. Contract stores "order intent" (what order WILL be)
3. Create Stripe Checkout Session with contract metadata
4. On return: Contract conditions fulfilled → Create oxorder
5. Link order to contract
```

### Option B: Deferred Order Number (Pure Contract-First)

```
1. Create CONTRACT
2. Create Stripe Checkout Session with contract_id in metadata
3. On return: Contract conditions fulfilled → Create oxorder
4. Order number assigned at creation time
5. Stripe metadata updated via API (or accepted as "contract_id" reference)
```

### Option C: Pre-Reserve Order Number (Compromise)

```
1. Create CONTRACT
2. Reserve order number in contract (counter increment, but no oxorder row)
3. Create Stripe Checkout Session with reserved order number
4. On return: Create oxorder with pre-reserved number
5. If failed: Number marked as "skipped" (gap, but intentional)
```

---

## Recommended: Option A - Contract with Order-Intent

This preserves Bartek's functionality while adding contract layer:

```php
// Contract stores order intent, not actual order
class PaymentContract
{
    private ?string $orderId = null;        // Actual oxorder.OXID (set when created)
    private ?string $reservedOrderNumber = null; // Optional pre-reserved number
    private array $basketSnapshot = [];     // Frozen basket data
    private array $conditions = [];         // payment_authorized, fraud_check, etc.

    // For Stripe Checkout Session - we pass contract_id, not order_id
    public function getMetadataForProvider(): array
    {
        return [
            'contract_id' => $this->id,
            'shop_id' => $this->shopId,
            // Order number added AFTER order creation
        ];
    }
}
```

---

## Event Flow with Contract-First

```
┌─────────────────────────────────────────────────────────────────────┐
│                    STRIPE CHECKOUT SESSION FLOW                     │
├─────────────────────────────────────────────────────────────────────┤
│                                                                     │
│  StripeOrderController.createCheckoutSession()                      │
│     │                                                               │
│     └─► dispatch(StripeCheckoutSessionRequestEvent)                 │
│             │                                                       │
│             ▼                                                       │
│         ContractCreationHandler                                     │
│             • Creates CONTRACT (state: DRAFT)                       │
│             • Captures basket snapshot                              │
│             • Sets conditions                                       │
│             • Contract.OXORDERID = NULL                             │
│             │                                                       │
│             ▼                                                       │
│         StripeCheckoutSessionHandler                                │
│             • Gets contract_id from context                         │
│             • Creates Stripe Checkout Session                       │
│             • Metadata: { contract_id: "xxx" }  ← NOT order_id!     │
│             • Contract → PENDING                                    │
│             │                                                       │
│             ▼                                                       │
│         Return { session_id, contract_id }                          │
│                                                                     │
├─────────────────────────────────────────────────────────────────────┤
│                                                                     │
│  Customer completes payment on Stripe...                            │
│                                                                     │
├─────────────────────────────────────────────────────────────────────┤
│                                                                     │
│  StripeOrderController.checkoutSuccess()                            │
│     │                                                               │
│     └─► dispatch(StripeCheckoutReturnEvent)                         │
│             │                                                       │
│             ▼                                                       │
│         StripeCheckoutReturnHandler                                 │
│             • Retrieves Checkout Session                            │
│             • Verifies payment_status = 'paid'                      │
│             • Gets contract_id from metadata                        │
│             • Loads contract                                        │
│             │                                                       │
│             ▼                                                       │
│         PaymentAuthorizationConditionHandler                        │
│             • Fulfills 'payment_authorized' condition               │
│             • Stores PaymentIntent ID in contract                   │
│             │                                                       │
│             ▼ (If all conditions fulfilled)                         │
│         ContractReadyToCommitHandler                                │
│             • Contract → READY_TO_COMMIT                            │
│             • dispatch(ContractReadyToCommitEvent)                  │
│             │                                                       │
│             ▼                                                       │
│         **OrderCreationHandler**                                    │
│             • **NOW creates oxorder**                               │
│             • Uses basket snapshot from contract                    │
│             • Order.OXTRANSSTATUS = 'NOT_FINISHED'                  │
│             • Contract.OXORDERID = order.OXID                       │
│             • Contract → COMMITTED                                  │
│             │                                                       │
│             ▼                                                       │
│         PaymentCaptureHandler (if auto-capture)                     │
│             • Order.OXTRANSSTATUS = 'OK'                            │
│             • Order.OXPAID = NOW()                                  │
│             • Contract → FULFILLED                                  │
│             │                                                       │
│             ▼                                                       │
│         Return { redirect: 'thankyou', order_id }                   │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘
```

---

## Handler Decomposition (Contract-First)

### From Bartek's Controller to Handlers:

| Bartek's Method | Target Handler | When Triggered |
|-----------------|----------------|----------------|
| `createCheckoutSession()` - order creation | **REMOVED** - no order here | - |
| `createCheckoutSession()` - session creation | `StripeCheckoutSessionHandler` | `StripeCheckoutSessionRequestEvent` |
| `checkoutSuccess()` - verify payment | `StripeCheckoutReturnHandler` | `StripeCheckoutReturnEvent` |
| `checkoutSuccess()` - create order | `OrderCreationHandler` | `ContractReadyToCommitEvent` |
| `handleSuccessfulPayment()` | Split into condition handlers | Various condition events |
| `handle3DSecure()` | `Stripe3DSHandler` | `Stripe3DSRequiredEvent` |

---

## New Handler: ContractCreationHandler

**This is the KEY handler** - creates contract BEFORE any Stripe calls:

```php
class ContractCreationHandler extends AbstractHandler
{
    public function handle(object $event): void
    {
        // Triggered by PaymentInitiatedEvent or StripeCheckoutSessionRequestEvent
        if (!$event instanceof PaymentInitiatedEventInterface) {
            return;
        }

        $context = $event->getContext();
        $basket = $context->get('basket');
        $user = $context->get('user');

        // Create contract with basket snapshot
        $contract = $this->contractFactory->createFromBasket($basket, $user);

        // Add standard conditions
        $contract->addCondition('payment_authorized', 'pending');
        $contract->addCondition('fraud_check', 'pending');
        $contract->addCondition('stock_reserved', 'pending');

        // Contract starts as DRAFT, transitions to PENDING
        $contract->transitionToPending();

        // Save contract
        $this->contractRepository->save($contract);

        // Put contract in context for other handlers
        $context->setContract($contract);
        $context->set('contractId', $contract->getId());

        // Dispatch ContractCreatedEvent
        $this->eventDispatcher->dispatch(new ContractCreatedEvent($context, $contract));
    }
}
```

---

## New Handler: OrderCreationHandler (Revised)

**Creates order ONLY when contract is ready to commit:**

```php
class OrderCreationHandler extends AbstractHandler
{
    public function handle(object $event): void
    {
        // ONLY triggered when ALL conditions are fulfilled
        if (!$event instanceof ContractReadyToCommitEvent) {
            return;
        }

        $context = $event->getContext();
        $contract = $context->getContract();

        // Verify contract is ready
        if (!$contract->isReadyToCommit()) {
            throw new \RuntimeException('Contract not ready to commit');
        }

        // Create order from contract's basket snapshot
        $order = $this->orderFactory->createFromContract($contract);

        // Save order
        $this->orderRepository->save($order);

        // Link contract to order
        $contract->commitToOrder($order->getId());
        $this->contractRepository->save($contract);

        // Update context
        $context->set('orderId', $order->getId());
        $context->set('orderNumber', $order->getOrderNumber());

        // Dispatch OrderCreatedEvent
        $this->eventDispatcher->dispatch(new OrderCreatedFromContractEvent(
            $context,
            $order->getId(),
            $contract->getId()
        ));
    }
}
```

---

## Condition Fulfillment Handlers

### PaymentAuthorizationConditionHandler

```php
class PaymentAuthorizationConditionHandler extends AbstractHandler
{
    public function handle(object $event): void
    {
        // Triggered when payment is confirmed (webhook, return, etc.)
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

            // Dispatch event to trigger order creation
            $this->eventDispatcher->dispatch(new ContractReadyToCommitEvent($context, $contract));
        }
    }
}
```

---

## Stripe Checkout Session Handler (Revised)

**No longer creates order - only creates Stripe session with contract reference:**

```php
class StripeCheckoutSessionHandler extends AbstractHandler
{
    public function handle(object $event): void
    {
        if (!$event instanceof StripeCheckoutSessionRequestEvent) {
            return;
        }

        $context = $event->getContext();
        $contract = $context->getContract(); // Already created by ContractCreationHandler

        // Build line items from contract's basket snapshot
        $lineItems = $this->buildLineItems($contract->getBasketSnapshot());

        // Create Checkout Session with CONTRACT reference, not order
        $stripeClient = $this->adapterFactory->getStripeClient();
        $checkoutSession = $stripeClient->checkout->sessions->create([
            'mode' => 'payment',
            'line_items' => $lineItems,
            'success_url' => $this->buildSuccessUrl($contract->getId()),
            'cancel_url' => $this->buildCancelUrl($contract->getId()),
            'metadata' => [
                'contract_id' => $contract->getId(),  // ← Contract, not order!
                'shop_id' => $context->get('shopId'),
            ],
            'payment_intent_data' => [
                'metadata' => [
                    'contract_id' => $contract->getId(),
                ],
            ],
        ]);

        // Store session ID in contract
        $contract->setProviderSessionId($checkoutSession->id);
        $this->contractRepository->save($contract);

        // Update context
        $context->set('checkoutSessionId', $checkoutSession->id);
    }
}
```

---

## Stripe Checkout Return Handler (Revised)

**Verifies payment, fulfills condition, triggers order creation:**

```php
class StripeCheckoutReturnHandler extends AbstractHandler
{
    public function handle(object $event): void
    {
        if (!$event instanceof StripeCheckoutReturnEvent) {
            return;
        }

        $context = $event->getContext();
        $sessionId = $context->get('checkoutSessionId');

        // Retrieve Checkout Session
        $stripeClient = $this->adapterFactory->getStripeClient();
        $checkoutSession = $stripeClient->checkout->sessions->retrieve($sessionId, [
            'expand' => ['payment_intent']
        ]);

        // Verify payment
        if ($checkoutSession->payment_status !== 'paid') {
            $context->setError('Payment not completed');
            $context->set('redirectTarget', 'payment');
            return;
        }

        // Get contract from metadata
        $contractId = $checkoutSession->metadata->contract_id;
        $contract = $this->contractRepository->find($contractId);

        if (!$contract) {
            $context->setError('Contract not found');
            return;
        }

        $context->setContract($contract);
        $context->set('contractId', $contractId);

        // Get PaymentIntent details
        $paymentIntentId = is_string($checkoutSession->payment_intent)
            ? $checkoutSession->payment_intent
            : $checkoutSession->payment_intent->id;

        $context->set('paymentIntentId', $paymentIntentId);

        // Dispatch PaymentConfirmedEvent to fulfill condition
        // This will trigger PaymentAuthorizationConditionHandler
        // Which will check if all conditions met → trigger OrderCreationHandler
        $this->eventDispatcher->dispatch(new PaymentConfirmedEvent($context));

        // After all handlers run, get result from context
        if ($orderId = $context->get('orderId')) {
            $context->set('redirectTarget', 'thankyou');
        }
    }
}
```

---

## Event Chain Visualization

```
createCheckoutSession() called
    │
    ▼
StripeCheckoutSessionRequestEvent
    │
    ├─► ContractCreationHandler
    │       • Creates CONTRACT (DRAFT → PENDING)
    │       • Captures basket snapshot
    │       • Dispatches ContractCreatedEvent
    │
    └─► StripeCheckoutSessionHandler
            • Creates Stripe Checkout Session
            • Metadata: { contract_id }
            • Returns session_id

═══════════════════════════════════════════════

Customer pays on Stripe...

═══════════════════════════════════════════════

checkoutSuccess() called
    │
    ▼
StripeCheckoutReturnEvent
    │
    └─► StripeCheckoutReturnHandler
            • Verifies payment_status = 'paid'
            • Loads contract by contract_id
            • Dispatches PaymentConfirmedEvent
                    │
                    ▼
            PaymentAuthorizationConditionHandler
                    • Fulfills 'payment_authorized'
                    • Checks: all conditions met?
                    • YES → Contract → READY_TO_COMMIT
                    • Dispatches ContractReadyToCommitEvent
                            │
                            ▼
                    OrderCreationHandler
                            • **Creates oxorder NOW**
                            • Links to contract
                            • Contract → COMMITTED
                            • Dispatches OrderCreatedFromContractEvent
                                    │
                                    ▼
                            (Notification handlers, etc.)
```

---

## TDD Implementation Order

### Phase 1: Contract Infrastructure

```
1. ContractCreationHandler test
2. Contract model (with conditions)
3. ContractRepository
4. ContractFactory
```

### Phase 2: Condition Handlers

```
1. PaymentAuthorizationConditionHandler test
2. FraudCheckConditionHandler test (can be auto-pass for now)
3. StockReservationConditionHandler test (can be auto-pass for now)
```

### Phase 3: Order Creation

```
1. OrderCreationHandler test (triggered by ContractReadyToCommitEvent)
2. OrderFactory.createFromContract()
```

### Phase 4: Stripe Integration

```
1. StripeCheckoutSessionHandler test (no order creation)
2. StripeCheckoutReturnHandler test (triggers condition fulfillment)
3. StripePaymentStatusHandler test (for Payment Element flow)
```

### Phase 5: Controller (Thin)

```
1. StripeOrderController.createCheckoutSession() - just dispatches event
2. StripeOrderController.checkoutSuccess() - just dispatches event
3. StripeOrderController.execute() - just dispatches event
```

---

## Key Principle Reminder

> **Contract BEFORE Order**
>
> The contract captures the INTENT to purchase.
> The order captures the COMMITMENT after payment.
>
> This prevents:
> - Orphan orders from failed payments
> - Order number gaps
> - Complex rollback logic

---

## Files to Create/Modify

### New Events
- `StripeCheckoutSessionRequestEvent.php`
- `StripeCheckoutReturnEvent.php`
- `PaymentConfirmedEvent.php`
- `ContractReadyToCommitEvent.php`
- `OrderCreatedFromContractEvent.php`

### New/Modified Handlers
- `ContractCreationHandler.php` (exists, may need updates)
- `PaymentAuthorizationConditionHandler.php` (NEW)
- `OrderCreationHandler.php` (MODIFY - trigger on ContractReadyToCommit)
- `StripeCheckoutSessionHandler.php` (MODIFY - no order creation)
- `StripeCheckoutReturnHandler.php` (MODIFY - trigger condition fulfillment)

### Controller
- `StripeOrderController.php` (THIN - only dispatch events)

---

**Document Status:** REVISED v2 - Contract-First Architecture
**Key Change:** Order created AFTER payment confirmed, not before
**Priority:** HIGH
