# BUG: Order Totals Show 0.00 After Refund

**Date:** 2026-02-26
**Severity:** HIGH
**Status:** FIXED (Option A implemented)

## Symptom

After a full refund is processed via the Stripe admin panel, the `order.info` block in OXID admin displays all values as `0,00 EUR`:

- Product Gross Price: 0,00
- Discount: 0,00
- Product Net Price: 0,00
- Shipping Costs: 0,00
- Charge Payment Method: 0,00
- Sum total: 0,00

## Root Cause

The bug is caused by `OxidStockRestorationService::restoreStockForOrder()` which:

1. **Marks ALL order articles as `oxstorno = 1`** (line 105-108)
2. **Then calls `$order->recalculateOrder()`** (line 68)

The OXID core `Order::recalculateOrder()` (Order.php:1357) rebuilds order totals from a virtual basket:

```
recalculateOrder()
  → getOrderArticles(true)     // true = exclude cancelled (storno'd)
    → getArticles(true)         // SQL: WHERE oxstorno != 1
    → returns EMPTY list        // all articles are storno'd!
  → calculateBasket(true)       // basket with 0 articles → all prices = 0
  → finalizeOrder($oBasket)
    → loadFromBasket()           // overwrites DB fields with zeros
```

**The critical sequence:**

| Step | Method | Effect |
|------|--------|--------|
| 1 | `processOrderArticle()` | Sets `oxstorno = 1` on each article via SQL |
| 2 | `$order->recalculateOrder()` | Called after all articles are storno'd |
| 3 | `getOrderArticles(true)` | Returns **empty list** (all storno'd) |
| 4 | `calculateBasket(true)` | Calculates totals from empty basket = **all zeros** |
| 5 | `loadFromBasket()` | **Overwrites** `oxtotalordersum`, `oxtotalnetsum`, `oxtotalbrutsum`, `oxdelcost`, `oxpaycost` with 0 |

## Call Chain

```
OrderRefund::render()
  → OrderActionDispatcher::dispatchRefund()
    → StripeRefundRequestHandler::handle()
      → RefundService::processFullRefund()
        → RefundService::handleRefundResponse()
          → OxidStockRestorationService::restoreStockForOrder()   ← BUG HERE
            → processOrderArticle() × N  (marks all as storno)
            → $order->recalculateOrder() (zeroes out totals)
```

## Affected Files

| File | Role |
|------|------|
| `src/Stripe/Service/OxidStockRestorationService.php:47-76` | Marks articles storno + calls recalculateOrder |
| `src/Stripe/Service/OxidStockRestorationService.php:104-108` | SQL UPDATE setting oxstorno = 1 |
| `src/Stripe/Service/RefundService.php:168-169` | Triggers stock restoration after successful refund |
| OXID `Order.php:1357-1386` | `recalculateOrder()` rebuilds from basket excluding storno'd |
| OXID `Order.php:315-330` | `getArticles(true)` filters `oxstorno != 1` |
| OXID `Order.php:631-646` | `loadFromBasket()` overwrites totals from (empty) basket |

## Why Storno Is Wrong for Refunds

The OXID `storno` mechanism was designed for **order cancellation** (removing items from an order), not for **refunds**. The key difference:

- **Cancellation (storno):** Item is removed from the order → totals should decrease
- **Refund:** Money is returned but the order record should remain intact for accounting

After a refund, the admin should still show the original order amounts so that:
- The refund amount can be verified against the original total
- Accounting/bookkeeping records remain accurate
- The order history is auditable

## Proposed Fix

**Option A (Minimal - Remove `recalculateOrder` call):**

Remove the `recalculateOrder()` call from `OxidStockRestorationService::restoreStockForOrder()`. Stock restoration (storno flag + article stock update) is still valid, but order totals should not be recalculated.

```php
// Line 66-68: Remove recalculateOrder call
if ($processedCount > 0) {
-   $order->recalculateOrder();
    $this->logger->info('Stock restored for order', [
```

**Risk:** Low. The storno flag already prevents double-restore. The order totals remain as originally calculated.

**Option B (Structural - Separate stock restore from storno):**

Create a dedicated stock restoration that only updates `oxarticles.oxstock` without setting `oxstorno = 1` on order articles. This preserves the order structure completely.

**Option C (Preserve totals before recalculation):**

Save order totals before `recalculateOrder()` and restore them after. This is a workaround, not a proper fix.

**Recommended: Option A** — simplest, lowest risk, preserves order integrity. The `recalculateOrder()` call was likely added by analogy with the OXID admin storno action, where it makes sense (partial cancellation), but not for full refund.

## Implementation (Option A)

**File changed:** `src/Stripe/Service/OxidStockRestorationService.php`

**Change:** Removed `$order->recalculateOrder()` call from `restoreStockForOrder()` method (line 68). Added explanatory comment documenting WHY recalculateOrder must not be called after marking all articles as storno.

**What remains unchanged:**
- Stock restoration logic (updateArticleStock) — still works
- Storno marking (oxstorno = 1) — still prevents double-restore
- Logging — still logs processed article count

**Diff:**
```diff
-        // Recalculate order after all articles processed
+        // Log stock restoration results.
+        // NOTE: Do NOT call $order->recalculateOrder() here.
+        // recalculateOrder() rebuilds totals from a virtual basket excluding storno'd articles.
+        // Since all articles are now storno'd, the basket would be empty and all totals
+        // would be overwritten with zeros (oxtotalordersum, oxtotalbrutsum, etc.).
+        // Order totals must remain intact after refund for accounting/auditing purposes.
         if ($processedCount > 0) {
-            $order->recalculateOrder();
             $this->logger->info('Stock restored for order', [
```

## Verification

- Unit tests: 714 passed, 1707 assertions (full suite)
- StockRestorationServiceTest: 7 passed
- RefundServiceTest: 33 passed
- Manual test: Order totals display correctly after refund (confirmed by user)
