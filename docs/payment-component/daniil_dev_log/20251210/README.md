# Sprint Analysis: Order.php::finalizeOrder() Multiple Call Points Problem

**Date:** 2025-12-10
**Status:** Analysis / Problem Definition
**Priority:** High

## Executive Summary

The OXID core `Order::finalizeOrder()` method is called from multiple entry points in the codebase, creating complexity for payment module integration. This analysis documents the problem, identifies all call points, and proposes solutions aligned with the smart-contract architecture.

---

## 1. Problem Statement

### The Core Issue

`Order::finalizeOrder()` in `/source/source/Application/Model/Order.php:471` is a **monolithic method** that:
1. Creates the order record
2. Validates basket/user/delivery
3. Executes payment gateway
4. Sets order number
5. Marks vouchers as used
6. Sends confirmation emails

**Problem:** This method is called from **multiple places** in the codebase, each with different expectations and contexts:

```
┌─────────────────────────────────────────────────────────────────┐
│                    finalizeOrder() Call Points                  │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  1. OXID Core OrderController::execute()                        │
│     └─ Standard checkout flow                                   │
│                                                                 │
│  2. OXID Core Order::recalculateOrder()                         │
│     └─ Admin order recalculation (skips payment/email)          │
│                                                                 │
│  3. Stripe OxidShopOrderService::createOrder()                  │
│     └─ Payment module order creation                            │
│                                                                 │
│  4. Other payment modules (PayPal, etc.)                        │
│     └─ Various payment integrations                             │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

### Why This Is Problematic

1. **Multiple Order Creation Paths**
   - Standard checkout: `OrderController -> finalizeOrder()`
   - Stripe checkout: `StripeCheckoutReturnHandler -> PaymentAuthorizedEvent -> StripeOrderCreationHandler -> OxidShopOrderService -> finalizeOrder()`
   - Stripe Payment Element: `StripePaymentExecuteEvent -> ... -> finalizeOrder()`

2. **State Management Complexity**
   - Session state (`sess_challenge`, address hashes) must be correct
   - Basket must exist and be valid
   - User must be logged in

3. **Payment Execution Paradox**
   - `finalizeOrder()` calls `executePayment()` internally
   - For Stripe, payment is **already processed** before order creation
   - We override `executePayment()` in `Stripe\Model\Order` to return `true`

4. **Idempotency Concerns**
   - What happens if `finalizeOrder()` is called twice?
   - Order exists check uses `sess_challenge` session variable
   - Race conditions with webhooks

---

## 2. Analysis of Call Points

### 2.1 OXID Core: OrderController::execute()

**File:** `/source/source/Application/Controller/OrderController.php:239`

```php
public function execute()
{
    // ... validation ...
    $order = oxNew(Order::class);
    $success = $order->finalizeOrder($basket, $user);
    // ... post-processing ...
}
```

**Context:**
- Standard form submission checkout
- POST request with `sDeliveryAddressMD5` parameter
- Session contains `sess_challenge` order ID

### 2.2 OXID Core: Order::recalculateOrder()

**File:** `/source/source/Application/Model/Order.php:1373`

```php
public function recalculateOrder($aNewArticles = [])
{
    // ... setup basket ...
    $iRet = $this->finalizeOrder($oBasket, $this->getOrderUser(), true);
    // $blRecalculatingOrder = true
}
```

**Context:**
- Admin backend order recalculation
- Third parameter `$blRecalculatingOrder = true` changes behavior:
  - Skips payment execution
  - Skips voucher marking
  - Skips email sending

### 2.3 Stripe Module: OxidShopOrderService::createOrder()

**File:** `/extensions/stripe/src/Stripe/Adapter/OxidShopOrderService.php:89`

```php
public function createOrder(CreateOrderRequest $request): OrderResponse
{
    // ... validation ...
    $order = oxNew(Order::class);
    $orderState = $order->finalizeOrder($basket, $user, false);
    // ... post-processing ...
}
```

**Context:**
- Called from `StripeOrderCreationHandler` when contract is ready to commit
- Payment already processed on Stripe
- `Stripe\Model\Order::executePayment()` returns `true` for Stripe payments

### 2.4 Stripe Model: Order Extension

**File:** `/extensions/stripe/src/Stripe/Model/Order.php:77`

```php
protected function executePayment($oBasket, $oUserpayment)
{
    $paymentId = $oBasket->getPaymentId();

    if (strpos($paymentId, 'osc_stripe_') === 0) {
        // Stripe payment - skip standard OXID payment execution
        return true;
    }

    return parent::executePayment($oBasket, $oUserpayment);
}
```

**Purpose:** Bypasses OXID payment gateway for Stripe payments (payment already processed via Stripe SDK).

---

## 3. The Real Problem: Event Flow Complexity

### Current Stripe Checkout Flow

```
User clicks "Pay" on Stripe Hosted Checkout
                    │
                    ▼
    Stripe processes payment (external)
                    │
                    ▼
    User redirected to success URL
                    │
                    ▼
    StripeOrderController::checkoutSuccess()
                    │
                    ▼
    StripeCheckoutReturnHandler::handle()
                    │
                    ├─► Validates session/contract
                    │
                    └─► Dispatches PaymentAuthorizedEvent
                                    │
                                    ▼
                    PaymentAuthorizedEventHandler::handle()
                                    │
                                    └─► Contract transitions to READY_TO_COMMIT
                                    │
                                    └─► Dispatches ContractReadyToCommitEvent
                                                        │
                                                        ▼
                                    StripeOrderCreationHandler::handle()
                                                        │
                                                        └─► OxidShopOrderService::createOrder()
                                                                        │
                                                                        ▼
                                                        Order::finalizeOrder()
                                                                        │
                                                        ┌───────────────┴───────────────┐
                                                        │                               │
                                              executePayment()                   sendOrderByEmail()
                                              (returns true                      (sends emails)
                                               for Stripe)
```

### The Problems Identified

1. **Payment Executed Twice (Conceptually)**
   - Stripe processes payment externally
   - `finalizeOrder()` still calls `executePayment()` (even if we short-circuit it)

2. **State Reconstruction Required**
   - When returning from Stripe, session might be lost
   - Must restore: basket, user, delivery address hash, order ID (`sess_challenge`)

3. **Webhook Race Condition**
   - Webhook may arrive before user returns
   - Webhook tries to fulfill contract, but order might not exist yet

4. **Multiple Entry Points = Multiple Failure Modes**
   - Each call point has different state expectations
   - Error handling varies between call points

---

## 4. Root Cause Analysis

### finalizeOrder() Is a "God Method"

The method does too much:

```php
public function finalizeOrder($oBasket, $oUser, $blRecalculatingOrder = false)
{
    // 1. Idempotency check
    if ($this->checkOrderExist($orderId)) {
        return self::ORDER_STATE_ORDEREXISTS;
    }

    // 2. Set order ID
    $this->setId($orderId);

    // 3. Validation
    $iOrderState = $this->validateOrder($oBasket, $oUser);

    // 4. Copy user info
    $this->assignUserInformation($oUser);

    // 5. Copy basket info
    $this->loadFromBasket($oBasket);

    // 6. Set payment info
    $oUserPayment = $this->setPayment($oBasket->getPaymentId());

    // 7. Set folder
    $this->setFolder();

    // 8. Set status NOT_FINISHED
    $this->setOrderStatus('NOT_FINISHED');

    // 9. Save to DB
    $this->save();

    // 10. Execute payment (!!)
    $blRet = $this->executePayment($oBasket, $oUserPayment);

    // 11. Set order number
    $this->setNumber();

    // 12. Set status OK
    $this->setOrderStatus('OK');

    // 13. Update basket
    $oBasket->setOrderId($this->getId());

    // 14. Update wish/notice lists
    $this->updateWishlist($oBasket->getContents(), $oUser);
    $this->updateNoticeList($oBasket->getContents(), $oUser);

    // 15. Mark vouchers
    $this->markVouchers($oBasket, $oUser);

    // 16. Send emails
    $iRet = $this->sendOrderByEmail($oUser, $oBasket, $oUserPayment);

    return $iRet;
}
```

**Single Responsibility Principle Violation:** This method handles:
- Order creation
- Payment processing
- Notification sending
- Side effect management (vouchers, wishlists)

---

## 5. Impact on Stripe Module

### Current Mitigations

1. **Order Model Extension**
   - `Stripe\Model\Order::executePayment()` returns `true` for Stripe payments
   - `Stripe\Model\Order::validateDeliveryAddress()` handles session-based hash lookup

2. **Session State Restoration**
   - `StripeCheckoutReturnHandler` restores delivery address hash from contract metadata
   - Contract stores basket snapshot at creation time

3. **Contract-First Architecture**
   - Order is only created when contract reaches `READY_TO_COMMIT` state
   - Contract acts as coordination point between user flow and webhooks

### Remaining Issues

1. **Email Timing**
   - `finalizeOrder()` sends email immediately
   - For async payments, email might go out before payment is confirmed

2. **Webhook-First Scenarios**
   - If webhook arrives before user returns, order might be created twice
   - Current solution: idempotency check via `sess_challenge`

3. **State Dependencies**
   - `finalizeOrder()` expects specific session state
   - Hard to test in isolation

---

## 6. Proposed Solutions

### Solution A: Event-Based Decomposition (Recommended)

**Concept:** Decompose `finalizeOrder()` into discrete events that can be handled independently.

```
┌─────────────────────────────────────────────────────────────────┐
│                    Event-Based Order Flow                       │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  OrderCreationRequestedEvent                                    │
│       │                                                         │
│       ├──► OrderValidationHandler                               │
│       │         │                                               │
│       │         └──► Validates basket/user/delivery             │
│       │                                                         │
│       ├──► OrderPersistenceHandler                              │
│       │         │                                               │
│       │         └──► Creates order record in DB                 │
│       │                                                         │
│       ├──► PaymentExecutionHandler                              │
│       │         │                                               │
│       │         └──► Executes payment (or skips for external)   │
│       │                                                         │
│       ├──► OrderNumberAssignmentHandler                         │
│       │         │                                               │
│       │         └──► Assigns order number                       │
│       │                                                         │
│       └──► OrderNotificationHandler                             │
│                 │                                               │
│                 └──► Sends emails                               │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

**Benefits:**
- Each step can be tested independently
- Payment modules can skip/replace specific handlers
- Better error handling per step

**Drawbacks:**
- Requires core OXID modifications (not viable for module)
- Breaking change for existing modules

### Solution B: Pre/Post Hook Pattern (Pragmatic)

**Concept:** Wrap `finalizeOrder()` calls with pre and post processing.

```php
// In Component layer
class OrderCreationService
{
    public function createOrderWithContract(
        PaymentContractInterface $contract,
        Basket $basket,
        User $user
    ): OrderResponse {
        // 1. Pre-processing
        $this->prepareSessionState($contract);
        $this->validateContract($contract);

        // 2. Delegate to standard OXID flow
        $order = oxNew(Order::class);
        $orderState = $order->finalizeOrder($basket, $user);

        // 3. Post-processing
        $this->linkContractToOrder($contract, $order);
        $this->updatePaymentState($order, $contract);

        return new OrderResponse($order, $orderState);
    }
}
```

**Benefits:**
- Works with existing OXID core
- Contract-aware without modifying core
- Testable service layer

**Drawbacks:**
- Still depends on `finalizeOrder()` internals
- Must maintain session state compatibility

### Solution C: Contract-Only Order Creation (Future)

**Concept:** Order is created purely from contract data, bypassing `finalizeOrder()`.

```php
class ContractBasedOrderService
{
    public function createOrderFromContract(
        PaymentContractInterface $contract
    ): Order {
        // 1. Create order directly from contract snapshot
        $order = oxNew(Order::class);
        $order->setId($this->generateOrderId());

        // 2. Load from basket snapshot
        $basketSnapshot = $contract->getBasketSnapshot();
        $this->populateOrderFromSnapshot($order, $basketSnapshot);

        // 3. Skip payment execution entirely
        // (payment already confirmed via contract)

        // 4. Save and assign number
        $order->save();
        $order->setNumber();

        // 5. Trigger post-order events
        $this->dispatcher->dispatch(new OrderCreatedEvent($order, $contract));

        return $order;
    }
}
```

**Benefits:**
- Complete control over order creation
- No session dependencies
- Idempotent by design

**Drawbacks:**
- Breaks compatibility with modules extending `finalizeOrder()`
- Must replicate all OXID order logic
- Maintenance burden

---

## 7. Recommendation

### Short-Term (Sprint 23-24)

**Continue with Solution B** (current approach):
- `OxidShopOrderService` wraps `finalizeOrder()`
- `Stripe\Model\Order` extension handles payment bypass
- Contract metadata stores session state for restoration

**Improvements:**
1. Add explicit logging at each call point
2. Document session state requirements
3. Add integration tests for all entry points

### Medium-Term (Sprint 25-30)

**Enhance Contract Architecture:**
1. Store complete order data in contract (not just basket snapshot)
2. Make order creation idempotent via contract ID (not session)
3. Add "order creation strategy" pattern for different scenarios

### Long-Term (Future Version)

**Consider OXID Core PR:**
- Propose event-based decomposition of `finalizeOrder()`
- Align with Symfony/modern PHP patterns
- Would benefit entire OXID ecosystem

---

## 8. Related Files

| File | Purpose |
|------|---------|
| `/source/Application/Model/Order.php` | Core order model with `finalizeOrder()` |
| `/source/Application/Controller/OrderController.php` | Core checkout controller |
| `/extensions/stripe/src/Stripe/Model/Order.php` | Stripe order extension |
| `/extensions/stripe/src/Stripe/Adapter/OxidShopOrderService.php` | Order creation service |
| `/extensions/stripe/src/Stripe/EventSystem/Handler/StripeOrderCreationHandler.php` | Contract-based order creation |
| `/extensions/stripe/src/Stripe/EventSystem/Handler/StripeCheckoutReturnHandler.php` | Stripe return handling |

---

## 9. Next Steps

1. [ ] Review PlantUML diagrams in `./puml/` directory
2. [ ] Discuss solution approach with team
3. [ ] Create tickets for identified improvements
4. [ ] Add integration tests for edge cases
5. [ ] Document session state requirements

---

## Appendix: finalizeOrder() Return States

| Constant | Value | Meaning |
|----------|-------|---------|
| `ORDER_STATE_OK` | 1 | Order created successfully |
| `ORDER_STATE_MAILINGERROR` | 0 | Order created, email failed |
| `ORDER_STATE_ORDEREXISTS` | 3 | Order already exists (idempotent) |
| `ORDER_STATE_INVALIDPAYMENT` | 2 | Invalid payment method |
| `ORDER_STATE_INVALIDDELIVERY` | 4 | Invalid delivery method |
| `ORDER_STATE_INVALIDDELADDRESSCHANGED` | 7 | Delivery address changed |
| `ORDER_STATE_BELOWMINPRICE` | 6 | Below minimum order price |
| `ORDER_STATE_PAYMENTERROR` | 8 | Payment execution failed |
| `ORDER_STATE_VOUCHERERROR` | 9 | Voucher processing failed |
