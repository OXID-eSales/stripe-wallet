# Transaction DTO Analysis Report

**Date:** 2026-01-30
**File:** `payment-component/src/Transaction/Transaction.php`

## Question

Why is this DTO separate from the `Adapter/Response/` classes? Is it dead code?

## Analysis Summary

**Status:** ACTIVE CODE - Not dead code

The `Transaction` class is fundamentally different from the `Adapter/Response/*` classes:

| Aspect | Transaction | Response DTOs |
|--------|-------------|---------------|
| **Purpose** | Persistence entity | API transfer object |
| **Lifecycle** | Persisted to database | Transient (single request) |
| **Mutability** | Mutable (has setters) | Immutable (readonly) |
| **Location** | `Transaction/` namespace | `Adapter/Response/` namespace |

## Usage Analysis

### Direct Usages Found (5 files)

1. **`TransactionRepositoryInterface.php`**
   - Defines repository contract for Transaction persistence
   - Methods: `save()`, `findById()`, `findByOrderId()`, etc.

2. **`DoctrineTransactionRepository.php`**
   - Implements repository using Doctrine DBAL
   - Persists Transaction to `oe_payments_transaction` table

3. **`AbstractPaymentRefundService.php`**
   - Uses `TransactionRepositoryInterface::logRefund()` method
   - Logs refund transactions after successful refunds

4. **`OxidShopOrderService.php` (Stripe)**
   - Creates Transaction records for order operations

5. **Test Files**
   - Integration and unit tests for repository

### Indirect Usages (TransactionRepositoryInterface)

The repository interface is injected into:
- `AbstractPaymentRefundService` - Logs refunds
- `OxidShopOrderService` - Order processing
- `StripeRefundServiceTest` - Test mocking

## Why It's Separate from Response DTOs

### 1. Different Design Pattern

**Response DTOs (Adapter/Response/)**
- **Pattern:** Value Object / Data Transfer Object
- **Purpose:** Transfer data between layers (Adapter → Service → Handler)
- **Lifetime:** Single request/response cycle
- **Immutability:** `readonly class` with private constructor
- **Factory Methods:** `success()` / `failure()`

**Transaction Entity**
- **Pattern:** Entity / Aggregate
- **Purpose:** Represent persisted payment transaction record
- **Lifetime:** Persisted indefinitely in database
- **Mutability:** Has setter methods for status updates
- **Factory Method:** `fromArray()` for hydration from DB

### 2. Different Responsibility

```
┌─────────────────────────────────────────────────────────────┐
│                    STRIPE PAYMENT FLOW                       │
├─────────────────────────────────────────────────────────────┤
│                                                              │
│  Stripe API  ──→  StripeAdapter  ──→  CaptureResponse       │
│                        │                    │                │
│                        │                    ▼                │
│                        │           (transient, discarded)    │
│                        │                                     │
│                        ▼                                     │
│               TransactionRepository.save(Transaction)        │
│                        │                                     │
│                        ▼                                     │
│               oe_payments_transaction table                  │
│                        │                                     │
│                        ▼                                     │
│               (persisted, queryable)                        │
│                                                              │
└─────────────────────────────────────────────────────────────┘
```

- **CaptureResponse**: Captures the *immediate result* of an API call
- **Transaction**: Captures the *historical record* for audit/reconciliation

### 3. Different Schema

**CaptureResponse fields:**
```php
providerPaymentId, captureId, amountCaptured, currency,
status, capturedAt, errorMessage, errorCode, providerData, metadata
```

**Transaction fields:**
```php
id, shopId, orderId, contractId, provider, providerOrderId,
transactionId, type, status, amount, currency, paymentMethodId,
paymentMethodType, parentTransactionId, createdAt, updatedAt
```

Transaction has additional fields for:
- Multi-shop support (`shopId`)
- Order linking (`orderId`)
- Contract linking (`contractId`)
- Transaction hierarchy (`parentTransactionId`)
- Payment method tracking (`paymentMethodId`, `paymentMethodType`)

## Database Table

The Transaction entity maps to `oe_payments_transaction`:

```sql
CREATE TABLE oe_payments_transaction (
    OXID VARCHAR(32) PRIMARY KEY,
    OXSHOPID INT,
    OXORDERID VARCHAR(32),
    OXCONTRACTID VARCHAR(32),
    OXTRANSACTIONID VARCHAR(255),
    OXPROVIDER VARCHAR(32),
    OXPROVIDERORDERID VARCHAR(255),
    OXTYPE VARCHAR(32),          -- 'capture', 'refund', 'authorization'
    OXSTATUS VARCHAR(32),        -- 'succeeded', 'pending', 'failed'
    OXAMOUNT DOUBLE,
    OXCURRENCY VARCHAR(3),
    OXPAYMENTMETHODID VARCHAR(255),
    OXPAYMENTMETHODTYPE VARCHAR(32),
    OXPARENTTRANSACTIONID VARCHAR(32),
    OXTIMESTAMP TIMESTAMP
);
```

## Recommendation

**Keep Transaction separate** - it serves a fundamentally different purpose:

1. **Response DTOs** = "What happened just now?" (transient)
2. **Transaction Entity** = "What payments occurred?" (persistent)

The Transaction class is correctly placed in its own namespace as it's an entity, not a DTO. It follows different design patterns and serves the persistence layer rather than the transfer layer.

## Potential Improvements

1. Consider using Doctrine ORM annotations/attributes instead of manual `toArray()`/`fromArray()`
2. Add `readonly` to immutable properties
3. Consider moving to `Entity/` namespace for clarity
4. Remove `@SuppressWarnings(PHPMD)` - fix the warnings instead

## Conclusion

The `Transaction` class is **NOT dead code** and is **correctly separate** from Response DTOs. It's an active entity used for:
- Auditing payment operations
- Reconciliation with provider records
- Historical tracking of refunds/captures
- Multi-shop transaction management
