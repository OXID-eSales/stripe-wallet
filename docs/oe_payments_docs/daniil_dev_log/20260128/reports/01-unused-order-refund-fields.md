# Report: Refund Architecture Analysis - Dead Code & Simplification

**Date:** 2026-01-28
**Priority:** HIGH
**Status:** ANALYSIS COMPLETE

---

## Executive Summary

The refund architecture has accumulated dead code and unnecessary complexity. This report identifies:
1. **Dead code** - `OrderRefundUpdateService` writes to non-existent database fields
2. **Over-engineering** - Partial refund code in Stripe module (Stripe only needs full refund)
3. **Architectural alignment** - Keep partial refund in payment-component for other providers

---

## Architecture Overview

### Provider-Agnostic Layer (payment-component)

```
payment-component/
├── Service/
│   ├── AbstractPaymentRefundService.php  # Template Method - supports partial refund
│   └── PaymentRefundService.php          # Default implementation
└── Adapter/
    └── PaymentAdapterInterface.php       # Provider-agnostic operations
```

**Key Method:**
```php
// AbstractPaymentRefundService.php
final public function refund(string $contractId, ?float $amount = null, string $reason = ''): RefundResult
{
    // $amount = null → full refund
    // $amount = 50.00 → partial refund
}
```

**KEEP THIS** - Other providers (PayPal, Adyen, etc.) may need partial refunds.

---

### Stripe-Specific Layer (stripe module)

```
src/Stripe/
├── Service/
│   ├── RefundService.php                    # Sprint 21 - API calls
│   ├── RefundServiceInterface.php           # Sprint 21
│   ├── StripeRefundService.php              # Sprint 3 - extends AbstractPaymentRefundService
│   ├── OrderRefundUpdateService.php         # Sprint 10 - DEAD CODE
│   └── OrderRefundUpdateServiceInterface.php
├── Adapter/
│   ├── StripeAdapter.php                    # Implements PaymentAdapterInterface
│   └── StripeAdapterInterface.php
└── EventSystem/Handler/
    └── StripeRefundRequestHandler.php       # Uses RefundService
```

---

## Problems Identified

### Problem 1: `OrderRefundUpdateService` - DEAD CODE

**File:** `src/Stripe/Service/OrderRefundUpdateService.php`

```php
private function updateOrderCostFields(Order $order): void
{
    /** @phpstan-ignore-next-line */
    $order->oxorder__stripedelcostrefunded = new Field($order->oxorder__oxdelcost->value);
    // ... 5 more fields that DON'T EXIST in database
}
```

**Impact:** NO IMPACT - fields don't exist, writes are silently ignored by OXID ORM

**Action:** DELETE - this service has no effect

---

### Problem 2: Duplicate Refund Services

| Service | Layer | Purpose | Status |
|---------|-------|---------|--------|
| `AbstractPaymentRefundService` | payment-component | Contract-based refund with partial support | KEEP |
| `PaymentRefundService` | payment-component | Empty default implementation | **DELETE** (redundant) |
| `StripeRefundService` | stripe | Extends abstract, uses defaults | KEEP |
| `RefundService` | stripe | API-level refund (Sprint 21) | EVALUATE |
| `RefundServiceInterface` | stripe | Interface for above | EVALUATE |
| `OrderRefundUpdateService` | stripe | Updates order fields (broken) | **DELETE** |

**Analysis:**
- `RefundService` (Sprint 21) handles low-level Stripe API calls
- `StripeRefundService` (Sprint 3) handles contract-based refunds
- These serve different purposes at different architectural levels

---

### Problem 3: Unnecessary Partial Refund in Stripe Module

**Decision:** Stripe module should only support FULL REFUNDS.

**Rationale:**
- Simplifies admin UI (no amount input needed)
- Reduces edge cases and error handling
- Aligns with common e-commerce pattern (full refund = return item)
- Partial refunds can be achieved via Stripe Dashboard if needed

**But:** Keep partial refund support in `payment-component` for other providers.

---

### Problem 4: `OrderRefund.php` Reads Non-Existent Fields

**File:** `src/Stripe/Controller/Admin/OrderRefund.php`

```php
public function isFullRefundAvailable(): bool
{
    // These fields DON'T EXIST - always return null/0
    if (
        ($oOrder->oxorder__stripedelcostrefunded->value ?? 0) > 0  // Always false
        || ($oOrder->oxorder__stripepaycostrefunded->value ?? 0) > 0
        // ...
    ) {
        return false;  // Never reached
    }
    return true;  // Always returns true
}
```

**Impact:** Method always returns `true` (unless article has refund flag)

**Action:** Simplify to use Stripe API data instead of non-existent fields

---

## Recommended Architecture

### Layer Separation

```
┌─────────────────────────────────────────────────────────────┐
│  PAYMENT-COMPONENT (Provider-Agnostic)                      │
│  ✓ Full + Partial refund support                            │
│  ✓ Contract-based refund workflow                           │
│  ✓ Transaction logging                                      │
├─────────────────────────────────────────────────────────────┤
│  AbstractPaymentRefundService                               │
│    refund(contractId, ?amount, reason)                      │
│    └── amount=null: full refund                             │
│    └── amount=50.00: partial refund (for other providers)   │
└─────────────────────────────────────────────────────────────┘
                              │
                              │ extends
                              ▼
┌─────────────────────────────────────────────────────────────┐
│  STRIPE MODULE (Provider-Specific)                          │
│  ✓ FULL refund only                                         │
│  ✗ Partial refund (remove)                                  │
├─────────────────────────────────────────────────────────────┤
│  StripeRefundService extends AbstractPaymentRefundService   │
│    └── Uses default behavior (full refund)                  │
│    └── Override to REJECT partial refund requests           │
│                                                             │
│  RefundService (low-level API wrapper)                      │
│    └── processFullRefund() - KEEP                           │
│    └── processPartialRefund() - REMOVE                      │
│    └── processRefundByCharge() - simplify to full only      │
│                                                             │
│  OrderRefundUpdateService - DELETE (dead code)              │
└─────────────────────────────────────────────────────────────┘
```

---

## Implementation Plan

### Sprint 22: Refund Cleanup

**Task 1: Delete Dead Code**
- [ ] Delete `OrderRefundUpdateService.php`
- [ ] Delete `OrderRefundUpdateServiceInterface.php`
- [ ] Delete `tests/Unit/Stripe/Service/OrderRefundUpdateServiceTest.php`
- [ ] Remove from `services.yaml`
- [ ] Remove call from `StripeRefundRequestHandler`

**Task 2: Simplify `RefundService` - Remove Partial Refund**
- [ ] Remove `processPartialRefund()` method
- [ ] Update `RefundServiceInterface` to only have `processFullRefund()`
- [ ] Update `processRefundByCharge()` to not accept amount (always full)
- [ ] Update tests

**Task 3: Simplify `OrderRefund.php`**
- [ ] Remove dead `isFullRefundAvailable()` code reading STRIPE* fields
- [ ] Get refund availability from Stripe API (already done via `getStripeApiOrderLastCharge`)
- [ ] Remove `partialRefund()` method from controller

**Task 4: Update `StripeRefundService`**
- [ ] Override `refund()` to reject partial amounts
- [ ] Add validation: throw exception if `$amount !== null`

---

## Files to Modify

### DELETE
```
# Stripe module - dead code
src/Stripe/Service/OrderRefundUpdateService.php
src/Stripe/Service/OrderRefundUpdateServiceInterface.php
tests/Unit/Stripe/Service/OrderRefundUpdateServiceTest.php

# payment-component - redundant default implementation
extensions/payment-component/src/Service/PaymentRefundService.php
extensions/payment-component/tests/Unit/Service/PaymentRefundServiceTest.php
```

### MODIFY
```
src/Stripe/Service/RefundService.php              # Remove partialRefund
src/Stripe/Service/RefundServiceInterface.php     # Remove partialRefund
src/Stripe/Service/StripeRefundService.php        # Add partial refund rejection
src/Stripe/Controller/Admin/OrderRefund.php       # Remove dead code, remove partialRefund
src/Stripe/EventSystem/Handler/StripeRefundRequestHandler.php  # Remove OrderRefundUpdateService call
services.yaml                                      # Remove OrderRefundUpdateService
tests/Unit/Stripe/Service/RefundServiceTest.php   # Update tests
tests/Unit/Stripe/EventSystem/Handler/StripeRefundRequestHandlerTest.php
```

### KEEP (payment-component)
```
extensions/payment-component/src/Service/AbstractPaymentRefundService.php  # Template Method base class
```

### DELETE (payment-component - redundant)
```
extensions/payment-component/src/Service/PaymentRefundService.php          # Empty default, not needed
extensions/payment-component/tests/Unit/Service/PaymentRefundServiceTest.php
```

---

## Historical Context

| Sprint | Date | Change |
|--------|------|--------|
| Sprint 3 | 2025-12-03 | Created `StripeRefundService` extending abstract |
| Sprint 5 | 2025-12-03 | Documented STRIPE* fields as BAD architecture |
| Sprint 10 | 2026-01-23 | Extracted `OrderRefundUpdateService` (already broken) |
| Sprint 21 | 2026-01-26 | Created `RefundService` for API calls |
| **Sprint 22** | **2026-01-28** | **Cleanup: delete dead code, remove partial refund** |

---

## Verification Results (2026-01-28)

**Events.php has been cleaned up.** No STRIPE* column creation code exists.

```php
/**
 * Database schema is handled by Doctrine migrations in migration/data/
 * This class only handles payment method installation and cache clearing.
 */
```

**The architecture decision was implemented but cleanup was incomplete:**
- Events.php was fixed (columns not created)
- Code using those columns was NOT removed

---

## Conclusion

1. **Delete `OrderRefundUpdateService`** - dead code, writes to non-existent fields
2. **Remove partial refund from Stripe module** - simplify to full refund only
3. **Keep partial refund in payment-component** - other providers may need it
4. **Simplify `OrderRefund.php`** - use Stripe API data, not dead STRIPE* fields

This aligns with the Smart-Contract Architecture principle of keeping provider-specific logic minimal and delegating to the provider-agnostic component where possible.
