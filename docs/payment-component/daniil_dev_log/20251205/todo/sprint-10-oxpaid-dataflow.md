# Sprint 10: OXPAID Data Flow Analysis

**Date:** 2025-12-05
**Status:** IMPLEMENTED
**Branch:** b-7.4.x-auth-STRP-70

---

## Executive Summary

Analysis of why `OXPAID` is not populated during frontend checkout flow. The issue is **by design**: OXPAID is only set via webhook, not during the frontend return flow.

---

## Problem Statement

**Symptom:** Orders created via Stripe Checkout have:
- `OXTRANSSTATUS = 'OK'`
- `OXTRANSID = pi_xxx`
- `OXPAID = '0000-00-00 00:00:00'` (not set!)

**Expected:** `OXPAID` should be set to current timestamp when payment is confirmed.

---

## Data Flow Analysis

### Current Architecture

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                           CHECKOUT DATA FLOW                                │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                             │
│  FRONTEND FLOW (User Returns)           WEBHOOK FLOW (Stripe Server)        │
│  ═══════════════════════════            ════════════════════════════        │
│                                                                             │
│  1. User completes Stripe payment       1. Stripe sends webhook             │
│  2. User returns to shop                2. payment_intent.succeeded         │
│  3. StripeCheckoutReturnEvent           3. WebhookController                │
│  4. PaymentAuthorizedEvent              4. WebhookProcessingService         │
│  5. ContractReadyToCommitEvent          5. WebhookContractFulfillmentHandler│
│  6. StripeOrderCreationHandler          6. Contract → FULFILLED             │
│  7. Order::finalizeOrder()              7. updateOrderPaidTimestamp()       │
│  8. Contract → COMMITTED                8. **OXPAID = NOW()**               │
│                                                                             │
│  ┌────────────────────┐                 ┌────────────────────┐              │
│  │ Order Created      │                 │ OXPAID Updated     │              │
│  │ OXPAID = '0000...' │ ─ ─ ─ ─ ─ ─ ─ →│ OXPAID = NOW()     │              │
│  │ OXTRANSSTATUS = OK │  (via webhook)  │                    │              │
│  └────────────────────┘                 └────────────────────┘              │
│                                                                             │
└─────────────────────────────────────────────────────────────────────────────┘
```

### Why OXPAID is Not Set in Frontend Flow

The frontend flow only creates the order and commits the contract. It does **NOT** set OXPAID because:

1. **Stripe Payment Lifecycle:**
   - `checkout.session.completed` = session finished (user returned)
   - `payment_intent.succeeded` = money actually captured
   - These are different events with different timing

2. **Intent vs Capture:**
   - User returning to shop = "intent" confirmed
   - Webhook = "actual capture" confirmed
   - OXPAID should only be set when money is actually captured

3. **Current Design Decision:**
   - Frontend flow: Order created, contract COMMITTED
   - Webhook: Contract FULFILLED, OXPAID updated
   - This is **correct** for most payment scenarios

---

## The Real Problem

The issue is not that OXPAID isn't set in frontend flow - that's by design.

The **real problem** is when webhooks fail or are delayed:

| Scenario | Result |
|----------|--------|
| Webhook arrives quickly | OXPAID set within seconds |
| Webhook delayed (1-60s) | User sees "unpaid" order temporarily |
| Webhook fails | OXPAID never set |
| Webhook endpoint down | All orders show unpaid |

---

## Current Code Locations

### Where OXPAID IS Updated (Correct)

```php
// src/Stripe/Service/WebhookProcessingService.php:647
$sql = "UPDATE oxorder SET OXPAID = NOW() WHERE OXID = ?";
```

Called from:
- `updateOrderFieldsAfterContractFulfillment()` - line 286
- `handleChargeCaptured()` - line 421
- `handleCheckoutSessionCompleted()` - line 562

### Where OXPAID is NOT Updated (By Design)

```php
// src/Stripe/Adapter/OxidShopOrderService.php:createOrder()
// Does NOT set OXPAID - this is correct!
// Order is created but payment not yet confirmed
```

---

## Recommendations

### Option A: Keep Current Design (Recommended)

**Pros:**
- Architecturally correct (payment_intent.succeeded = actual capture)
- Works for all payment scenarios (auth+capture, direct capture)
- Webhook is authoritative source

**Cons:**
- Brief window where order shows "unpaid"
- Depends on webhook reliability

**Mitigation:**
- Add cron job to check/fix unpaid orders with completed Stripe payments
- Add UI indicator "Payment processing..." instead of showing OXPAID status

### Option B: Set OXPAID in Frontend Flow

**Change:** Set OXPAID in `StripeOrderCreationHandler` when creating order.

```php
// In StripeOrderCreationHandler::handle()
$this->updateOrderPaidTimestamp($orderId);
```

**Pros:**
- Immediate OXPAID update
- No dependency on webhook

**Cons:**
- May mark order as "paid" before actual capture (for auth+capture flows)
- Webhook would need to handle duplicate updates (idempotency)
- Inconsistent with OXID's OXPAID semantics

### Option C: Hybrid Approach

**Change:** Set OXPAID in frontend flow ONLY for direct capture payments.

```php
// In StripeOrderCreationHandler::handle()
if ($event->getPaymentStatus() === 'complete') {
    $this->updateOrderPaidTimestamp($orderId);
}
```

**Pros:**
- Fast update for simple payments
- Correct semantics for auth+capture

**Cons:**
- More complex logic
- Need to determine payment type

---

## Current Webhook Call Chain

```
WebhookController::render()
  └→ WebhookProcessingService::processEvent()
       └→ handlePaymentIntentSucceeded() / handleChargeCaptured() / handleCheckoutSessionCompleted()
            └→ WebhookContractFulfillmentHandler::handlePaymentSucceeded()
                 └→ Contract::fulfill()
            └→ updateOrderFieldsAfterContractFulfillment()
                 └→ updateOrderPaidTimestamp()
                      └→ UPDATE oxorder SET OXPAID = NOW()
```

---

## Test Verification

### E2E Test to Verify

```typescript
// tests/e2e/playwright/tests/admin/payment-date-validation.spec.ts
test('paid orders have OXPAID set', async () => {
    // Create order via checkout
    // Wait for webhook processing
    // Verify OXPAID is not '0000-00-00 00:00:00'
});
```

### Integration Test

```php
// tests/Integration/Stripe/Webhook/OxpaidWebhookUpdateTest.php
public function paymentIntentSucceededUpdatesOxpaidViaContract(): void
{
    // Create contract + order
    // Process webhook
    // Assert OXPAID updated
}
```

---

## Conclusion

**OXPAID not being set in frontend flow is BY DESIGN.**

The current architecture is correct:
1. Frontend flow creates order and commits contract
2. Webhook confirms payment and sets OXPAID
3. This ensures OXPAID reflects actual payment capture, not just payment intent

**Recommendation:** Keep current design. Add monitoring/retry for webhook failures.

---

## Sprint 10.1: Webhook Request Logging (IMPLEMENTED)

**Problem:** Webhooks from Stripe may be getting lost - need visibility into all incoming requests.

**Solution Implemented:** Raw HTTP request logging added to `WebhookController`

### Log File Location
```
source/log/osc/stripe_webhooks.log
```

### What Gets Logged

**On every incoming request (WEBHOOK_RECEIVED):**
- Timestamp with microseconds
- Request ID (for correlation)
- Event ID (`evt_xxx`)
- Event Type (`payment_intent.succeeded`, etc.)
- Payment Intent ID (`pi_xxx`)
- Remote IP
- User Agent
- Payload size
- Signature presence
- Truncated signature

**On processing complete (WEBHOOK_RESULT):**
- SUCCESS (HTTP 200)
- SIGNATURE_FAILED (HTTP 400)
- PROCESSING_FAILED (HTTP 500)

### Sample Log Output
```
[2025-12-05 14:30:45.123456] [a1b2c3d4] WEBHOOK_RECEIVED
  Event ID:      evt_1234567890
  Event Type:    payment_intent.succeeded
  Payment ID:    pi_abcdef123456
  Remote IP:     54.187.174.169
  User Agent:    Stripe/1.0 (+https://stripe.com/docs/webhooks)
  Payload Size:  2456 bytes
  Has Signature: YES
  Signature:     t=1701789045,v1=abc123def456...
  ---
[2025-12-05 14:30:45.234567] WEBHOOK_RESULT: SUCCESS (HTTP 200) - Event: payment_intent.succeeded [evt_1234567890]
```

### Files Changed
- `src/Stripe/Controller/Webhook/WebhookController.php`
  - Added `WEBHOOK_LOG_FILE` constant
  - Added `logRawWebhookRequest()` method
  - Added `logWebhookResult()` method

---

## Sprint 10.2: OXPAID Reconciliation Command (IMPLEMENTED)

**Problem:** When webhooks fail or are delayed, OXPAID remains '0000-00-00 00:00:00' even though Stripe has captured the payment.

**Solution Implemented:** Console command to reconcile OXPAID with Stripe API.

### Usage

```bash
# Check and fix all unpaid orders from the last 7 days
bin/oe-console stripe:reconcile-oxpaid

# Dry run (show what would be done without making changes)
bin/oe-console stripe:reconcile-oxpaid --dry-run

# Check orders from the last 14 days
bin/oe-console stripe:reconcile-oxpaid --max-age=14
```

### How It Works

1. **Find unpaid orders**: Query orders where:
   - `OXPAID = '0000-00-00 00:00:00'` (not paid)
   - `OXTRANSID LIKE 'pi_%'` (Stripe PaymentIntent)
   - Order created within specified days

2. **Check Stripe API**: For each order, call `getPaymentDetails()` via StripeAdapter

3. **Update if captured**: If Stripe shows payment is captured:
   - Update `OXPAID` timestamp on the order
   - Transition contract to `FULFILLED` state (if exists)

### Log File
```
source/log/osc/stripe_reconciliation.log
```

### Sample Output
```
Stripe OXPAID Reconciliation
============================

Checking orders from the last 7 days...
Found 3 unpaid order(s) to check.

+---------------+----------------------+---------+----------+------------------------------------------+
| Order ID      | PaymentIntent        | Status  | Contract | Reason                                   |
+---------------+----------------------+---------+----------+------------------------------------------+
| abc123def4... | pi_3ABC123DEF456789  | UPDATED | Yes      | OXPAID updated from Stripe payment status|
| xyz789abc1... | pi_3XYZ789ABC123456  | SKIPPED | No       | Payment not captured. Status: requires_ca|
| def456ghi7... | pi_3DEF456GHI789012  | ERROR   | No       | API error: No such payment_intent        |
+---------------+----------------------+---------+----------+------------------------------------------+

Summary
-------
Orders checked: 3
Updated: 1
Skipped: 1
Errors: 1
```

### Cron Setup

Add to crontab to run every hour:
```cron
0 * * * * cd /var/www && bin/oe-console stripe:reconcile-oxpaid --max-age=1 >> /var/log/stripe-reconcile.log 2>&1
```

### Files Created
- `src/Stripe/Command/ReconcileOxpaidCommand.php` - Console command
- `src/Stripe/Service/OxpaidReconciliationService.php` - Business logic
- `src/Stripe/Service/ReconciliationResult.php` - Result DTO
- `services.yaml` - Service registration

---

## Related Diagrams

- `puml/01-checkout-data-flow-analysis.puml` - Full data flow with OXPAID locations
- `puml/02-parallel-workflow-comparison.puml` - Frontend vs Webhook timing
- `puml/03-contract-state-machine.puml` - Contract state transitions
