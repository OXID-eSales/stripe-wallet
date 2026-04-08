# Report: Empty Orders Created on Back-Navigation During Stripe Checkout

**Date:** 2026-03-02
**Branch:** b-7.4.x-discounts-in-payment-intentions-STRP-103
**Ticket:** STRP-103

---

## 1. Problem Description

When a user navigates back from the Stripe payment page without completing payment and then resumes checkout, the resulting order in OXID Admin is empty — no products, no user data, no amounts, no payment method.

**Steps to Reproduce:**
1. Add product to cart
2. Proceed to checkout, select Stripe payment
3. Get redirected to Stripe payment page
4. Navigate back (browser back button) without completing payment
5. Resume checkout — click "Place Order" again
6. Complete payment on Stripe
7. Open order in Shop Back End → **order is empty**

**Observed (Order 192):**
- OXTOTALORDERSUM: 0, OXBILLFNAME: empty, OXUSERID: empty
- OXPAYMENTTYPE: empty, OXTRANSSTATUS: OK
- 0 order articles
- But OXTRANSID is set (payment was completed on Stripe)

## 2. Root Cause Analysis

### 2.1 Back-Navigation Creates Multiple Contracts and Orders

Each click of "Place Order" triggers `createCheckoutSession()` which:
1. Creates a new contract (DRAFT)
2. `EarlyOrderCreationHandler` fires → creates order via `OxidShopOrderService::createOrder()`
3. `createOrder()` calls `Order::finalizeOrder($basket, $user)` which uses the session basket

**The session basket is consumed on the first `finalizeOrder()` call:**
- `finalizeOrder()` calls `$oBasket->setOrderId()` marking it as "ordered"
- OXID's `sess_challenge` mechanism may prevent the same basket from being used again
- On subsequent attempts, the basket is empty or stale

### 2.2 Evidence from Order 192 Flow

Three contracts were created within 36 seconds:

| Time | Contract | Order | Order Total | Status |
|------|----------|-------|-------------|--------|
| 11:41:10 | 8c5de5... | 190 | 144.78 EUR | pending (abandoned) |
| 11:41:21 | 42998c... | 191 | 0.00 EUR | pending (abandoned) |
| 11:41:46 | 725035... | 192 | 0.00 EUR | committed (completed) |

- **Order 190**: First attempt, created correctly (basket was still available)
- **Order 191**: Second attempt after back-navigation, empty (basket consumed)
- **Order 192**: Third attempt, empty but committed with payment — this is what the user sees

### 2.3 The `StripeOrderCreationHandler` Doesn't Fix Empty Orders

When payment completes, `StripeOrderCreationHandler::handleExistingOrder()`:
1. Finds the existing order (192) via `$contract->getOrderId()`
2. Updates OXTRANSID with the PaymentIntent ID
3. Dispatches `ContractCommittedEvent`
4. Does NOT re-populate order data (user info, products, amounts)

The handler trusts that the order was correctly created during early order creation — but it wasn't.

### 2.4 Orphaned Orders

Orders 190 and 191 are orphaned — their contracts are stuck in "pending" state with no payment. These orders have consumed order numbers and basket data but will never be completed.

## 3. Impact

- **Data integrity:** Orders visible in admin with completely empty data (no user, no products, no amounts)
- **Financial reporting:** Order totals show 0 EUR for completed payments
- **Order number waste:** Each back-navigation attempt consumes an order number
- **Orphaned contracts/orders:** Pending contracts and orders accumulate
- **Customer confusion:** Successful Stripe payment but empty order in shop

## 4. Proposed Solution

See sprint plan: `sprints/sprint-72-early-order-not-finished-cleanup.md`

## 5. Files Involved

| File | Role |
|------|------|
| `payment-component/.../EarlyOrderCreationHandler.php` | Creates early order via `finalizeOrder()` |
| `stripe/.../OxidShopOrderService.php` | Calls `finalizeOrder()` with session basket |
| `stripe/.../StripeOrderCreationHandler.php` | Handles committed contracts, trusts early order data |
| `stripe/.../StripeOrderController.php:createCheckoutSession()` | Triggers new contract + early order per click |
| `OXID Order::finalizeOrder()` | Consumes session basket, uses `sess_challenge` |
