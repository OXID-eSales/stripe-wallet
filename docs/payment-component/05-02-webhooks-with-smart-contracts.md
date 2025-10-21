# Webhook & Capture/Refund Operations with Smart-Contract Integration

**Version:** 3.0.0
**Date:** 2025-10-20
**Status:** Architectural Integration Guide
**Related Documents:**
- [01-02-architecture-smart-contracts.md](01-02-architecture-smart-contracts.md) - Smart-Contract pattern
- [05-webhooks.md](05-webhooks.md) - Original webhook system
- [07-capture-refund-operations.md](07-capture-refund-operations.md) - Original capture/refund operations

**Visual Diagrams:**
- [puml/05-02-webhook-system-with-contracts.puml](puml/05-02-webhook-system-with-contracts.puml) - Webhook flow with contracts
- [puml/07-02-capture-refund-with-contracts.puml](puml/07-02-capture-refund-with-contracts.puml) - Capture/refund with contracts

---

## Table of Contents

1. [Overview](#overview)
2. [Webhook Integration](#webhook-integration)
3. [Capture Operations](#capture-operations)
4. [Refund Operations](#refund-operations)
5. [Key Benefits](#key-benefits)
6. [Implementation Examples](#implementation-examples)
7. [Migration Guide](#migration-guide)

---

## Overview

This document describes how **webhooks**, **capture operations**, and **refund operations** integrate with the **smart-contract pattern** introduced in the payment component.

### Key Integration Points

The smart-contract pattern enhances webhooks and capture/refund operations by:

1. **Contract as Central Entity**: Webhooks and operations reference contracts first, not orders
2. **State Validation**: Contract state enforces operation eligibility
3. **Transaction Lineage**: Parent-child transaction relationships tracked via contract
4. **Audit Trail**: Complete lifecycle from intent → fulfillment recorded
5. **Idempotency**: Contract state prevents duplicate processing

---

## Webhook Integration

### Problem with Traditional Approach

**OLD Pattern:**
```
Provider Webhook → Find oxorder by provider ID → Update order status → Done
```

**Issues:**
- Order must exist before webhook (tight coupling)
- No validation of payment lifecycle state
- Hard to track authorization → capture flow
- Duplicate webhooks can cause state corruption

### Smart-Contract Solution

**NEW Pattern:**
```
Provider Webhook → Find Contract by provider ID → Validate contract state → Update contract → Update order → Done
```

**Flow:**

1. **Webhook Received**
   ```
   POST /webhook/stripe
   Headers: Stripe-Signature: ...
   Body: {
     "type": "payment_intent.succeeded",
     "data": {
       "object": {
         "id": "pi_stripe_123",
         "status": "succeeded"
       }
     }
   }
   ```

2. **Find Contract** (Not Order!)
   ```sql
   SELECT * FROM osc_payment_contract
   WHERE OXPROVIDERORDERID = 'pi_stripe_123'
   ```

   Contract contains:
   - `OXORDERID` (links to order)
   - `OXSTATE` (current contract state)
   - `OXPROVIDER` (stripe, paypal, etc.)

3. **Validate Contract State**
   ```php
   if ($contract->getState() !== PaymentContract::STATE_COMMITTED) {
       // Contract not ready for fulfillment
       $this->logger->warning("Webhook received for non-committed contract");
       return new Response('OK', 200); // Acknowledge but don't process
   }
   ```

4. **Fulfill Contract**
   ```php
   $contract->fulfill(); // COMMITTED → FULFILLED
   $this->contractRepository->save($contract);
   ```

5. **Update Order**
   ```php
   $order = $this->orderRepository->find($contract->getOrderId());
   $order->markOrderPaid();
   $order->setState(Order::ORDER_STATE_OK);
   ```

6. **Emit Events**
   ```php
   $this->dispatcher->dispatch(new ContractFulfilledEvent($contract, $order));
   ```

### Benefits

✅ **Single Lookup**: Contract found by provider ID, contains order ID
✅ **State Validation**: Only process webhooks for COMMITTED contracts
✅ **Idempotency**: Contract state prevents duplicate fulfillment
✅ **Clean Audit**: Complete flow from contract creation → fulfillment
✅ **Provider Agnostic**: Same pattern for Stripe, PayPal, Adyen, etc.

### Idempotency Handling

Webhooks are often delivered multiple times. Smart-contract pattern handles this elegantly:

```php
public function handleWebhook(PaymentIntent $intent): void
{
    $contract = $this->contractRepo->findByProviderOrderId($intent->id);

    // Idempotency check via state
    if ($contract->getState() === PaymentContract::STATE_FULFILLED) {
        // Already processed - safe to return
        $this->logger->info("Webhook already processed", ['contract_id' => $contract->getId()]);
        return;
    }

    // Process only if COMMITTED
    if ($contract->getState() === PaymentContract::STATE_COMMITTED) {
        $contract->fulfill();
        $this->contractRepo->save($contract);
        // ... rest of processing
    }
}
```

**Result**: Webhook can be replayed safely - state machine prevents duplicates.

---

## Capture Operations

### Problem with Traditional Approach

**OLD Pattern:**
```
Admin clicks "Capture" → Find order → Call provider API → Update order → Done
```

**Issues:**
- No validation of authorization state
- No tracking of authorization → capture relationship
- Hard to implement partial captures
- No audit trail of capture reason

### Smart-Contract Solution

**NEW Pattern:**
```
Admin clicks "Capture" → Find contract + order → Validate contract state → Check authorization → Call provider API → Record transaction → Fulfill contract → Update order
```

### Capture Flow

#### 1. Manual Capture (Admin)

Admin initiates capture from order details page:

```
POST /admin/order/capture
{
  "orderId": "order789",
  "amount": 99.99,
  "reason": "Goods shipped"
}
```

#### 2. Load Contract & Validate

```php
$contract = $this->contractRepo->findByOrderId($orderId);

// Validate contract state
if ($contract->getState() !== PaymentContract::STATE_COMMITTED) {
    throw new InvalidStateException("Cannot capture - contract not committed");
}
```

**State Validation Table:**

| Contract State | Capture Allowed? | Reason |
|---------------|------------------|--------|
| `DRAFT` | ❌ No | No payment initiated yet |
| `PENDING` | ❌ No | Conditions not fulfilled |
| `COMMITTED` | ✅ **YES** | Authorization complete, ready to capture |
| `FULFILLED` | ❌ No | Already captured |
| `CANCELLED` | ❌ No | Contract cancelled |

#### 3. Check Authorization Details

```sql
SELECT *
FROM osc_payment_transaction t
LEFT JOIN osc_payment_authorization_details a
  ON t.OXID = a.OXTRANSACTIONID
WHERE t.OXORDERID = 'order789'
  AND t.OXTYPE = 'authorization'
  AND t.OXSTATUS = 'completed'
```

Validation:
- ✅ Authorization exists
- ✅ Not expired (`OXISEXPIRED = false`)
- ✅ Sufficient remaining amount (`OXREMAININGAMOUNT >= requestedAmount`)

#### 4. Call Provider API

```php
$response = $this->paymentService->capturePayment(
    providerOrderId: $contract->getProviderOrderId(),
    amount: $captureAmount
);
```

Provider-specific capture:
- **Stripe**: `PaymentIntent::capture()`
- **PayPal**: `Order::capture()`
- **Adyen**: `Payment::capture()`
- **Unzer**: `Charge::charge()`

#### 5. Record Capture Transaction

```sql
INSERT INTO osc_payment_transaction (
    OXORDERID,
    OXPROVIDERORDERID,
    OXTRANSACTIONID,
    OXTYPE,
    OXSTATUS,
    OXAMOUNT,
    OXPARENTTRANSACTIONID  -- Links to authorization!
) VALUES (
    'order789',
    'pi_stripe_123',
    'cap_789',
    'capture',
    'completed',
    99.99,
    'auth_456'  -- Parent transaction
);
```

**Transaction Chain:**
```
auth_456 (authorization)
  └─ cap_789 (capture) ← OXPARENTTRANSACTIONID points to auth_456
```

#### 6. Update Authorization Details

```sql
UPDATE osc_payment_authorization_details
SET OXCAPTUREDAMOUNT = OXCAPTUREDAMOUNT + 99.99,
    OXREMAININGAMOUNT = OXREMAININGAMOUNT - 99.99
WHERE OXTRANSACTIONID = 'auth_456';
```

#### 7. Fulfill Contract

```php
$contract->fulfill(); // COMMITTED → FULFILLED
$this->contractRepo->save($contract);
```

#### 8. Update Order

```php
$order->markOrderPaid();
$order->setState(Order::ORDER_STATE_OK);
$this->orderRepo->save($order);
```

#### 9. Emit Events

```php
$this->dispatcher->dispatch(new ContractFulfilledEvent($contract, $order));
```

Subscribers react:
- `EmailSubscriber` → Send order confirmation
- `InventorySubscriber` → Update stock
- `AnalyticsSubscriber` → Track conversion
- `AuditLogSubscriber` → Log capture

### Partial Capture Support

Smart-contract pattern supports partial captures natively:

```php
// First partial capture
$this->capturePayment($orderId, amount: 50.00);
// Authorization: authorized=99.99, captured=50.00, remaining=49.99
// Contract: Still COMMITTED (not yet fully fulfilled)

// Second partial capture
$this->capturePayment($orderId, amount: 49.99);
// Authorization: authorized=99.99, captured=99.99, remaining=0.00
// Contract: Now FULFILLED (fully captured)
```

Contract fulfillment logic:

```php
public function shouldFulfillContract(array $authorizationDetails): bool
{
    // Fulfill when fully captured OR when merchant marks as complete
    return $authorizationDetails['OXREMAININGAMOUNT'] <= 0.01 // Float precision
        || $this->isManuallyMarkedComplete();
}
```

---

## Refund Operations

### Problem with Traditional Approach

**OLD Pattern:**
```
Admin clicks "Refund" → Find order → Call provider API → Update order → Done
```

**Issues:**
- No tracking of refund → capture relationship
- Hard to calculate refundable amount
- No audit trail of refund reason
- Refund limits not enforced

### Smart-Contract Solution

**NEW Pattern:**
```
Admin clicks "Refund" → Find contract → Validate fulfilled → Calculate refundable → Call provider API → Record refund transaction → Update contract metadata → Update order
```

### Refund Flow

#### 1. Initiate Refund

```
POST /admin/order/refund
{
  "orderId": "order789",
  "amount": 50.00,
  "reason": "Customer requested",
  "type": "partial"
}
```

#### 2. Load Contract & Validate

```php
$contract = $this->contractRepo->findByOrderId($orderId);

// Validate contract is fulfilled
if ($contract->getState() !== PaymentContract::STATE_FULFILLED) {
    throw new InvalidStateException("Cannot refund - contract not fulfilled");
}
```

**State Validation:**

| Contract State | Refund Allowed? | Reason |
|---------------|-----------------|--------|
| `FULFILLED` | ✅ **YES** | Payment captured, can refund |
| `COMMITTED` | ❌ No | Not yet captured |
| `CANCELLED` | ❌ No | Never captured |

#### 3. Calculate Refundable Amount

```sql
SELECT
  SUM(CASE WHEN OXTYPE = 'capture' THEN OXAMOUNT ELSE 0 END) as total_captured,
  SUM(CASE WHEN OXTYPE = 'refund' THEN OXAMOUNT ELSE 0 END) as total_refunded
FROM osc_payment_transaction
WHERE OXORDERID = 'order789';
```

Validation:
```php
$refundable = $totalCaptured - $totalRefunded;

if ($requestedAmount > $refundable) {
    throw new RefundException("Requested amount exceeds refundable: {$refundable}");
}
```

#### 4. Call Provider API

```php
$response = $this->paymentService->refundPayment(
    providerOrderId: $contract->getProviderOrderId(),
    amount: $refundAmount,
    reason: $refundReason
);
```

#### 5. Record Refund Transaction

```sql
-- Main transaction
INSERT INTO osc_payment_transaction (
    OXORDERID,
    OXTYPE,
    OXSTATUS,
    OXAMOUNT,
    OXPARENTTRANSACTIONID  -- Links to capture!
) VALUES (
    'order789',
    'refund',
    'completed',
    50.00,
    'cap_789'  -- Parent capture
);

-- Refund details
INSERT INTO osc_payment_refund_details (
    OXTRANSACTIONID,
    OXORIGINALAMOUNT,
    OXREFUNDAMOUNT,
    OXTOTALREFUNDED,
    OXREMAININGREFUNDABLE,
    OXREASON
) VALUES (
    're_abc',
    99.99,  -- Original capture
    50.00,  -- This refund
    50.00,  -- Total refunded so far
    49.99,  -- Still refundable
    'Customer requested'
);
```

**Transaction Chain:**
```
auth_456 (authorization)
  └─ cap_789 (capture)
       ├─ re_abc (refund) ← First refund
       └─ re_def (refund) ← Second refund
```

#### 6. Update Contract Metadata

```php
// Track refunds in contract for audit
$contract->addRefund([
    'refund_id' => 're_abc',
    'amount' => 50.00,
    'reason' => 'Customer requested',
    'date' => new \DateTime(),
]);

$this->contractRepo->save($contract);
```

**Contract remains FULFILLED** - refunds don't change fulfillment state.

#### 7. Update Order Status

```php
if ($totalRefunded >= $totalCaptured) {
    $order->setState(Order::ORDER_STATE_FULLY_REFUNDED);
} else {
    $order->setState(Order::ORDER_STATE_PARTIALLY_REFUNDED);
}
```

#### 8. Emit Events

```php
$this->dispatcher->dispatch(new RefundCompletedEvent($contract, $order, $refundResult));
```

Subscribers:
- `EmailSubscriber` → Send refund confirmation
- `AccountingSubscriber` → Update ledger
- `AnalyticsSubscriber` → Track refund metrics

---

## Key Benefits

### 1. State Validation

Contract state machine enforces operation eligibility:

```
Webhook Processing:
  - Only process if contract.state == COMMITTED
  - Transition to FULFILLED after capture

Capture Operations:
  - Only capture if contract.state == COMMITTED
  - Fulfill contract after successful capture

Refund Operations:
  - Only refund if contract.state == FULFILLED
  - Contract remains FULFILLED after refund
```

### 2. Transaction Relationships

Parent-child transaction tracking via `OXPARENTTRANSACTIONID`:

```
Authorization (auth_456)
  ├─ Capture 1 (cap_789) - $60
  │    └─ Refund (re_abc) - $20
  └─ Capture 2 (cap_790) - $39.99
       └─ Refund (re_def) - $10
```

**Query transaction tree:**
```sql
-- Get all transactions for an order
WITH RECURSIVE transaction_tree AS (
  -- Root: Authorization
  SELECT * FROM osc_payment_transaction
  WHERE OXORDERID = 'order789' AND OXTYPE = 'authorization'

  UNION ALL

  -- Children: Captures and refunds
  SELECT t.* FROM osc_payment_transaction t
  INNER JOIN transaction_tree tt ON t.OXPARENTTRANSACTIONID = tt.OXID
)
SELECT * FROM transaction_tree ORDER BY OXCREATED;
```

### 3. Complete Audit Trail

Contract + transactions provide complete history:

```sql
-- Contract lifecycle
SELECT
  OXSTATE,
  OXCREATED as contract_created,
  OXCOMMITTEDAT as order_created,
  OXFULFILLEDAT as payment_captured
FROM osc_payment_contract
WHERE OXID = 'contract123';

-- All transactions
SELECT
  OXTYPE,
  OXSTATUS,
  OXAMOUNT,
  OXCREATED,
  OXPARENTTRANSACTIONID
FROM osc_payment_transaction
WHERE OXORDERID = 'order789'
ORDER BY OXCREATED;
```

### 4. Idempotency

Contract state prevents duplicate operations:

```php
// Webhook idempotency
if ($contract->getState() === PaymentContract::STATE_FULFILLED) {
    return; // Already processed
}

// Capture idempotency
if ($contract->getState() !== PaymentContract::STATE_COMMITTED) {
    throw new InvalidStateException(); // Prevent duplicate capture
}
```

### 5. Provider Agnostic

Same pattern works for all providers:

| Provider | Authorization Object | Capture Method | Refund Method |
|----------|---------------------|----------------|---------------|
| Stripe | `PaymentIntent` | `capture()` | `refund()` |
| PayPal | `Order` | `capture()` | `refund()` |
| Adyen | `Payment` | `capture()` | `refund()` |
| Unzer | `Authorization` | `charge()` | `cancel()` |
| Amazon Pay | `Charge` | `capture()` | `refund()` |

All mapped through contract's `OXPROVIDERORDERID`.

---

## Implementation Examples

### Webhook Handler with Contract

```php
<?php

namespace Osc\Payment\EventHandler;

use Osc\Payment\Event\PaymentCapturedEvent;
use Osc\Payment\Repository\ContractRepository;
use Osc\Payment\Repository\OrderRepository;
use Osc\Payment\Model\PaymentContract;

class WebhookPaymentCaptureHandler
{
    public function __construct(
        private ContractRepository $contractRepo,
        private OrderRepository $orderRepo,
        private EventDispatcher $dispatcher
    ) {}

    public function handle(PaymentCapturedEvent $event): void
    {
        $providerOrderId = $event->getProviderOrderId();

        // 1. Find contract by provider order ID
        $contract = $this->contractRepo->findByProviderOrderId($providerOrderId);

        if (!$contract) {
            throw new ContractNotFoundException("Contract not found: {$providerOrderId}");
        }

        // 2. Validate contract state
        if ($contract->getState() === PaymentContract::STATE_FULFILLED) {
            // Idempotent - already processed
            return;
        }

        if ($contract->getState() !== PaymentContract::STATE_COMMITTED) {
            throw new InvalidStateException(
                "Cannot fulfill contract in state: {$contract->getState()}"
            );
        }

        // 3. Fulfill contract
        $contract->fulfill();
        $this->contractRepo->save($contract);

        // 4. Update order
        $order = $this->orderRepo->find($contract->getOrderId());
        $order->markOrderPaid();
        $this->orderRepo->save($order);

        // 5. Emit fulfillment event
        $this->dispatcher->dispatch(new ContractFulfilledEvent($contract, $order));
    }
}
```

### Capture Handler with Contract

```php
<?php

namespace Osc\Payment\EventHandler;

use Osc\Payment\Event\CaptureRequestedEvent;

class CaptureRequestHandler
{
    public function handle(CaptureRequestedEvent $event): void
    {
        $orderId = $event->getOrderId();
        $amount = $event->getAmount();

        // 1. Load contract
        $contract = $this->contractRepo->findByOrderId($orderId);

        // 2. Validate state
        if ($contract->getState() !== PaymentContract::STATE_COMMITTED) {
            throw new InvalidStateException("Contract not committed");
        }

        // 3. Check authorization
        $authDetails = $this->getAuthorizationDetails($orderId);

        if ($amount > $authDetails['remaining_amount']) {
            throw new CaptureException("Amount exceeds authorized");
        }

        if ($authDetails['is_expired']) {
            throw new CaptureException("Authorization expired");
        }

        // 4. Call provider
        $result = $this->paymentService->capturePayment(
            $contract->getProviderOrderId(),
            $amount
        );

        // 5. Record transaction
        $this->recordCaptureTransaction($orderId, $result, $authDetails['auth_id']);

        // 6. Fulfill contract (if fully captured)
        if ($this->isFullyCaptured($orderId)) {
            $contract->fulfill();
            $this->contractRepo->save($contract);

            $this->dispatcher->dispatch(
                new PaymentCapturedEvent($contract, $order)
            );
        }
    }
}
```

### Refund Handler with Contract

```php
<?php

namespace Osc\Payment\EventHandler;

use Osc\Payment\Event\RefundRequestedEvent;

class RefundRequestHandler
{
    public function handle(RefundRequestedEvent $event): void
    {
        $orderId = $event->getOrderId();
        $amount = $event->getAmount();

        // 1. Load contract
        $contract = $this->contractRepo->findByOrderId($orderId);

        // 2. Validate fulfilled
        if ($contract->getState() !== PaymentContract::STATE_FULFILLED) {
            throw new InvalidStateException("Contract not fulfilled");
        }

        // 3. Calculate refundable
        $refundable = $this->calculateRefundable($orderId);

        if ($amount > $refundable) {
            throw new RefundException("Exceeds refundable amount");
        }

        // 4. Call provider
        $result = $this->paymentService->refundPayment(
            $contract->getProviderOrderId(),
            $amount,
            $event->getReason()
        );

        // 5. Record refund
        $this->recordRefundTransaction($orderId, $result);

        // 6. Update contract metadata
        $contract->addRefund([
            'refund_id' => $result->getRefundId(),
            'amount' => $amount,
            'reason' => $event->getReason(),
        ]);
        $this->contractRepo->save($contract);

        // 7. Emit event
        $this->dispatcher->dispatch(
            new RefundCompletedEvent($contract, $order, $result)
        );
    }
}
```

---

## Migration Guide

### Phase 1: Add Contract Awareness to Webhooks

**Current Code:**
```php
// Find order by provider ID
$order = $this->orderRepo->findByProviderOrderId($providerOrderId);
$order->markOrderPaid();
```

**Migrated Code:**
```php
// Find contract by provider ID (contract knows order ID)
$contract = $this->contractRepo->findByProviderOrderId($providerOrderId);

// Validate state
if ($contract->getState() === PaymentContract::STATE_FULFILLED) {
    return; // Idempotent
}

// Fulfill contract
$contract->fulfill();
$this->contractRepo->save($contract);

// Update order
$order = $this->orderRepo->find($contract->getOrderId());
$order->markOrderPaid();
```

### Phase 2: Add State Validation to Captures

**Current Code:**
```php
public function capturePayment(string $orderId, float $amount): void
{
    // Call provider API
    $result = $this->paymentService->capture($orderId, $amount);

    // Update order
    $this->updateOrder($orderId, $result);
}
```

**Migrated Code:**
```php
public function capturePayment(string $orderId, float $amount): void
{
    // Load contract
    $contract = $this->contractRepo->findByOrderId($orderId);

    // Validate state
    if ($contract->getState() !== PaymentContract::STATE_COMMITTED) {
        throw new InvalidStateException("Contract not committed");
    }

    // Call provider API
    $result = $this->paymentService->capture(
        $contract->getProviderOrderId(),
        $amount
    );

    // Fulfill contract
    $contract->fulfill();
    $this->contractRepo->save($contract);

    // Update order
    $this->updateOrder($orderId, $result);
}
```

### Phase 3: Add Transaction Lineage

**Current Code:**
```sql
INSERT INTO payment_transaction (order_id, type, amount)
VALUES ('order789', 'capture', 99.99);
```

**Migrated Code:**
```sql
-- Find parent authorization
SELECT OXID FROM osc_payment_transaction
WHERE OXORDERID = 'order789' AND OXTYPE = 'authorization';

-- Insert with parent link
INSERT INTO osc_payment_transaction (
    OXORDERID, OXTYPE, OXAMOUNT, OXPARENTTRANSACTIONID
) VALUES (
    'order789', 'capture', 99.99, 'auth_456'
);
```

### Phase 4: Enable Audit Trail

Add contract tracking to all operations:

```php
// Record operation in contract metadata
$contract->recordOperation([
    'operation' => 'capture',
    'amount' => $amount,
    'timestamp' => new \DateTime(),
    'user' => $currentUser->getId(),
]);
$this->contractRepo->save($contract);
```

---

## Conclusion

The **smart-contract integration** significantly enhances webhook and capture/refund operations by:

✅ **State Validation** - Enforce operation eligibility via contract state machine
✅ **Transaction Lineage** - Track authorization → capture → refund relationships
✅ **Audit Trail** - Complete lifecycle from intent to fulfillment
✅ **Idempotency** - Prevent duplicate webhook/capture processing via state
✅ **Provider Agnostic** - Same pattern for Stripe, PayPal, Adyen, Unzer, etc.

**Next Steps:**
1. Review webhook handler implementation
2. Update capture/refund handlers with contract validation
3. Test idempotency with webhook replay
4. Verify transaction lineage queries
5. Document provider-specific mappings

---

**Related Documents:**
- [01-02-architecture-smart-contracts.md](01-02-architecture-smart-contracts.md) - Smart-Contract pattern overview
- [02-database-and-models.md](02-database-and-models.md) - Database schema with contracts
- [puml/05-02-webhook-system-with-contracts.puml](puml/05-02-webhook-system-with-contracts.puml) - Webhook flow diagram
- [puml/07-02-capture-refund-with-contracts.puml](puml/07-02-capture-refund-with-contracts.puml) - Capture/refund diagram

**Status:** ✅ Ready for Implementation
