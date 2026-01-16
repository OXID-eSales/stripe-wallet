# Investigation Report: Order State Machine Ambiguity

**Date:** December 3, 2025
**Status:** INVESTIGATION COMPLETE
**Issue:** Contract state machine bypassed by webhook processing
**Severity:** Architectural (not a bug, but design inconsistency)

---

## Executive Summary

The payment module has an **Event-Driven Architecture** with a contract state machine documented in `05-02-webhooks-with-smart-contracts.md`. However, investigation reveals:

1. **Frontend Flow** (checkout) - **USES** contract state machine correctly
2. **Webhook Flow** (background) - **BYPASSES** contract entirely

This creates **state machine ambiguity** where:
- Contract reaches `COMMITTED` state via frontend
- Webhook never transitions contract to `FULFILLED`
- Contract is stuck in `COMMITTED` forever
- Order is updated via direct SQL, not through contract

---

## Investigation Findings

### 1. Frontend Flow Analysis

The frontend flow **correctly implements** the Event-Driven Architecture:

```
StripeOrderController.createCheckoutSession()
    → StripeContractCreationHandler (priority: 100)
        → Creates contract (DRAFT)
    → StripeCheckoutSessionHandler (priority: 0)
        → Stores provider order ID in contract

[Customer pays on Stripe]

StripeOrderController.checkoutSuccess()
    → StripeCheckoutReturnHandler
        → Loads contract by ID
        → Validates token
        → Dispatches PaymentAuthorizedEvent
    → PaymentAuthorizedEventHandler
        → DRAFT → PENDING
        → Fulfills 'payment_authorized' condition
        → PENDING → READY_TO_COMMIT (if all conditions met)
        → Dispatches ContractReadyToCommitEvent
    → StripeOrderCreationHandler
        → Creates OXID order
        → READY_TO_COMMIT → COMMITTED
        → Dispatches ContractCommittedEvent
```

**Contract State at end of frontend flow:** `COMMITTED`

### 2. Webhook Flow Analysis

The webhook flow **bypasses** the contract system:

```
WebhookController receives payment_intent.succeeded
    → WebhookProcessingService.processEvent()
        → findOrderByPaymentIntentId() [Direct SQL lookup!]
            → First: oe_payments_transaction.OXPROVIDERORDERID
            → Fallback: oxorder.OXTRANSID
        → updateOrderPaymentState() [Direct SQL to oe_payments_order_state]
        → updateOrderPaidTimestamp() [Direct SQL to oxorder.OXPAID]
        → updateOrderTransStatus() [Direct SQL to oxorder.OXTRANSSTATUS]
```

**What's MISSING in webhook flow:**
- ❌ No `ContractRepository.findByProviderOrderId()`
- ❌ No contract state validation (`isCommitted()`)
- ❌ No contract state transition (`fulfill()`)
- ❌ No `ContractFulfilledEvent` dispatched

**Contract State after webhook:** Still `COMMITTED` (never changes!)

### 3. Why Tests Are "False Green"

Unit tests verify **what the code does**, not **what it should do**:

| Test File | What It Verifies | What It Misses |
|-----------|-----------------|----------------|
| `PaymentIntentWebhookTest.php` | WebhookLog saved correctly | Contract lookup |
| `ChargeWebhookTest.php` | Event type logged | Contract state validation |
| `DisputeWebhookTest.php` | Payload stored | State transition |
| All webhook tests | Idempotency by event_id | Idempotency by contract state |

**Result:** 50+ tests pass, but they don't verify the documented architecture!

### 4. Database Tables Affected

| Table | Frontend Flow | Webhook Flow |
|-------|--------------|--------------|
| `oe_payments_contract` | Created & Updated | **NEVER TOUCHED** |
| `oe_payments_transaction` | Queried (fallback) | Queried (primary) |
| `oe_payments_order_state` | Not directly used | Updated directly |
| `oxorder` | Updated via Order model | Updated via direct SQL |

---

## Root Cause

`WebhookProcessingService` was implemented with a **legacy direct-DB approach** before the contract state machine was fully integrated. It was never refactored to use the contract-aware pattern.

**Code Evidence:**

```php
// WebhookProcessingService.php:391-426
private function findOrderByPaymentIntentId(string $paymentIntentId): ?Order
{
    $db = DatabaseProvider::getDb();

    // First try: Look in oe_payments_transaction table
    $orderId = $db->getOne(
        "SELECT OXORDERID FROM oe_payments_transaction WHERE OXPROVIDERORDERID = ? LIMIT 1",
        [$paymentIntentId]
    );

    // Fallback: Look directly in oxorder.OXTRANSID
    if (!$orderId) {
        $orderId = $db->getOne(
            "SELECT OXID FROM oxorder WHERE OXTRANSID = ? LIMIT 1",
            [$paymentIntentId]
        );
    }
    // ... loads Order model directly, never touches Contract
}
```

The method finds the **order** directly, completely skipping the **contract** layer.

---

## Impact Assessment

| Impact Area | Severity | Description |
|-------------|----------|-------------|
| Contract State Consistency | HIGH | Contract stuck in COMMITTED, never FULFILLED |
| Audit Trail | MEDIUM | ContractFulfilledEvent never fires |
| Event Subscribers | MEDIUM | Downstream handlers don't trigger |
| Reporting | LOW | Contract state doesn't reflect actual payment status |
| Functionality | LOW | Orders still work (paid status correct) |

---

## Recommended Solution

### Sprint 6: Contract-Aware Webhook Processing

Refactor `WebhookProcessingService` to:

1. **Find contract first** (by provider order ID)
2. **Validate contract state** (must be COMMITTED)
3. **Transition contract** to FULFILLED
4. **Update order through contract** (not direct SQL)
5. **Dispatch ContractFulfilledEvent**

See: `sprint-6-contract-aware-webhooks.md` for detailed TDD implementation plan.

---

## PlantUML Diagrams Created

| File | Description |
|------|-------------|
| `01-documented-architecture-contract-aware.puml` | How architecture docs describe it |
| `02-actual-implementation-direct-db.puml` | How WebhookProcessingService actually works |
| `03-false-green-tests-gap.puml` | Why tests pass but don't verify architecture |
| `04-workflow-ambiguity-two-paths.puml` | Side-by-side comparison |
| `05-tdd-fix-approach.puml` | TDD approach for fix |
| `06-corrected-unified-flow.puml` | Target state after fix |
| `07-actual-frontend-flow-contract-aware.puml` | Frontend flow (works correctly) |
| `08-frontend-vs-webhook-comparison.puml` | Final comparison diagram |

---

## Conclusion

The **Event-Driven Architecture is correctly implemented in the frontend flow** but **completely bypassed in the webhook flow**. This creates a state machine ambiguity where the contract's documented lifecycle is only partially executed.

The fix requires making `WebhookProcessingService` contract-aware, which should be done with a TDD approach as outlined in Sprint 6.

---

**Investigation by:** Claude Code
**Time spent:** ~2 hours
**Next action:** Begin Sprint 6 (TDD implementation)
