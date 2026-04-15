# Sprint 88 Completion Report: Keep Cancelled Orders (No Gaps in Order Numbers)

**Date:** 2026-04-15
**Branch:** `b-7.4.x`
**Ticket:** STRP-123
**Status:** DONE — verified with live orders 625-629

## Problem

When a customer cancels Stripe checkout, the early-created `NOT_FINISHED` order was deleted from `oxorder`, creating gaps in the order number sequence.

## Root Cause (Two-Part)

### Part 1: Order deletion
`OxidShopOrderService::deleteNotFinishedOrder()` called `$order->delete()`, removing the row entirely.

### Part 2: `sess_challenge` collision (discovered during fix)
When the order is retained (storno'd) instead of deleted, OXID's `checkOrderExist()` finds the old order via `sess_challenge` session variable. `finalizeOrder()` returns `ORDER_STATE_ORDEREXISTS` immediately — **skipping all data copying** (user, basket, totals). The new order is saved as an empty shell.

## Fix (3 Changes)

### 1. `OxidShopOrderService::deleteNotFinishedOrder()` — storno instead of delete
```php
// Before:
return (bool) $order->delete();

// After:
$order->oxorder__oxstorno = new Field(1);
$order->oxorder__oxtransstatus = new Field('CANCELLED');
$order->save();
return true;
```

### 2. `StripeOrderController::checkoutCancel()` — regenerate sess_challenge
```php
// Added after clearStripeSessionVariables():
$helper->setSessionVariable('sess_challenge', $this->generateNewSessChallenge());
```

### 3. `StripeOrderController::cleanupStaleCheckoutOnRender()` — same fix
```php
// Added after clearStripeSessionVariables():
$helper->setSessionVariable('sess_challenge', $this->generateNewSessChallenge());
```

## Verification

Live test on `daniil.oxiddev.de` with orders 625-629:

| Order | Status | Storno | Total | User | Scenario |
|-------|--------|--------|-------|------|----------|
| 625 | CANCELLED | 1 | 842.97 | Marc | Cancel #1 — full data preserved |
| 626 | CANCELLED | 1 | 842.97 | Marc | Cancel #2 — full data preserved |
| 627 | OK | 0 | 842.97 | Marc | Completed successfully |
| 628 | CANCELLED | 1 | 547.10 | Marc | Cancel with different basket — data preserved |
| 629 | OK | 0 | 547.10 | Marc | Completed successfully |

- No empty orders
- No gaps in order numbers
- All cancelled orders have full basket data, user info, and totals
- OXSTORNO=1 marks them for OXID admin (strikethrough display)
- OXTRANSSTATUS=CANCELLED distinguishes from legitimate NOT_FINISHED orders
- Vouchers still reset for reuse

## Files Changed

| File | Change |
|------|--------|
| `src/Stripe/Adapter/OxidShopOrderService.php` | `deleteNotFinishedOrder()`: storno + CANCELLED instead of delete |
| `src/Stripe/Controller/StripeOrderController.php` | `checkoutCancel()` + `cleanupStaleCheckoutOnRender()`: regenerate `sess_challenge` |
| `tests/Unit/Stripe/Controller/StripeOrderControllerRetryTest.php` | Updated assertions for new `sess_challenge` |
| `tests/Integration/Stripe/Service/StaleOrderCleanupIntegrationTest.php` | Assert STORNO + CANCELLED instead of deleted |

## Pre-commit
All checks passed — COMMITABLE
