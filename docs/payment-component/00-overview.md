# Enterprise Payment Component - Smart-Contract Architecture

**Version:** 4.0.0
**Date:** 2025-10-22
**Target Platform:** OXID eShop 7.4+ (compatible with 7.5, 8.0+)
**Based on:** Smart-contract pattern analysis of 5 payment providers (Stripe, PayPal, Amazon Pay, Unzer, TeleCash)
**Purpose:** Universal payment component with contract-based lifecycle management
**Architecture:** Event-driven with smart-contract pattern

---

## Executive Summary

This document describes a **smart-contract based payment component** that separates purchase intent (contract) from order fulfillment. The component implements a **contract-first approach** where:

> **"Clicking 'Place Order' creates a contract, not an order. The order is created only when the contract is fulfilled."**

### Key Innovation

**Traditional E-commerce Flow:**
```
User clicks "Place Order" → Order created (state: NOT_FINISHED) → Payment → Order updated (state: OK)
```

**Smart-Contract Flow:**
```
User clicks "Place Order" → Contract created (state: DRAFT) → Conditions resolved → Order created (state: OK)
```

### Core Capabilities

- **Contract Lifecycle Management**: DRAFT → PENDING → COMMITTED → FULFILLED
- **Explicit Condition Tracking**: payment_authorized, fraud_check, stock_reserved
- **Provider-Agnostic Design**: Works with Stripe, PayPal, Amazon Pay, Unzer, Adyen, Klarna, Square
- **Event-Driven Architecture**: All operations triggered via domain events
- **Clean Rollback**: Cancel contract vs. delete/rollback order
- **Headless/API-First**: Perfect for GraphQL, MCP protocol, programmatic commerce
- **No OXID Core Modifications**: Component tables with FK references only

---

## What's New in 4.0?

### Smart-Contract Pattern

The **osc/payment-component** now implements a contract-based lifecycle that mirrors how modern payment providers work internally:

**Alignment with Payment Providers:**
- **Stripe**: PaymentIntent (requires_confirmation → succeeded) = Contract pattern
- **PayPal**: Order (CREATED → APPROVED → COMPLETED) = Contract pattern
- **Amazon Pay**: ChargePermission → Charge = Two-tier contract
- **Adyen**: Payment with captureDelayHours = Contract pattern
- **Klarna**: Session → Order = Two-phase contract
- **Square**: Payment (APPROVED → COMPLETED) = Contract pattern

All major providers use two-phase patterns. We extend this to order creation.

### Architectural Benefits

- ✅ **Clean Separation**: Payment contract vs. order fulfillment domains
- ✅ **Better Rollback**: Cancel contract (no order created) vs. delete order
- ✅ **No Order Number Gaps**: Numbers assigned only when contracts fulfilled
- ✅ **True DDD**: Contract = Aggregate Root, Order = Separate Aggregate
- ✅ **API-First Ready**: Clear semantics (contract ID → order ID)
- ✅ **Explicit Conditions**: Tracked in database, not hidden in code 
- ✅ **Complete Audit Trail**: Contract history from intent to fulfillment

---

## Architectural Philosophy

### Contract as Aggregate Root

**What is a Payment Contract?**

A payment contract is a domain entity that:
1. **Captures Intent**: User's intention to purchase (basket snapshot, terms)
2. **Tracks Preconditions**: Payment authorization, fraud checks, stock availability
3. **Manages Lifecycle**: DRAFT → PENDING → COMMITTED → FULFILLED
4. **Triggers Order Creation**: Creates `oxorder` ONLY when all conditions met
5. **Provides Audit Trail**: Complete history of fulfillment process

**Why "Contract" Terminology?**

- **Legal**: Binding agreement with conditions between user and merchant
- **Technical**: Executable specification of fulfillment requirements
- **Domain**: Aggregate root that owns the purchase lifecycle
- **Practical**: Container for all data needed before order creation

### Event-Driven First

Controllers and CLI commands act as **thin security and validation layers** that emit events. All business logic happens inside event handlers, services, and domain models.

**Flow:**
```
Controller validates → Emits event → Event handler creates contract →
Contract conditions resolved → Order created → Payment captured → Contract fulfilled
```

### Headless by Design

Frontend controllers don't execute business logic directly. They:
1. Validate and sanitize input
2. Enforce security policies
3. Emit domain events
4. Return responses based on event outcomes

---

## Document Structure

### Core Documentation (Sequential)

1. **00-overview.md** (this file) - Executive summary and smart-contract introduction
2. **01-architecture-layers.md** - Contract-aware event-driven architecture
3. **02-database-and-models.md** - Contract-aware database schema with master-detail pattern
4. **03-building-payment-modules.md** - How to build provider modules on top of contracts
5. **04-sdk-adapter-layer.md** - Provider abstraction architecture
6. **05-webhooks.md** - Webhook processing with contract integration
7. **06-onepage-checkout.md** - One-page checkout & headless API
   - **06-01-onepage-checkout-implementation.md** - Complete TDD implementation plan
8. **07-capture-refund-operations.md** - Capture/refund workflows with contracts
9. **08-security-and-fraud.md** - Security patterns and fraud prevention
   - **08-01-fraud-prevention-details.md** - Detailed fraud detection algorithms
10. **09-tdd-strategy.md** - Test-driven development strategy
11. **10-test-organization.md** - Component vs provider test separation
    - **10-01-provider-module-testing.md** - Provider-specific testing patterns
12. **11-comprehensive-provider-analysis.md** - Analysis of 5 payment providers
13. **12-blockchain-inventory-management.md** - Blockchain integration patterns

### Reference Documents

- **INDEX.md** - Complete documentation index
- **README.md** - Getting started guide
- **DELIVERY-SUMMARY.md** - Project delivery summary
- **IMPLEMENTATION-TICKETS-SPRINT-1.md** - Sprint 1 implementation tickets

---

## Target Audience

### For Architects
- Read: [01-architecture-layers.md](01-architecture-layers.md), [02-database-and-models.md](02-database-and-models.md)
- Understand: Smart-contract pattern, contract state machine, condition tracking
- Focus: How contracts separate intent from fulfillment

### For Backend Developers
- Read: [02-database-and-models.md](02-database-and-models.md), [04-sdk-adapter-layer.md](04-sdk-adapter-layer.md)
- Implement: Contract lifecycle, condition handlers, order creation from contracts
- Focus: Event handlers, repositories, domain models

### For Integration Engineers
- Read: [03-building-payment-modules.md](03-building-payment-modules.md), [04-sdk-adapter-layer.md](04-sdk-adapter-layer.md)
- Build: Provider adapters that map provider contracts to our contracts
- Focus: Stripe PaymentIntent → Contract, PayPal Order → Contract mappings

### For Frontend Developers
- Read: [06-onepage-checkout.md](06-onepage-checkout.md)
- Implement: Contract-aware checkout UI, condition status display
- Focus: GraphQL API for contract queries, headless checkout

### For QA Engineers
- Read: [09-tdd-strategy.md](09-tdd-strategy.md), [10-test-organization.md](10-test-organization.md)
- Test: Contract lifecycle, condition fulfillment, state transitions
- Focus: Pure domain logic testing (no database required)

---

## Contract Lifecycle Overview

### Phase 1: Order Intent

```
User clicks "Place Order"
  ↓
Create osc_payment_contract (state: DRAFT)
  - Captures: Basket snapshot, user data, terms
  - Conditions: payment_authorized, fraud_check, stock_reserved
  - No oxorder yet!
  ↓
Contract state: DRAFT → PENDING
```

### Phase 2: Condition Resolution

```
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
```

### Phase 3: Order Creation (Optimal Point)

```
ContractConditionsFulfilledEvent
  ↓
Create oxorder (state: NOT_FINISHED)
  - Order number assigned HERE (no gaps!)
  - Contract.commitToOrder(oxorder.OXID)
  ↓
Contract state: PENDING → COMMITTED
Create osc_payment_order_state (FK to both contract + order)
```

### Phase 4: Fulfillment

```
PaymentCapturedEvent (webhook)
  ↓
Contract.fulfill()
  - Marks contract as FULFILLED
  - Order.markAsOK()
  - Contract.fulfilledAt = NOW()
  ↓
Contract state: COMMITTED → FULFILLED
Order state: NOT_FINISHED → OK
```

---

## Contract State Machine

### States

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

### Terminal States

- `FULFILLED` - Success, payment captured, order ready
- `CANCELLED` - User or system cancelled before fulfillment
- `EXPIRED` - Contract timeout (default: 24 hours)
- `FAILED` - Error during condition resolution

### Transition Rules

- **DRAFT → PENDING**: Conditions defined, resolution begins
- **PENDING → READY_TO_COMMIT**: All conditions fulfilled
- **READY_TO_COMMIT → COMMITTED**: Order created successfully
- **COMMITTED → FULFILLED**: Payment captured
- **Any → CANCELLED**: User/system cancellation (except FULFILLED)
- **Any → EXPIRED**: Timeout reached (except terminal states)
- **Any → FAILED**: Condition failure (except terminal states)

---

## Supported Payment Providers

### Analyzed & Fully Supported

All providers implement contract-like patterns internally:

- ✅ **Stripe** - PaymentIntent pattern = Contract
- ✅ **PayPal** - Order pattern (CREATED → APPROVED → COMPLETED) = Contract
- ✅ **Amazon Pay** - ChargePermission → Charge (two-tier) = Contract
- ✅ **Unzer** - Payment with authorization = Contract
- ✅ **TeleCash** - SOAP authorization pattern = Contract

### Provider Contract Mappings

| Provider | Contract Object | Intent State | Committed State | Fulfilled State |
|----------|----------------|--------------|-----------------|-----------------|
| Stripe | PaymentIntent | requires_confirmation | requires_capture | succeeded |
| PayPal | Order | CREATED | APPROVED | COMPLETED |
| Amazon Pay | ChargePermission + Charge | Chargeable | Authorized | Captured |
| Adyen | Payment | Pending | Authorised | SettledSuccessfully |
| Klarna | Session + Order | Session created | AUTHORIZED | CAPTURED |
| Square | Payment | - | APPROVED | COMPLETED |

**Key Insight:** Our smart-contract pattern aligns perfectly with how payment providers work internally. We're not inventing a new pattern—we're making explicit what providers already do implicitly.

---

## Core Component Architecture

### 0. Contract Layer (NEW - Primary Layer)

**Purpose:** Manage payment lifecycle before order creation

- **PaymentContract**: Aggregate root managing conditions and state
- **ContractCondition**: Entity representing fulfillment preconditions
- **ContractRepository**: Persistence for contracts
- **Contract Events**: ContractCreated, ConditionFulfilled, ContractCommitted, ContractFulfilled

### 1. Event Layer (100% Reusable)

**The heart of the architecture**: All business operations are event-driven

- **Domain Events**: PaymentInitiatedEvent, ContractCreatedEvent, ContractCommittedEvent, etc.
- **Event Handlers**: Subscribers that execute business logic with contract awareness
- **Event Dispatcher**: PSR-14 compliant event routing
- **Event Context**: Carries cached request data across handlers

### 2. Presentation Layer (Controllers & CLI)

**Thin security & validation layer**: No business logic

- Validate and sanitize user input
- Enforce authentication & authorization
- **Cache request data** (basket, user, session)
- **Emit domain events** with validated data
- Return responses (redirects, JSON, views)

### 3. Service Layer (95% Reusable - Enhanced)

**Event-triggered services**: Called by event handlers, contract-aware

- **ContractService**: Contract creation, condition management
- **PaymentService**: Provider API operations via adapter
- **AuthorizationService**: Two-step auth/capture flow
- **IdempotencyService**: Prevent duplicate charges (critical P0)
- **VaultingService**: Save payment methods
- **SCAValidatorService**: 3D Secure/SCA verification
- **RefundService**: Partial refund calculations
- **OrderManager**: Order lifecycle management (creates from contracts)

### 4. SDK-Adapter Layer (NEW - 100% Pattern Reusable)

**Provider abstraction**: Unified interface for all payment providers

- **PaymentAdapterInterface**: Universal contract (createPayment, authorizePayment, etc.)
- **Request/Response DTOs**: Provider-agnostic data structures (100% reusable)
- **Provider Adapters**: Stripe, PayPal, Unzer, Amazon Pay adapters (30% code per adapter)
- **AdapterFactory**: Configuration-driven provider selection
- **Exception Handling**: Unified error handling

### 5. Data Layer (100% Reusable - Enhanced)

**Component tables with FK references**: NO ALTER TABLE on OXID core

- **osc_payment_contract**: Master contract table (NEW)
- **osc_payment_transaction**: Transaction tracking (enhanced with OXCONTRACTID)
- **osc_payment_order_state**: Payment lifecycle state (enhanced with OXCONTRACTID)
- **osc_payment_customer**: Customer payment data (1:1 with oxuser)
- **osc_payment_idempotency**: Duplicate charge prevention
- **osc_payment_saved_methods**: Vaulting/tokenization
- **osc_payment_sessions**: Session state management

**Architecture Principle:** Minimal core dependency - NO ALTER TABLE on oxorder/oxuser/oxbasket

### 6. Webhook System (100% Reusable - Contract-Aware)

- Signature verification
- Webhook event dispatcher
- Handler registry with contract lookup
- Base handler class (finds contract by provider order ID)
- Idempotency for webhook redelivery

---

## Technology Stack

### Required

- PHP 8.1+ (for typed properties, match expressions)
- Relational database (MySQL 8.0+, PostgreSQL)
- PSR-3 Logger
- PSR-14 Event Dispatcher (or Symfony EventDispatcher)

### Optional but Recommended

- Doctrine DBAL for database abstraction
- Symfony DependencyInjection for service container
- PHPUnit for testing
- Monolog for logging
- Redis for contract caching

---

## Design Principles

### 1. Contract as Aggregate Root

**Domain-Driven Design:**
- Contract = Aggregate Root (owns conditions, basket snapshot, lifecycle)
- Order = Separate Aggregate (created by contract, owns fulfillment)
- Condition = Entity (managed by contract)
- BasketSnapshot = Value Object (immutable)
- ContractState = Value Object (type-safe states)

### 2. Separation of Concerns

**Clear boundaries:**
- **Contract Domain**: Intent capture, condition management, payment processing
- **Order Domain**: Fulfillment, shipping, invoicing, customer service
- **Payment Module**: Provider integration, adapter implementation

### 3. Event-Driven Architecture

**Critical payment events trigger subscribers:**
- Extensibility without modifying core
- Multiple subscribers can react to single event
- Audit logging built-in
- Third-party integrations easy

### 4. Explicit State Management

**Contract state machine is separate from order state:**
- Contract states: DRAFT, PENDING, READY_TO_COMMIT, COMMITTED, FULFILLED
- Order states: NOT_FINISHED, OK (simplified - no intermediate payment states)
- Clean separation prevents state pollution

### 5. Async Payment Handling

**Support for redirect-based and webhook-based flows:**
- Contract creation before redirect
- Webhook processing updates contract
- Order created only when safe
- Timeout management via contract expiration

---

## Migration from Traditional Architecture

### Old Pattern (Deprecated)

```php
// ❌ OLD: Order created immediately
$order = $this->orderManager->createTemporaryOrder($basket, $user);
$order->setState(Order::ORDER_STATE_NOT_FINISHED);
$order->save();

// Payment processing...
// If payment fails, orphan order exists
```

### New Pattern (Smart-Contract)

```php
// ✅ NEW: Contract created first
$contract = new PaymentContract(
    shopId: $this->config->getShopId(),
    userId: $user->getId(),
    basketSnapshot: BasketSnapshot::fromOxidBasket($basket)
);

// Add conditions
$contract->addCondition(new ContractCondition('payment_authorized'));
$contract->addCondition(new ContractCondition('fraud_check'));
$contract->transitionToPending();
$this->contractRepository->save($contract);

// Order created ONLY when all conditions fulfilled
// If payment fails, contract cancelled, no order created
```

### Migration Strategy

**Phase 1**: Add contract table and models (no impact on production)
**Phase 2**: Feature flag for contract flow (A/B testing possible)
**Phase 3**: Gradual rollout (10% → 25% → 50% → 100%)
**Phase 4**: Deprecate old flow

---

## Key Architectural Patterns

### 1. Contract Lifecycle Pattern

```
Contract captures intent → Conditions resolved → Order created → Payment captured → Contract fulfilled
```

### 2. Condition Tracking Pattern

**Explicit conditions in database:**
```json
[
  {"type": "payment_authorized", "status": "fulfilled", "fulfilledAt": "2025-10-22T14:30:15Z"},
  {"type": "fraud_check", "status": "fulfilled", "fulfilledAt": "2025-10-22T14:30:18Z"},
  {"type": "stock_reserved", "status": "pending", "fulfilledAt": null}
]
```

### 3. Foreign Key Reference Pattern

**Component tables reference OXID core via FK:**
```sql
-- Contract table
CREATE TABLE osc_payment_contract (
    OXID CHAR(32) PRIMARY KEY,
    OXUSERID CHAR(32) NOT NULL,  -- FK to oxuser.OXID
    OXORDERID CHAR(32) NULL,  -- FK to oxorder.OXID (NULL until committed!)
    FOREIGN KEY (OXUSERID) REFERENCES oxuser(OXID),
    FOREIGN KEY (OXORDERID) REFERENCES oxorder(OXID)
);
```

**NO ALTER TABLE** on oxorder/oxuser - clean isolation!

### 4. Request Data Caching Pattern

**Cache HTTP request data for event handlers:**
- Controllers cache basket, user, session, configuration
- Event handlers access cached data via context object
- Eliminates redundant database queries
- Maintains data consistency across event chain

---

## Integration Points

### Shop Integration

- **Order Creation**: OrderFactory creates from contract
- **Basket Handling**: Immutable snapshot in contract
- **User Data**: Reference via FK, no extensions
- **Email Notifications**: Triggered by ContractFulfilledEvent

### Provider Integration

- **API Client**: Via SDK-Adapter layer
- **Authentication**: OAuth, API keys per provider
- **Webhook Processing**: Contract lookup by provider order ID
- **Request/Response Mapping**: Provider states → Contract states

### Admin Integration

- **Contract Management**: New admin UI for pending contracts
- **Order Management**: Traditional UI for fulfilled orders
- **Transaction History**: Contract + order linked
- **Webhook Monitoring**: Webhook delivery status

---

## Testing Strategy

### Unit Tests (Pure Domain Logic)

```php
// No database required!
$contract = new PaymentContract($shopId, $userId, $basketSnapshot);
$contract->addCondition(new ContractCondition('payment_authorized'));
$contract->fulfillCondition('payment_authorized');

$this->assertTrue($contract->areAllConditionsFulfilled());
// Fast, pure, no framework dependencies
```

### Integration Tests

- Contract repository persistence
- Event handler orchestration
- Order creation from contract
- Webhook processing with contract lookup

### E2E Tests (Codeception/Playwright)

- Complete checkout with contract UI
- Provider redirects and callbacks
- Contract → order → fulfillment flow

---

## Performance Characteristics

### Contract Operations

| Operation | Time | Notes |
|-----------|------|-------|
| Contract creation | ~50ms | JSON storage, 3 writes |
| Condition fulfillment | ~10ms | JSON update |
| All conditions check | <1ms | In-memory iteration |
| Order creation from contract | ~100ms | Full order creation |
| **Total overhead** | **+50ms** | Compared to traditional flow |

### Optimizations

- Redis caching for contract state
- Indexed queries (OXSTATE, OXPROVIDERORDERID)
- Lazy-loading of basket snapshot
- Async condition processing

---

## Security Considerations

### Contract Security

- **Immutable basket snapshot**: Cannot be modified after creation
- **User binding**: Contract bound to specific user ID
- **Expiration**: 24-hour timeout prevents stale contracts
- **Condition validation**: All conditions must pass before order creation

### GDPR Compliance

- Contract data retention policy (90 days after fulfillment)
- User data removal cascades to contracts
- Right to erasure supported
- Audit trail for compliance

---

## Next Steps

1. Read [01-architecture-layers.md](01-architecture-layers.md) for detailed contract-aware architecture
2. Review [02-database-and-models.md](02-database-and-models.md) for contract database schema
3. Study [11-comprehensive-provider-analysis.md](11-comprehensive-provider-analysis.md) for provider contract mappings
4. Check [INDEX.md](INDEX.md) for complete documentation navigation
5. Review [IMPLEMENTATION-TICKETS-SPRINT-1.md](IMPLEMENTATION-TICKETS-SPRINT-1.md) for implementation roadmap

---

## Glossary

**Contract** - Domain entity capturing purchase intent before order creation
**Condition** - Precondition that must be fulfilled before contract can be committed
**Commitment** - Act of creating order from contract (all conditions met)
**Fulfillment** - Payment captured, contract complete, order ready for shipping
**Aggregate Root** - DDD pattern, contract owns its conditions and lifecycle
**Basket Snapshot** - Immutable copy of basket data at contract creation time

**Authorization** - Reserve funds without capturing
**Capture** - Actually charge the reserved funds
**Order Intent** - CAPTURE (immediate) or AUTHORIZE (capture later)
**PUI** - Pay Upon Invoice (buy now, pay later)
**SCA** - Strong Customer Authentication (3D Secure 2.0)
**Vaulting** - Saving payment methods for future use
**Webhook** - Server-to-server callback from payment provider

---

**Status:** ✅ Smart-Contract Architecture (v4.0) - Ready for Implementation
**Continue to:** [01-architecture-layers.md](01-architecture-layers.md)
