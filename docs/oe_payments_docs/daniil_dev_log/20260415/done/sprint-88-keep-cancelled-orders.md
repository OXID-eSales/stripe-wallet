# Sprint 88: Keep Cancelled Orders (No Gaps in Order Numbers)

**Date:** 2026-04-15
**Branch:** `b-7.4.x`
**Ticket:** STRP-123

## Core Requirements

| Principle | Application |
|-----------|-------------|
| **TDD-first** | Sprint 88a: write failing tests before any production code |
| **DevOps-first** | Sprint 88c: full pre-commit (PHPCS, PHPStan max, PHPMD, PHPUnit) before done |
| **SOLID / SRP** | `retainNotFinishedOrder()` does one thing — marks storno + resets vouchers. Cleanup service orchestrates. |
| **Liskov** | `retainNotFinishedOrder()` replaces `deleteNotFinishedOrder()` with same contract (orderId in, bool out) — callers don't change behavior |
| **DRY** | Single method for all 4 deletion points — no duplicate storno logic |
| **Clean Code** | Early returns, no else, meaningful method name (`retain` not `softDelete`), voucher reset extracted |
| **No overengineering** | No new interfaces, no new events, no admin UI changes — just swap delete for storno |

## Problem

When a customer cancels Stripe checkout or abandons the session, the early-created `NOT_FINISHED` order is **deleted** from `oxorder`. This creates gaps in the order number sequence (e.g., order 612, 613, ~~614~~, 615). For accounting, tax compliance, and auditability, order numbers must be sequential with no gaps.

## Current Flow (Broken)

```
1. Customer starts checkout → Contract DRAFT → Order #614 created (NOT_FINISHED)
2. Customer redirected to Stripe
3. Customer clicks "Back" or closes browser
4. RetryCleanupService runs:
   - order->delete()           ← ORDER #614 DELETED (gap!)
   - vouchers reset
   - contract → CANCELLED
5. Next customer → Order #615 created
   → Gap: #614 missing from oxorder table
```

## Who Deletes Orders

**4 deletion points** all calling `OxidShopOrderService::deleteNotFinishedOrder()`:

| Trigger | Location | When |
|---------|----------|------|
| **User cancels** | `StripeOrderController::checkoutCancel()` → `RetryCleanupService::cleanupPreviousAttempt()` | User clicks back on Stripe |
| **User retries** | `StripeOrderController::cleanupPreviousCheckoutAttempt()` → `RetryCleanupService::cleanupPreviousAttempt()` | User navigates back and starts new checkout |
| **Stale cleanup** | `WebhookController` → `RetryCleanupService::cleanupStaleContracts(30)` | After every webhook, contracts >30 min old |
| **Payment page** | `PaymentController::cleanupStaleCheckoutAttempt()` → `RetryCleanupService` | User visits payment page after stale checkout |

**The delete method** (`OxidShopOrderService.php` lines 206-242):
```php
public function deleteNotFinishedOrder(string $orderId): bool
{
    $order = oxNew(Order::class);
    if (!$order->load($orderId)) {
        return false;
    }
    $status = $order->getFieldData('oxtransstatus');
    if ($status !== 'NOT_FINISHED') {
        return false;
    }
    $this->resetVouchersForOrder($orderId);
    return (bool) $order->delete();  // ← THIS CAUSES THE GAP
}
```

## What Must Change

**Replace `deleteNotFinishedOrder()` with `retainNotFinishedOrder()`** — keep the order in `oxorder` but ensure it's clearly marked as abandoned/cancelled and doesn't interfere with operations.

### Desired Flow (Fixed)

```
1. Customer starts checkout → Contract DRAFT → Order #614 created (NOT_FINISHED)
2. Customer redirected to Stripe
3. Customer clicks "Back" or closes browser
4. RetryCleanupService runs:
   - order->OXTRANSSTATUS stays 'NOT_FINISHED'  ← KEPT
   - order->OXSTORNO = 1                        ← MARKED AS STORNO
   - vouchers reset                              ← STILL NEEDED (free vouchers for reuse)
   - contract → CANCELLED
5. Next customer → Order #615 created
   → #614 exists in DB as NOT_FINISHED + STORNO
   → No gap in order numbers
```

### Why `OXSTORNO = 1`

OXID's built-in storno mechanism:
- `oxorder.OXSTORNO` (tinyint, 0/1) — standard OXID field for cancelled/reversed orders
- OXID admin shows storno orders with strikethrough
- OXID reporting excludes storno orders from revenue calculations
- No special handling needed in existing OXID admin — it already knows how to display storno orders

### Why NOT Change `OXTRANSSTATUS`

- `NOT_FINISHED` is the correct status — the transaction was genuinely not finished
- Changing it to `OK` would be misleading (payment never completed)
- Changing it to a custom value would break OXID core expectations
- `OXSTORNO = 1` is the OXID-standard way to mark cancelled orders

## Implementation Plan

### What Changes

| File | Change |
|------|--------|
| `src/Stripe/Adapter/OxidShopOrderService.php` | Replace `deleteNotFinishedOrder()` with `retainNotFinishedOrder()` — sets `OXSTORNO=1`, resets vouchers, does NOT delete |
| `src/Stripe/Service/RetryCleanupService.php` | Call `retainNotFinishedOrder()` instead of `deleteNotFinishedOrder()` |
| `payment-component: ShopOrderServiceInterface` | Add `retainNotFinishedOrder()` to interface (or keep both methods, deprecate delete) |

### What Does NOT Change

- Contract state machine (still transitions to CANCELLED)
- Voucher reset (still frees vouchers for reuse)
- Stale cleanup timing (still 30 min)
- Event dispatching (ContractCancelledEvent still fires)
- All 4 cleanup trigger points (same callers, different operation)

### Edge Cases

| Case | Current Behavior | New Behavior |
|------|-----------------|--------------|
| User cancels, starts new checkout | Old order deleted, new order created | Old order STORNO'd, new order created (both visible) |
| User cancels 3 times | 3 orders deleted, 4th succeeds | 3 orders STORNO'd, 4th succeeds (4 orders in DB) |
| Stale cleanup (30 min) | Old orders deleted | Old orders STORNO'd |
| Admin views orders | Gap in numbers | Sees STORNO'd NOT_FINISHED orders (low priority, clearly marked) |
| Revenue reports | Missing orders | STORNO orders excluded by OXID (standard behavior) |
| Order export to ERP | Gap in sequence | Complete sequence, STORNO flag for filtering |

### Voucher Handling

Vouchers MUST still be reset when an order is retained as STORNO:
- The customer should be able to reuse the voucher on the next attempt
- `OXORDERID` is cleared, `OXDATEUSED` is reset, `OXRESERVED` is reset
- The STORNO'd order keeps the order number but releases the voucher

## TDD Plan

### Phase 1: RED — Failing Tests

**Unit: OxidShopOrderServiceTest**
```
Test: testRetainNotFinishedOrderSetsStorno()
  Arrange: Order with OXTRANSSTATUS='NOT_FINISHED'
  Act: retainNotFinishedOrder(orderId)
  Assert: order.OXSTORNO = 1, order NOT deleted from DB

Test: testRetainNotFinishedOrderResetsVouchers()
  Arrange: Order with voucher attached
  Act: retainNotFinishedOrder(orderId)
  Assert: voucher.OXORDERID = '', voucher.OXDATEUSED = NULL

Test: testRetainNotFinishedOrderSkipsNonNotFinishedOrders()
  Arrange: Order with OXTRANSSTATUS='OK'
  Act: retainNotFinishedOrder(orderId)
  Assert: order unchanged, returns false

Test: testDeleteNotFinishedOrderStillWorks()
  (Keep for backward compat — deprecation only)
```

**Unit: RetryCleanupServiceTest**
```
Test: testCleanupRetainsOrderInsteadOfDeleting()
  Arrange: Contract with NOT_FINISHED order
  Act: cleanupPreviousAttempt(contractId)
  Assert: orderService.retainNotFinishedOrder() called (not delete)
```

### Phase 2: GREEN — Implementation

1. Add `retainNotFinishedOrder(string $orderId): bool` to `OxidShopOrderService`
2. Update `RetryCleanupService` to call `retainNotFinishedOrder()` instead of `deleteNotFinishedOrder()`
3. Keep `deleteNotFinishedOrder()` (deprecated, for backward compat)

### Phase 3: REFACTOR

- Pre-commit check
- Verify existing tests pass (cleanup tests may need assertion updates)
- Manual test: cancel checkout, verify order stays in DB with OXSTORNO=1

## Sub-Sprints

| Sprint | Description | Status |
|--------|-------------|--------|
| 88a | RED — Failing tests for retainNotFinishedOrder | todo |
| 88b | GREEN — Implement retainNotFinishedOrder + update RetryCleanupService | todo |
| 88c | REFACTOR — Pre-commit + manual verification | todo |

## Out of Scope

- Admin UI changes for STORNO'd NOT_FINISHED orders (OXID handles this natively)
- Changing the cleanup timing (30 min is fine)
- Automatic order number gap detection/reporting
- payment-component interface changes (keep change in stripe module only)
