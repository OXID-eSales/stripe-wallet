# Sprint 72: Early Order NOT_FINISHED Status + Back-Navigation Cleanup

**Date:** 2026-03-02
**Ticket:** STRP-103
**Branch:** b-7.4.x-discounts-in-payment-intentions-STRP-103
**Report:** `reports/02-empty-orders-on-back-navigation.md`

---

## Problem Summary

When the user navigates back from Stripe payment page and retries, each attempt creates a new contract + early order via `finalizeOrder()`. The first call consumes the session basket; subsequent calls produce empty orders. The final committed order has no data.

## Solution Overview

**Two-part fix:**

1. **Early orders must have NOT_FINISHED OXTRANSSTATUS** and be created properly with basket data
2. **On retry (back-navigation), cancel previous NOT_FINISHED order** before creating a new one

The goal: at any point in time, there is at most ONE active NOT_FINISHED order per session. When payment completes, the order transitions from NOT_FINISHED → OK. When the user abandons, the NOT_FINISHED order is cleaned up.

---

## Architecture

### Current Flow (Broken)

```
User clicks "Place Order"
  → createCheckoutSession() [AJAX]
    → EarlyOrderCreationHandler
      → OxidShopOrderService::createOrder()
        → finalizeOrder(basket) → status OK, basket consumed
    → Contract: DRAFT → NOT_FINISHED → PENDING

User navigates back, clicks again
  → createCheckoutSession() [AJAX]
    → EarlyOrderCreationHandler
      → OxidShopOrderService::createOrder()
        → finalizeOrder(empty basket) → empty order with status OK  ← BUG
    → New contract: DRAFT → NOT_FINISHED → PENDING
    → Old contract: orphaned in PENDING state
```

### Target Flow (Fixed)

```
User clicks "Place Order"
  → createCheckoutSession() [AJAX]
    → EarlyOrderCreationHandler
      → OxidShopOrderService::createOrder()
        → finalizeOrder(basket) → status NOT_FINISHED
      → Store orderId in session
    → Contract: DRAFT → NOT_FINISHED → PENDING

User navigates back, clicks again
  → createCheckoutSession() [AJAX]
    → Check session for existing NOT_FINISHED order
      → Cancel/delete previous NOT_FINISHED order
      → Cancel previous contract
    → EarlyOrderCreationHandler
      → OxidShopOrderService::createOrder()
        → finalizeOrder(basket) → status NOT_FINISHED  ← basket available (previous order cancelled)
      → Store new orderId in session
    → New contract: DRAFT → NOT_FINISHED → PENDING

User completes payment
  → checkoutSuccess()
    → StripeOrderCreationHandler
      → handleExistingOrder() → set OXTRANSSTATUS = OK
    → Contract: COMMITTED
```

---

## Implementation Plan (TDD-First)

### Step 1: Test — Early Order Has NOT_FINISHED Status

**File:** `tests/Unit/EventSystem/Handler/EarlyOrderCreationHandlerTest.php` (in payment-component)

```
TEST: testCreatedOrderHasNotFinishedStatus
  GIVEN a ContractDraftCompletedEvent with valid basket
  WHEN EarlyOrderCreationHandler handles it
  THEN the created order has OXTRANSSTATUS = 'NOT_FINISHED'
```

**Implementation:**
- Modify `OxidShopOrderService::createOrder()` to accept an optional `$initialStatus` parameter
- OR add `setOrderFieldsAfterCreation()` to set status to NOT_FINISHED after `finalizeOrder()`
- Best approach: After `finalizeOrder()` sets status OK (line 537), override with NOT_FINISHED in `setOrderFieldsAfterCreation()`

**File:** `src/Stripe/Adapter/OxidShopOrderService.php`

Add a parameter or method to set the initial order status:

```php
private function setOrderFieldsAfterCreation(Order $order, CreateOrderRequest $request): void
{
    // ... existing code ...

    // STRP-103: Set order status based on request
    if ($request->initialStatus !== null) {
        $order->setOrderStatus($request->initialStatus);
    }

    $order->save();
}
```

**File:** `payment-component/.../Request/CreateOrderRequest.php`

Add optional `initialStatus` field:

```php
public function __construct(
    // ... existing params ...
    public readonly ?string $initialStatus = null,
)
```

**File:** `payment-component/.../Handler/EarlyOrderCreationHandler.php`

Pass `initialStatus: 'NOT_FINISHED'` in CreateOrderRequest.

---

### Step 2: Test — StripeOrderCreationHandler Sets Status OK on Committed Order

**File:** `tests/Unit/Stripe/EventSystem/Handler/StripeOrderCreationHandlerTest.php`

```
TEST: testHandleExistingOrderSetsStatusOK
  GIVEN a contract with existing orderId (early creation)
  WHEN StripeOrderCreationHandler handles ContractReadyToCommitEvent
  THEN the order's OXTRANSSTATUS is updated to 'OK'
```

**Implementation:**
- In `StripeOrderCreationHandler::handleExistingOrder()`, after updating OXTRANSID, also set:
  ```php
  $order->setOrderStatus('OK');
  ```

---

### Step 3: Test — Previous NOT_FINISHED Order Is Cancelled on Retry

**File:** `tests/Unit/Stripe/Controller/StripeOrderControllerTest.php` (or a new test file)

```
TEST: testCreateCheckoutSessionCancelsPreviousNotFinishedOrder
  GIVEN session has a previous orderId with OXTRANSSTATUS = 'NOT_FINISHED'
  AND session has a previous contractId in PENDING state
  WHEN createCheckoutSession() is called
  THEN the previous order is deleted (or storno'd)
  AND the previous contract is cancelled
  AND a new contract + order is created
```

**Implementation approach — add cleanup at the start of `createCheckoutSession()`:**

```php
public function createCheckoutSession(): void
{
    $helper = $this->getRequestHelper();

    // STRP-103: Clean up previous NOT_FINISHED order if exists
    $this->cleanupPreviousCheckoutAttempt($helper);

    // ... existing code ...
}

private function cleanupPreviousCheckoutAttempt(ControllerRequestHelper $helper): void
{
    $previousContractId = $helper->getContractIdFromSession();
    if ($previousContractId === null) {
        return;
    }

    // Cancel previous contract (transitions to CANCELLED state)
    // Delete/storno previous NOT_FINISHED order
    $context = new EventContext([
        'contractId' => $previousContractId,
        'reason' => 'checkout_retry',
    ]);
    $event = new StripeCheckoutCleanupEvent($context);
    $this->getEventDispatcher()->dispatch($event);

    // Clear session variables
    $helper->clearStripeSessionVariables();
}
```

**New event + handler:**

**Event:** `StripeCheckoutCleanupEvent` — dispatched when user retries checkout

**Handler:** `StripeCheckoutCleanupHandler`:
1. Load contract by ID
2. If contract is in PENDING or NOT_FINISHED state → cancel it
3. If contract has an orderId → load order
4. If order OXTRANSSTATUS = 'NOT_FINISHED' → delete order (or mark as storno)
5. Save contract as CANCELLED

---

### Step 4: Test — Order Data Is Correct When Payment Completes

**File:** `tests/Integration/Stripe/Controller/StripeOrderControllerTest.php`

```
TEST: testBackNavigationAndRetryProducesCorrectOrder
  GIVEN user created first checkout session (contract A, order A with NOT_FINISHED)
  WHEN user navigates back and creates second checkout session
  THEN order A is deleted, contract A is cancelled
  AND new contract B + order B with NOT_FINISHED is created
  WHEN payment completes for contract B
  THEN order B has OXTRANSSTATUS = 'OK'
  AND order B has correct product data, user info, amounts
```

---

### Step 5: Test — Session Basket Is Preserved Across Retry

**File:** `tests/Unit/Stripe/Adapter/OxidShopOrderServiceTest.php`

```
TEST: testFinalizeOrderWithNotFinishedStatusPreservesBasket
  GIVEN a basket with products
  WHEN createOrder is called with initialStatus = 'NOT_FINISHED'
  THEN order is created with correct product data
  AND OXTRANSSTATUS = 'NOT_FINISHED'
```

The key question: does `finalizeOrder()` consume the basket even for NOT_FINISHED orders?

Looking at `Order::finalizeOrder()`:
- Line 540: `$oBasket->setOrderId($this->getId())` — marks basket as having an order
- Line 551: `$this->markVouchers($oBasket, $oUser)` — marks vouchers as used
- The basket itself is NOT deleted (that happens in ThankYouController)

So the basket should still be available after `finalizeOrder()` for the cleanup + retry flow, BUT:
- `sess_challenge` was consumed → `checkOrderExist()` will return true for same challenge
- Need to ensure a NEW `sess_challenge` is generated for the retry

**Implementation:** Before creating a new order on retry, generate a fresh `sess_challenge`:
```php
Registry::getSession()->setVariable('sess_challenge', Registry::getUtilsObject()->generateUId());
```

---

### Step 6: Test — Abandoned NOT_FINISHED Orders Are Cleaned Up

**File:** `tests/Unit/Stripe/Service/AbandonedOrderCleanupServiceTest.php`

```
TEST: testCleanupDeletesOldNotFinishedOrders
  GIVEN orders with OXTRANSSTATUS = 'NOT_FINISHED' older than 24 hours
  WHEN cleanup service runs
  THEN those orders are deleted
  AND their contracts are cancelled
```

**This is a separate background task (cron/command)** — not part of the checkout flow but important for long-term data hygiene.

**Implementation:** OXID console command `oe:stripe:cleanup-abandoned-orders`

---

## File Changes Summary

| File | Change | Package |
|------|--------|---------|
| `CreateOrderRequest.php` | Add `?string $initialStatus = null` | payment-component |
| `OxidShopOrderService.php` | Use `initialStatus` to override order status | stripe |
| `EarlyOrderCreationHandler.php` | Pass `initialStatus: 'NOT_FINISHED'` | payment-component |
| `StripeOrderCreationHandler.php` | Set `OXTRANSSTATUS = 'OK'` on commit | stripe |
| `StripeOrderController.php` | Add `cleanupPreviousCheckoutAttempt()` | stripe |
| `StripeCheckoutCleanupEvent.php` | New event class | stripe |
| `StripeCheckoutCleanupHandler.php` | New handler: cancel contract + delete order | stripe |
| `ControllerRequestHelper.php` | Add `generateNewSessionChallenge()` method | stripe |

**New test files:**
| Test File | Coverage |
|-----------|----------|
| `EarlyOrderCreationHandlerTest.php` | NOT_FINISHED status |
| `StripeOrderCreationHandlerTest.php` | Status OK on commit |
| `StripeCheckoutCleanupHandlerTest.php` | Cleanup logic |
| `StripeOrderControllerTest.php` | Back-navigation retry flow |
| `OxidShopOrderServiceTest.php` | initialStatus parameter |

---

## Execution Order

1. **Step 1** — NOT_FINISHED status on early order (foundation)
2. **Step 2** — Status OK on commit (pairs with Step 1)
3. **Step 3** — Cleanup on retry (requires Steps 1–2)
4. **Step 5** — Basket preservation verification
5. **Step 4** — Integration test for full flow
6. **Step 6** — Background cleanup command (can be deferred)

---

## Risks and Mitigations

| Risk | Mitigation |
|------|------------|
| `finalizeOrder()` side effects (vouchers, stock) on NOT_FINISHED orders | Review which side effects to skip; may need to call `finalizeOrder()` with `recalculating=true` or handle differently |
| Race condition: payment webhook arrives while cleanup runs | Check payment status before deleting order; only delete if no payment |
| `sess_challenge` consumed by first attempt | Generate new `sess_challenge` before retry |
| Basket articles may change between attempts | Accept this as normal — user may have modified basket |

## Open Questions

1. Should NOT_FINISHED orders be **deleted** or **storno'd** (OXSTORNO=1)?
   - Delete: cleaner, no ghost orders in admin
   - Storno: audit trail preserved
   - **Recommendation:** Delete — these are incomplete orders that never had a payment

2. Should the cleanup event be synchronous (in the controller) or dispatched asynchronously?
   - **Recommendation:** Synchronous — must complete before new order is created

3. Should `finalizeOrder()` be used at all for early order creation, or should we create orders directly?
   - `finalizeOrder()` handles article stock, vouchers, payment validation, email sending
   - For NOT_FINISHED orders, we may want to skip email sending
   - **Recommendation:** Use `finalizeOrder()` but override status afterward; investigate skipping email for NOT_FINISHED
