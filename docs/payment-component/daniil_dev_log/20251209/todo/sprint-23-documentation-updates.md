# Sprint 23: Update Architecture Documentation

**Date:** 2025-12-09
**Priority:** MEDIUM
**Status:** PENDING
**Branch:** TBD (b-7.4.x-STRP-XX)
**Est. Effort:** 2 hours
**Depends On:** Sprint 15-22 (code changes)

---

## Development Principles Checklist

| Principle | How Applied |
|-----------|-------------|
| **Documentation as Code** | Docs versioned with code |
| **Accuracy** | Docs match implementation |
| **Completeness** | All patterns documented |
| **Clean Code** | Clear, concise language |

---

## Problem Statement

**Architecture documents out of sync with implementation:**

| Document | Update Required |
|----------|-----------------|
| `00-overview.md` | Add terminal states (CANCELLED, EXPIRED, FAILED) |
| `01-architecture-layers.md` | Document ContainerFactory usage, OXPAID strategy |
| `02-database-and-models.md` | Remove `osc_payment_order_state` references |
| `03-building-payment-modules.md` | Document actual Component layer dependencies |
| `05-webhooks.md` | Document WebhookProcessingService complexity |

**Missing Documentation:**
1. OXPAID Update Strategy
2. Contract Fulfillment Flow
3. Session State Management
4. Reconciliation Strategy

---

## Documentation Updates

### 1. Update `00-overview.md`

**Current (incorrect):**
```markdown
**Contract Lifecycle:** `DRAFT → PENDING → COMMITTED → FULFILLED`
```

**Updated:**
```markdown
**Contract Lifecycle:**
```
DRAFT → PENDING → READY_TO_COMMIT → COMMITTED → FULFILLED
                                              ↘ CANCELLED
                                              ↘ EXPIRED
                                              ↘ FAILED
```

**Terminal States:**
- `FULFILLED` - Contract successfully completed, order created
- `CANCELLED` - User or system cancelled the contract
- `EXPIRED` - Contract timed out before fulfillment
- `FAILED` - Payment or other condition failed
```

### 2. Update `01-architecture-layers.md`

**Add new section: OXPAID Update Strategy**

```markdown
## OXPAID Update Strategy

**Single Source of Truth:** `OrderPaymentStateService`

All OXPAID updates go through this service to ensure:
- Consistent date formatting (PHP DateTimeImmutable)
- Single update location (DRY)
- Proper timezone handling

**Primary Update Path:**
1. User returns from Stripe checkout
2. `StripeOrderCreationHandler` creates order
3. `OrderPaymentStateService.markOrderAsPaid()` sets OXPAID

**Backup Path (webhook):**
1. Stripe sends `payment_intent.succeeded` webhook
2. `PaymentIntentSucceededHandler` processes
3. If order exists but OXPAID empty, service updates it

**Service Interface:**
```php
interface OrderPaymentStateServiceInterface
{
    public function updatePaidTimestamp(string $orderId, ?DateTimeImmutable $paidAt = null): bool;
    public function updateTransactionStatus(string $orderId, string $status): bool;
    public function updateTransactionId(string $orderId, string $transactionId): bool;
    public function markOrderAsPaid(string $orderId, string $transactionId, ?DateTimeImmutable $paidAt = null): bool;
}
```

### 3. Update `02-database-and-models.md`

**Remove all references to `osc_payment_order_state`:**

```markdown
~~### osc_payment_order_state Table~~

> **Note:** This table was removed in Sprint 8 (December 2025).
> All payment state tracking is now consolidated in `osc_payment_contract`.

**Capture/Refund Fields (in osc_payment_contract):**
| Field | Type | Description |
|-------|------|-------------|
| OXCAPTUREDAMOUNT | DECIMAL(10,2) | Amount captured |
| OXREFUNDEDAMOUNT | DECIMAL(10,2) | Amount refunded |
| OXCAPTUREDAT | DATETIME | Capture timestamp |
| OXREFUNDEDAT | DATETIME | Refund timestamp |
```

### 4. Update `03-building-payment-modules.md`

**Add section: Component Layer Dependencies**

```markdown
## Component Layer Dependencies

The Component layer is **OXID-aware** but **provider-agnostic**.

**Framework Dependencies:**
- OXID Registry (Session, Request, Logger)
- OXID DatabaseProvider / Doctrine Connection
- OXID Configuration

**Provider Independence:**
The Component layer has NO direct dependencies on:
- Stripe SDK
- PayPal SDK
- Any other payment provider SDK

All provider-specific code resides in the respective provider subdirectory (e.g., `src/Stripe/`).

**Note:** For true platform independence (non-OXID shops), the Component layer would need a `ShopAdapterInterface` abstraction.
```

### 5. Update `05-webhooks.md`

**Add section: WebhookProcessingService Architecture**

```markdown
## WebhookProcessingService Complexity

The `WebhookProcessingService` (~1,158 lines) handles complex webhook routing:

**Event Types Handled:**
1. `payment_intent.succeeded`
2. `payment_intent.payment_failed`
3. `checkout.session.completed`
4. `charge.captured`
5. `charge.refunded`
6. `checkout.session.expired`

**Contract Lookup Strategies:**
1. By `checkout_session_id` (metadata)
2. By `payment_intent_id` (transaction ID)
3. By `provider_order_id`

**Order Update Locations:**
1. OXPAID update (via OrderPaymentStateService)
2. OXTRANSSTATUS update
3. OXTRANSID update
4. Contract state transition

**Legacy Compatibility:**
The service maintains backward compatibility with orders created before the contract system was introduced.
```

### 6. New Document: OXPAID Update Strategy

**File:** `docs/payment-component/architecture/12-oxpaid-update-strategy.md`

```markdown
# OXPAID Update Strategy

## Overview

OXPAID is the timestamp when a payment was received. This document describes the single-source-of-truth strategy for updating this field.

## Problem (Historical)

Before Sprint 16, OXPAID was updated in 4 different locations with 3 different date handling approaches, leading to:
- Timezone inconsistencies
- Race conditions
- Duplicate code

## Solution

`OrderPaymentStateService` is the single service for all OXPAID updates.

## Flow Diagram

```
[Checkout Return Flow]
        │
        v
StripeOrderCreationHandler
        │
        v
OrderPaymentStateService.markOrderAsPaid()
        │
        v
    OXPAID SET


[Webhook Flow (backup)]
        │
        v
PaymentIntentSucceededHandler
        │
        v
Check: OXPAID empty?
        │
     YES │
        v
OrderPaymentStateService.updatePaidTimestamp()
        │
        v
    OXPAID SET
```

## Date Handling

All dates use PHP's `DateTimeImmutable` formatted to `Y-m-d H:i:s` to match OXID's timezone handling.

**Do NOT use:**
- MySQL `NOW()` - different timezone
- Stripe timestamps directly - requires conversion
```

### 7. New Document: Contract Fulfillment Flow

**File:** `docs/payment-component/architecture/13-contract-fulfillment-flow.md`

```markdown
# Contract Fulfillment Flow

## Overview

Contract fulfillment is the process of transitioning a contract from COMMITTED to FULFILLED state and creating the associated order.

## Service

`ContractFulfillmentService` is the single service for all fulfillment operations.

## Flow

```
[Contract in COMMITTED state]
        │
        v
ContractFulfillmentService.fulfill($contract)
        │
        ├── canFulfill() guard check
        │   ├── Is COMMITTED? Yes → continue
        │   └── Is FULFILLED? No → continue
        │
        ├── $contract->fulfill()
        │
        ├── $contractRepository->save($contract)
        │
        └── dispatch(ContractFulfilledEvent)
```

## Guards

Fulfillment is rejected if:
1. Contract is already FULFILLED
2. Contract is not in COMMITTED state
3. Contract is CANCELLED or EXPIRED

## Event

`ContractFulfilledEvent` is dispatched after successful fulfillment, triggering:
- Order creation
- Email notifications
- Analytics tracking
```

---

## Implementation Steps

### Step 1: Update Existing Documents

```bash
# Edit each document
# Verify accuracy against code
# Commit changes
```

### Step 2: Create New Documents

```bash
# Create new strategy documents
touch docs/payment-component/architecture/12-oxpaid-update-strategy.md
touch docs/payment-component/architecture/13-contract-fulfillment-flow.md
```

### Step 3: Update INDEX.md

Add new documents to the index.

### Step 4: Review and Validate

```bash
# Read through all updated docs
# Verify code references are accurate
# Check links work
```

---

## Files to Create/Modify

### Modified Files

| File | Change |
|------|--------|
| `00-overview.md` | Add terminal states |
| `01-architecture-layers.md` | Add OXPAID strategy section |
| `02-database-and-models.md` | Remove order_state table |
| `03-building-payment-modules.md` | Document Component dependencies |
| `05-webhooks.md` | Document service complexity |
| `INDEX.md` | Add new documents |

### New Files

| File | Purpose |
|------|---------|
| `12-oxpaid-update-strategy.md` | OXPAID update documentation |
| `13-contract-fulfillment-flow.md` | Fulfillment flow documentation |

---

## Verification Checklist

- [ ] All code references in docs match actual implementation
- [ ] No references to removed `osc_payment_order_state`
- [ ] Contract states documented correctly
- [ ] OXPAID strategy documented
- [ ] Fulfillment flow documented
- [ ] INDEX.md updated

---

## Success Criteria

1. ✅ All documents accurate
2. ✅ No stale references
3. ✅ New patterns documented
4. ✅ INDEX.md complete

---

## Related Issues

- CODE_REVIEW.md Section 5 (Documentation Updates Required)
- CODE_REVIEW.md Section 1.3 (Contract State Machine Documentation Outdated)
- CODE_REVIEW.md Section 1.8 (osc_payment_order_state Table Status)

---

**Last Updated:** 2025-12-09
