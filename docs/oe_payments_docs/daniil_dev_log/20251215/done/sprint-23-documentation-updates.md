# Sprint 23: Update Architecture Documentation

**Date:** 2025-12-15
**Priority:** MEDIUM
**Status:** TODO
**Branch:** b-7.4.x-code-review-STRP-75
**Est. Effort:** 2 hours
**Original Sprint:** 2025-12-09

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

Architecture documents are out of sync with implementation after Sprints 15-22 refactoring.

### Documents Requiring Updates

| Document | Issue |
|----------|-------|
| `00-overview.md` | Missing terminal states (CANCELLED, EXPIRED, FAILED) |
| `01-architecture-layers.md` | Missing OXPAID strategy, ContainerFactory resolution |
| `02-database-and-models.md` | References dropped `oe_payments_order_state` table |
| `03-building-payment-modules.md` | Missing Component layer dependency clarification |
| `05-webhooks.md` | Missing WebhookProcessingService complexity documentation |

### Missing Documentation

| Topic | Description |
|-------|-------------|
| OXPAID Update Strategy | Which service, when, timezone handling |
| Contract Fulfillment Flow | Single service, guards, events |
| Session State Management | Delivery address hash pattern |
| New Services | RefundService, CheckoutReturnService, etc. |

---

## Implementation Plan

### 1. Update `00-overview.md`

**Location:** `docs/payment-component/00-overview.md`

**Change: Contract Lifecycle**

```markdown
<!-- BEFORE -->
**Contract Lifecycle:** `DRAFT → PENDING → COMMITTED → FULFILLED`

<!-- AFTER -->
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

---

### 2. Update `01-architecture-layers.md`

**Location:** `docs/payment-component/01-architecture-layers.md`

**Add Section: OXPAID Update Strategy**

```markdown
## OXPAID Update Strategy

**Single Source of Truth:** `OrderPaymentStateService`

All OXPAID updates go through this service to ensure:
- Consistent date formatting (PHP DateTimeImmutable)
- Single update location (DRY)
- Proper timezone handling

### Primary Update Path
1. User returns from Stripe checkout
2. `StripeOrderCreationHandler` creates order
3. `OrderPaymentStateService.markOrderAsPaid()` sets OXPAID

### Backup Path (webhook)
1. Stripe sends `payment_intent.succeeded` webhook
2. `PaymentIntentSucceededHandler` processes
3. If order exists but OXPAID empty, service updates it

### Service Interface
```php
interface OrderPaymentStateServiceInterface
{
    public function updatePaidTimestamp(string $orderId, ?DateTimeImmutable $paidAt = null): bool;
    public function updateTransactionStatus(string $orderId, string $status): bool;
    public function updateTransactionId(string $orderId, string $transactionId): bool;
    public function markOrderAsPaid(string $orderId, string $transactionId, ?DateTimeImmutable $paidAt = null): bool;
}
```
```

**Add Section: Handler Service Delegation**

```markdown
## Handler Service Delegation (Sprint 21)

Event handlers follow the delegation pattern - business logic extracted to services:

| Handler | Service | Responsibility |
|---------|---------|----------------|
| StripeRefundRequestHandler | RefundService | Refund processing |
| StripeCheckoutReturnHandler | CheckoutReturnService | Return validation |
| StripeCheckoutSessionHandler | CheckoutSessionService | Session creation |
| StripeContractCreationHandler | ContractMetadataService | Metadata operations |

### Pattern
```
Handler (thin)
├── Extract parameters from event
├── Delegate to Service
├── Handle result
└── Update context

Service (business logic)
├── Business logic
├── API calls via Adapter
├── Error handling
└── Return Result DTO
```
```

---

### 3. Update `02-database-and-models.md`

**Location:** `docs/payment-component/02-database-and-models.md`

**Remove `oe_payments_order_state` references:**

```markdown
<!-- REMOVE THIS SECTION -->
~~### oe_payments_order_state Table~~

<!-- ADD NOTE -->
> **Note:** The `oe_payments_order_state` table was removed in Sprint 8 (December 2025).
> All payment state tracking is now consolidated in `oe_payments_contract`.

### Capture/Refund Fields (in oe_payments_contract)

| Field | Type | Description |
|-------|------|-------------|
| OXCAPTUREDAMOUNT | DECIMAL(10,2) | Amount captured |
| OXREFUNDEDAMOUNT | DECIMAL(10,2) | Amount refunded |
| OXCAPTUREDAT | DATETIME | Capture timestamp |
| OXREFUNDEDAT | DATETIME | Refund timestamp |
```

---

### 4. Update `03-building-payment-modules.md`

**Location:** `docs/payment-component/03-building-payment-modules.md`

**Add Section: Component Layer Dependencies**

```markdown
## Component Layer Dependencies

The Component layer is **OXID-aware** but **provider-agnostic**.

### Framework Dependencies
- OXID Registry (Session, Request, Logger)
- OXID DatabaseProvider / Doctrine Connection
- OXID Configuration

### Provider Independence
The Component layer has NO direct dependencies on:
- Stripe SDK
- PayPal SDK
- Any other payment provider SDK

All provider-specific code resides in the respective provider subdirectory (e.g., `src/Stripe/`).

### For True Platform Independence
For non-OXID shops, the Component layer would need a `ShopAdapterInterface` abstraction:

```php
interface ShopAdapterInterface
{
    public function getSession(): SessionInterface;
    public function getRequest(): RequestInterface;
    public function getLogger(): LoggerInterface;
}
```
```

---

### 5. Update `05-webhooks.md`

**Location:** `docs/payment-component/05-webhooks.md`

**Add Section: WebhookProcessingService Architecture**

```markdown
## WebhookProcessingService Architecture

The `WebhookProcessingService` handles complex webhook routing with multiple strategies.

### Event Types Handled
1. `payment_intent.succeeded` - Payment completed
2. `payment_intent.payment_failed` - Payment failed
3. `checkout.session.completed` - Checkout session done
4. `charge.captured` - Charge captured
5. `charge.refunded` - Refund processed
6. `checkout.session.expired` - Session timeout

### Contract Lookup Strategies
1. By `checkout_session_id` (from metadata)
2. By `payment_intent_id` (transaction ID)
3. By `provider_order_id`

### Processing Flow
```
Webhook Received
      │
      ├── Signature Verification
      │
      ├── Event Type Routing
      │
      ├── Contract Lookup (3 strategies)
      │
      ├── Contract State Validation
      │
      └── State Update + Event Dispatch
```

### Race Condition Handling
Webhooks may arrive before user returns from Stripe. The service handles this by:
1. Checking if contract is in valid state for update
2. Logging and gracefully skipping if order doesn't exist yet
3. Order creation handler sets OXPAID (primary path)
4. Webhook handler updates if OXPAID still empty (backup path)
```

---

### 6. Create New Document: Service Catalog

**Location:** `docs/payment-component/10-service-catalog.md`

```markdown
# Service Catalog

## Component Services

| Service | Interface | Responsibility |
|---------|-----------|----------------|
| ContractService | ContractServiceInterface | Contract CRUD |
| ContractFulfillmentService | ContractFulfillmentServiceInterface | Fulfillment logic |
| OrderPaymentStateService | OrderPaymentStateServiceInterface | OXPAID updates |

## Stripe Services

| Service | Interface | Responsibility |
|---------|-----------|----------------|
| RefundService | RefundServiceInterface | Refund processing |
| CheckoutReturnService | CheckoutReturnServiceInterface | Return validation |
| CheckoutSessionService | CheckoutSessionServiceInterface | Session creation |
| ContractMetadataService | ContractMetadataServiceInterface | Metadata operations |
| WebhookProcessingService | - | Webhook routing |
| ModuleConfigurationService | - | Module settings |

## DTOs

| DTO | Purpose |
|-----|---------|
| RefundResult | Refund operation result |
| CheckoutReturnResult | Return validation result |
| CheckoutSessionResult | Session creation result |
```

---

## Files to Create

| File | Purpose |
|------|---------|
| `docs/payment-component/10-service-catalog.md` | Service documentation |

## Files to Modify

| File | Change |
|------|--------|
| `docs/payment-component/00-overview.md` | Add terminal states |
| `docs/payment-component/01-architecture-layers.md` | Add OXPAID strategy, handler pattern |
| `docs/payment-component/02-database-and-models.md` | Remove order_state table |
| `docs/payment-component/03-building-payment-modules.md` | Document dependencies |
| `docs/payment-component/05-webhooks.md` | Document service complexity |
| `docs/payment-component/INDEX.md` | Add new document references |

---

## Verification Checklist

- [ ] All code references in docs match actual implementation
- [ ] No references to removed `oe_payments_order_state`
- [ ] Contract states documented correctly (including terminal states)
- [ ] OXPAID strategy documented
- [ ] Handler delegation pattern documented
- [ ] New services documented
- [ ] INDEX.md updated with new documents
- [ ] Links verified working

---

## Success Criteria

1. All 5 existing documents updated
2. 1 new service catalog document created
3. No stale references to removed code
4. All new patterns from Sprints 15-22 documented
5. Documentation matches implementation

---

## Related Issues

- CODE_REVIEW.md Section 5 (Documentation Updates Required)
- CODE_REVIEW.md Section 1.3 (Contract State Machine Documentation Outdated)
- CODE_REVIEW.md Section 1.8 (oe_payments_order_state Table Status)

---

**Last Updated:** 2025-12-15
