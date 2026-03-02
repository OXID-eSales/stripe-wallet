# Payment Architecture Overview

**Date:** 2026-02-04
**Based on:** Actual code analysis of payment-component and stripe modules

---

## Module Relationship

```
┌─────────────────────────────────────────────────────────────────────┐
│                         stripe module                                │
│  (Provider-specific implementation)                                  │
│                                                                      │
│  ┌─────────────────────────────────────────────────────────────────┐│
│  │  Implements: PaymentAdapterInterface, SessionAdapterInterface,  ││
│  │              ShopAdapterInterface, HandlerInterface              ││
│  │  Extends: ContractCreationHandler, AbstractPaymentCaptureService││
│  │           AbstractPaymentRefundService, AbstractWebhookProcessor││
│  └─────────────────────────────────────────────────────────────────┘│
│                              ↓ uses                                  │
├─────────────────────────────────────────────────────────────────────┤
│                      payment-component                               │
│  (Provider-agnostic library)                                         │
│                                                                      │
│  ┌─────────────────────────────────────────────────────────────────┐│
│  │  Defines: Interfaces, Abstract Classes, Domain Events           ││
│  │  Provides: Contract State Machine, Event System, Repositories   ││
│  └─────────────────────────────────────────────────────────────────┘│
└─────────────────────────────────────────────────────────────────────┘
```

---

## Smart-Contract Architecture

The system implements a **contract-first payment pattern with early order creation**:

**Traditional Flow:**
```
User clicks "Place Order" → Order created → Payment → Order updated
```

**Smart-Contract Flow (with Early Order Creation):**
```
User clicks "Place Order" → Contract created (DRAFT)
                          → Order created early (NOT_FINISHED → PENDING)
                          → Payment initiated with order_number
                          → Conditions resolved → Order finalized (COMMITTED → FULFILLED)
```

**Why Early Order Creation?**
- Order number is available **before** payment is initiated
- Order number is sent to Stripe in PaymentIntent metadata
- Stripe dashboard shows order number for reconciliation
- No need to update Stripe metadata after order creation

---

## Contract Lifecycle (Early Order Creation)

```
                    ┌─────────┐
                    │  DRAFT  │  Contract created, no order yet
                    └────┬────┘
                         │ EarlyOrderCreationHandler creates order
                         │ order_number stored in metadata
                         ▼
               ┌─────────────────┐
               │  NOT_FINISHED   │  Order exists (OXORDERID set)
               └────────┬────────┘
                        │ transitionToPending()
                        ▼
                  ┌───────────┐
                  │  PENDING  │  Stripe session created with order_number
                  └─────┬─────┘
                        │ payment authorized
                        ▼
                 ┌────────────┐
                 │ AUTHORIZED │ (two-step capture only)
                 └──────┬─────┘
                        │ all conditions fulfilled
                        ▼
            ┌────────────────────┐
            │  READY_TO_COMMIT   │  Payment confirmed
            └──────────┬─────────┘
                       │ StripeOrderCreationHandler detects existing order
                       │ updates OXTRANSID, sets OXPAID
                       ▼
                 ┌───────────┐
                 │ COMMITTED │  Order finalized
                 └─────┬─────┘
                       │ payment captured/complete
                       ▼
                 ┌───────────┐
                 │ FULFILLED │ (terminal)
                 └───────────┘

   Alternative terminal states: CANCELLED, EXPIRED, FAILED
```

**Key Insight:** The order is created in NOT_FINISHED state, not in COMMITTED. This allows:
- Order number to be sent to Stripe immediately
- Order to exist before payment completion
- Stripe dashboard to show order number for support/reconciliation

---

## Contract Conditions

Default conditions added to every contract:
- `payment_authorized` - Payment provider has authorized the payment
- `fraud_check` - Fraud check passed (optional, provider-specific)

Conditions can be fulfilled independently, and when all are met, the contract transitions to `READY_TO_COMMIT`.

---

## Key Domain Models

### PaymentContract (Aggregate Root)
- Manages state machine transitions
- Contains: ShopId, UserId, OrderId (null until committed), BasketSnapshot
- Tracks: conditions, capturedAmount, refundedAmount, provider metadata

### BasketSnapshot (Value Object)
- Immutable snapshot of basket at contract creation time
- Contains: items, discounts, totalGross, totalNet, totalVat, currency

### ContractCondition
- Types: payment_authorized, fraud_check, stock_reserved (extensible)
- Fulfillment tracking with optional data payload

---

## Database Schema

All tables in `payment-component/migration/`:

| Table | Purpose |
|-------|---------|
| `oe_payments_contract` | Contract lifecycle, basket snapshot, capture/refund tracking |
| `oe_payments_transaction` | Transaction history with OXCONTRACTID FK |
| `oe_payments_customer` | Customer payment data (vaulting) |
| `oe_payments_idempotency` | Duplicate charge prevention |
| `oe_payments_sessions` | Session state management |
| `oe_payments_webhooklogs` | Webhook event logs |

---

## Module Statistics

| Module | Files | Interfaces | Handlers | Services |
|--------|-------|------------|----------|----------|
| payment-component | 90+ | 25+ | 10 | 15+ |
| stripe | 81 | 8 | 9 | 12 |

---

## References

- `01-architecture-layers.md` - Detailed layer architecture
- `02-event-system.md` - Event-driven architecture
- `03-provider-abstraction.md` - Provider-agnostic design
- `04-webhook-processing.md` - Webhook handling
