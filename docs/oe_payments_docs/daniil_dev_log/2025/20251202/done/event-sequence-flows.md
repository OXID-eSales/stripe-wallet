# Event Sequence Flows - Stripe Payment Module

**Date:** 2025-12-02
**Author:** Claude Code

---

## Overview

This document describes the event-driven architecture and sequence flows for the Stripe payment module. All payment operations are handled through events, enabling multi-channel support (frontend, admin, webhook, API, MCP).

---

## 1. Normal Checkout Flow (Success)

### Sequence Diagram

```
┌─────────┐     ┌──────────────┐     ┌─────────────┐     ┌───────────────┐     ┌────────┐
│ Customer│     │PaymentController│   │EventDispatcher│   │    Handlers   │     │ Stripe │
└────┬────┘     └──────┬───────┘     └──────┬──────┘     └───────┬───────┘     └───┬────┘
     │                 │                    │                    │                 │
     │ Select Payment  │                    │                    │                 │
     │────────────────>│                    │                    │                 │
     │                 │                    │                    │                 │
     │                 │ Create Contract    │                    │                 │
     │                 │ (ContractService)  │                    │                 │
     │                 │──────────────────> │                    │                 │
     │                 │                    │                    │                 │
     │                 │                    │ StripeCheckout     │                 │
     │                 │                    │ SessionRequestEvent│                 │
     │                 │                    │───────────────────>│                 │
     │                 │                    │                    │                 │
     │                 │                    │                    │ Create Checkout │
     │                 │                    │                    │ Session         │
     │                 │                    │                    │────────────────>│
     │                 │                    │                    │                 │
     │                 │                    │                    │<────────────────│
     │                 │                    │                    │ session_id, url │
     │                 │                    │                    │                 │
     │                 │                    │<───────────────────│                 │
     │                 │                    │ Context updated    │                 │
     │                 │                    │ (checkoutUrl)      │                 │
     │                 │<───────────────────│                    │                 │
     │                 │                    │                    │                 │
     │<────────────────│                    │                    │                 │
     │ Redirect to     │                    │                    │                 │
     │ Stripe Checkout │                    │                    │                 │
     │                 │                    │                    │                 │
     │═══════════════════════════════════════════════════════════════════════════>│
     │                      Customer completes payment on Stripe                   │
     │<═══════════════════════════════════════════════════════════════════════════│
     │                                                                             │
     │ Redirect to success_url                                                     │
     │ (?session_id=xxx&contract_id=xxx&contract_token=xxx)                       │
     │                 │                    │                    │                 │
     │────────────────>│                    │                    │                 │
     │                 │ checkoutSuccess()  │                    │                 │
     │                 │                    │                    │                 │
     │                 │                    │ StripeCheckout     │                 │
     │                 │                    │ ReturnEvent        │                 │
     │                 │                    │───────────────────>│                 │
     │                 │                    │                    │                 │
     │                 │                    │                    │ Validate token  │
     │                 │                    │                    │ Load contract   │
     │                 │                    │                    │ Verify payment  │
     │                 │                    │                    │────────────────>│
     │                 │                    │                    │<────────────────│
     │                 │                    │                    │ PaymentIntent   │
     │                 │                    │                    │ status=succeeded│
     │                 │                    │                    │                 │
     │                 │                    │                    │ Fulfill contract│
     │                 │                    │                    │ Create order    │
     │                 │                    │                    │                 │
     │                 │                    │<───────────────────│                 │
     │                 │                    │ Context: orderId   │                 │
     │                 │<───────────────────│                    │                 │
     │                 │                    │                    │                 │
     │<────────────────│                    │                    │                 │
     │ Thank You Page  │                    │                    │                 │
     │ (Order confirmed)                    │                    │                 │
     └─────────────────────────────────────────────────────────────────────────────┘
```

### Events Fired

| Step | Event | Handler | Description |
|------|-------|---------|-------------|
| 1 | `StripeCheckoutSessionRequestEvent` | `StripeCheckoutSessionHandler` | Creates Stripe Checkout Session |
| 2 | `StripeCheckoutReturnEvent` | `StripeCheckoutReturnHandler` | Validates return, creates order |

### Key Data in EventContext

**StripeCheckoutSessionRequestEvent:**
```php
EventContext([
    'contractId' => 'uuid-xxx',
    'shopUrl' => 'https://shop.example.com/',
    'shopId' => '1',
    // Output:
    'checkoutSessionId' => 'cs_xxx',
    'checkoutUrl' => 'https://checkout.stripe.com/xxx',
])
```

**StripeCheckoutReturnEvent:**
```php
EventContext([
    'sessionId' => 'cs_xxx',
    'contractId' => 'uuid-xxx',
    'contractToken' => 'token_xxx',
    // Output:
    'orderId' => 'oxid-xxx',
    'orderNumber' => '42',
    'paymentStatus' => 'succeeded',
])
```

---

## 2. Canceled Checkout Flow

### Sequence Diagram

```
┌─────────┐     ┌──────────────┐     ┌─────────────┐     ┌───────────────┐     ┌────────┐
│ Customer│     │PaymentController│   │EventDispatcher│   │    Handlers   │     │ Stripe │
└────┬────┘     └──────┬───────┘     └──────┬──────┘     └───────┬───────┘     └───┬────┘
     │                 │                    │                    │                 │
     │ Select Payment  │                    │                    │                 │
     │────────────────>│                    │                    │                 │
     │                 │                    │                    │                 │
     │                 │ Create Contract    │                    │                 │
     │                 │───────────────────>│                    │                 │
     │                 │                    │                    │                 │
     │                 │                    │ StripeCheckout     │                 │
     │                 │                    │ SessionRequestEvent│                 │
     │                 │                    │───────────────────>│                 │
     │                 │                    │                    │────────────────>│
     │                 │                    │                    │<────────────────│
     │                 │                    │<───────────────────│                 │
     │                 │<───────────────────│                    │                 │
     │<────────────────│ Redirect to Stripe │                    │                 │
     │                 │                    │                    │                 │
     │═══════════════════════════════════════════════════════════════════════════>│
     │                      Customer on Stripe Checkout page                       │
     │                                                                             │
     │                      Customer clicks "Back" or closes page                  │
     │<═══════════════════════════════════════════════════════════════════════════│
     │                                                                             │
     │ Redirect to cancel_url                                                      │
     │ (index.php?cl=payment)                                                      │
     │                 │                    │                    │                 │
     │────────────────>│                    │                    │                 │
     │                 │                    │                    │                 │
     │                 │ (No event fired)   │                    │                 │
     │                 │ Contract remains   │                    │                 │
     │                 │ PENDING            │                    │                 │
     │                 │                    │                    │                 │
     │<────────────────│                    │                    │                 │
     │ Payment Page    │                    │                    │                 │
     │ (Can retry)     │                    │                    │                 │
     │                 │                    │                    │                 │
     │                 │                    │                    │                 │
     │    [Later: Contract expires after TTL, cleaned up by cron]                 │
     │                 │                    │                    │                 │
     └─────────────────────────────────────────────────────────────────────────────┘
```

### Events Fired

| Step | Event | Handler | Description |
|------|-------|---------|-------------|
| 1 | `StripeCheckoutSessionRequestEvent` | `StripeCheckoutSessionHandler` | Creates Stripe Checkout Session |
| - | *(No return event)* | - | Customer canceled, no return to success_url |

### Contract State

```
Contract State: PENDING → (remains PENDING) → EXPIRED (after TTL)
```

### Notes

- No `StripeCheckoutReturnEvent` is fired because customer never reaches success_url
- Contract remains in PENDING state
- Cron job (`cleanupExpiredContracts`) will expire contract after TTL
- Customer can retry payment (same contract or new one)
- Stripe Checkout Session expires after 24 hours

---

## 3. Payment Authorization Declined Flow

### Sequence Diagram

```
┌─────────┐     ┌──────────────┐     ┌─────────────┐     ┌───────────────┐     ┌────────┐
│ Customer│     │OrderController │   │EventDispatcher│   │    Handlers   │     │ Stripe │
└────┬────┘     └──────┬───────┘     └──────┬──────┘     └───────┬───────┘     └───┬────┘
     │                 │                    │                    │                 │
     │ [After Stripe Checkout - Card Declined]                                     │
     │                 │                    │                    │                 │
     │ Redirect to success_url (Stripe still redirects on some failures)          │
     │ (?session_id=xxx&contract_id=xxx&contract_token=xxx)                       │
     │                 │                    │                    │                 │
     │────────────────>│                    │                    │                 │
     │                 │ checkoutSuccess()  │                    │                 │
     │                 │                    │                    │                 │
     │                 │                    │ StripeCheckout     │                 │
     │                 │                    │ ReturnEvent        │                 │
     │                 │                    │───────────────────>│                 │
     │                 │                    │                    │                 │
     │                 │                    │                    │ Validate token ✓│
     │                 │                    │                    │ Load contract ✓ │
     │                 │                    │                    │                 │
     │                 │                    │                    │ Retrieve        │
     │                 │                    │                    │ CheckoutSession │
     │                 │                    │                    │────────────────>│
     │                 │                    │                    │<────────────────│
     │                 │                    │                    │ payment_status= │
     │                 │                    │                    │ "unpaid"        │
     │                 │                    │                    │                 │
     │                 │                    │                    │ OR              │
     │                 │                    │                    │                 │
     │                 │                    │                    │ Retrieve        │
     │                 │                    │                    │ PaymentIntent   │
     │                 │                    │                    │────────────────>│
     │                 │                    │                    │<────────────────│
     │                 │                    │                    │ status=         │
     │                 │                    │                    │ "requires_      │
     │                 │                    │                    │ payment_method" │
     │                 │                    │                    │                 │
     │                 │                    │                    │ Payment FAILED  │
     │                 │                    │                    │ Set error in    │
     │                 │                    │                    │ context         │
     │                 │                    │                    │                 │
     │                 │                    │<───────────────────│                 │
     │                 │                    │ Context:           │                 │
     │                 │                    │ paymentFailed=true │                 │
     │                 │                    │ error="Card        │                 │
     │                 │                    │        declined"   │                 │
     │                 │<───────────────────│                    │                 │
     │                 │                    │                    │                 │
     │<────────────────│                    │                    │                 │
     │ Redirect to     │                    │                    │                 │
     │ Payment Page    │                    │                    │                 │
     │ with error msg  │                    │                    │                 │
     │                 │                    │                    │                 │
     └─────────────────────────────────────────────────────────────────────────────┘
```

### Alternative: Stripe Handles Decline Internally

```
┌─────────┐                                                              ┌────────┐
│ Customer│                                                              │ Stripe │
└────┬────┘                                                              └───┬────┘
     │                                                                       │
     │ Enter card details on Stripe Checkout                                 │
     │══════════════════════════════════════════════════════════════════════>│
     │                                                                       │
     │                                                    Card authorization │
     │                                                    ──────────────────>│ Bank
     │                                                    <──────────────────│
     │                                                    DECLINED           │
     │                                                                       │
     │<══════════════════════════════════════════════════════════════════════│
     │ "Your card was declined. Please try another payment method."          │
     │                                                                       │
     │ Customer stays on Stripe Checkout page                                │
     │ Can retry with different card                                         │
     │                                                                       │
     │ OR clicks "Back" → cancel_url                                         │
     │                                                                       │
     └───────────────────────────────────────────────────────────────────────┘
```

### Events Fired

| Step | Event | Handler | Description |
|------|-------|---------|-------------|
| 1 | `StripeCheckoutReturnEvent` | `StripeCheckoutReturnHandler` | Checks payment status, finds failure |

### Key Data in EventContext (Declined)

```php
EventContext([
    'sessionId' => 'cs_xxx',
    'contractId' => 'uuid-xxx',
    'contractToken' => 'token_xxx',
    // Output:
    'paymentFailed' => true,
    'paymentStatus' => 'requires_payment_method',
    'error' => 'Your card was declined.',
    'declineCode' => 'card_declined',
])
```

### Contract State

```
Contract State: PENDING → (remains PENDING, payment condition NOT satisfied)
```

### Stripe Payment Status Values

| Status | Meaning | Action |
|--------|---------|--------|
| `succeeded` | Payment successful | Create order |
| `processing` | Payment processing (async) | Wait for webhook |
| `requires_payment_method` | Card declined | Show error, allow retry |
| `requires_action` | 3DS required | Redirect to 3DS |
| `canceled` | Payment canceled | Show error |

---

## 4. Refund Flow (Admin)

### Sequence Diagram

```
┌─────────┐     ┌──────────────┐     ┌─────────────┐     ┌───────────────┐     ┌────────┐
│  Admin  │     │OrderRefund    │     │EventDispatcher│   │    Handlers   │     │ Stripe │
│  User   │     │Controller     │     │              │     │               │     │        │
└────┬────┘     └──────┬───────┘     └──────┬──────┘     └───────┬───────┘     └───┬────┘
     │                 │                    │                    │                 │
     │ Open Refund Tab │                    │                    │                 │
     │────────────────>│                    │                    │                 │
     │                 │                    │                    │                 │
     │                 │ render()           │                    │                 │
     │                 │ getStripeApiOrder()│                    │                 │
     │                 │─────────────────────────────────────────────────────────>│
     │                 │<─────────────────────────────────────────────────────────│
     │                 │ PaymentIntent + Charge data                              │
     │                 │                    │                    │                 │
     │<────────────────│                    │                    │                 │
     │ Refund Form     │                    │                    │                 │
     │ (shows Stripe   │                    │                    │                 │
     │  captured amt)  │                    │                    │                 │
     │                 │                    │                    │                 │
     │ Click "Refund"  │                    │                    │                 │
     │ (fullRefund)    │                    │                    │                 │
     │────────────────>│                    │                    │                 │
     │                 │                    │                    │                 │
     │                 │ Validate input     │                    │                 │
     │                 │                    │                    │                 │
     │                 │ Create EventContext│                    │                 │
     │                 │ orderId, amount,   │                    │                 │
     │                 │ reason, initiator  │                    │                 │
     │                 │                    │                    │                 │
     │                 │                    │ StripeRefund       │                 │
     │                 │                    │ RequestEvent       │                 │
     │                 │                    │───────────────────>│                 │
     │                 │                    │                    │                 │
     │                 │                    │                    │ Load order      │
     │                 │                    │                    │ Get PaymentIntent│
     │                 │                    │                    │────────────────>│
     │                 │                    │                    │<────────────────│
     │                 │                    │                    │                 │
     │                 │                    │                    │ Get Charge ID   │
     │                 │                    │                    │────────────────>│
     │                 │                    │                    │<────────────────│
     │                 │                    │                    │                 │
     │                 │                    │                    │ Create Refund   │
     │                 │                    │                    │────────────────>│
     │                 │                    │                    │<────────────────│
     │                 │                    │                    │ Refund object   │
     │                 │                    │                    │ (re_xxx)        │
     │                 │                    │                    │                 │
     │                 │                    │                    │ Update order    │
     │                 │                    │                    │ status          │
     │                 │                    │                    │                 │
     │                 │                    │                    │ Set results in  │
     │                 │                    │                    │ context         │
     │                 │                    │<───────────────────│                 │
     │                 │                    │ Context:           │                 │
     │                 │                    │ refundSuccess=true │                 │
     │                 │                    │ refundId="re_xxx"  │                 │
     │                 │                    │ refundedAmount=85.99│                │
     │                 │<───────────────────│                    │                 │
     │                 │                    │                    │                 │
     │                 │ processContext     │                    │                 │
     │                 │ Results()          │                    │                 │
     │                 │                    │                    │                 │
     │<────────────────│                    │                    │                 │
     │ Success Message │                    │                    │                 │
     │ "Refund         │                    │                    │                 │
     │  Successful"    │                    │                    │                 │
     │                 │                    │                    │                 │
     └─────────────────────────────────────────────────────────────────────────────┘
```

### Events Fired

| Step | Event | Handler | Description |
|------|-------|---------|-------------|
| 1 | `StripeRefundRequestEvent` | `StripeRefundRequestHandler` | Processes refund via Stripe API |

### Key Data in EventContext

**Input (from Controller):**
```php
EventContext([
    'orderId' => 'oxid-xxx',
    'contractId' => null,  // Optional, for contract-based refunds
    'amount' => null,      // null = full refund, or specific amount
    'reason' => 'requested_by_customer',  // duplicate, fraudulent, etc.
    'description' => 'Customer requested refund',
    'initiator' => 'admin',  // admin, webhook, api, mcp
])
```

**Output (from Handler):**
```php
EventContext([
    // ... input fields ...
    'refundSuccess' => true,
    'refundId' => 're_xxx',
    'refundedAmount' => 85.99,
    'refundStatus' => 'succeeded',
    // OR on failure:
    'refundSuccess' => false,
    'error' => 'Refund amount exceeds charge amount',
])
```

### Refund Types

| Type | Amount Parameter | Description |
|------|------------------|-------------|
| Full Refund | `null` | Refunds entire captured amount |
| Partial Refund | `50.00` | Refunds specified amount |
| Remaining Refund | `null` + `refundRemaining=1` | Refunds whatever is left |

### Multi-Channel Support

The same `StripeRefundRequestEvent` can be triggered from:

```
┌─────────────────────────────────────────────────────────────────┐
│                     REFUND ENTRY POINTS                         │
├─────────────────────────────────────────────────────────────────┤
│  Admin Panel    │   Webhook    │    REST API    │    MCP        │
│  (fullRefund)   │  (stripe)    │   (external)   │  (tools)      │
│  initiator:     │  initiator:  │  initiator:    │  initiator:   │
│  'admin'        │  'webhook'   │  'api'         │  'mcp'        │
└───────┬─────────┴──────┬───────┴───────┬────────┴───────┬───────┘
        │                │               │                │
        ▼                ▼               ▼                ▼
┌─────────────────────────────────────────────────────────────────┐
│                    EventContext                                  │
│  orderId, amount, reason, description, initiator                │
└───────────────────────────┬─────────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────────┐
│              StripeRefundRequestEvent                           │
└───────────────────────────┬─────────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────────┐
│            StripeRefundRequestHandler                           │
│  (Same business logic for ALL channels)                         │
└─────────────────────────────────────────────────────────────────┘
```

---

## 5. Webhook Flow (Payment Confirmation)

### Sequence Diagram

```
┌────────┐     ┌──────────────┐     ┌─────────────┐     ┌───────────────┐     ┌─────────┐
│ Stripe │     │WebhookController│  │EventDispatcher│   │    Handlers   │     │ Database│
└───┬────┘     └──────┬───────┘     └──────┬──────┘     └───────┬───────┘     └────┬────┘
    │                 │                    │                    │                  │
    │ POST /webhook   │                    │                    │                  │
    │ (payment_intent.│                    │                    │                  │
    │  succeeded)     │                    │                    │                  │
    │────────────────>│                    │                    │                  │
    │                 │                    │                    │                  │
    │                 │ Verify signature   │                    │                  │
    │                 │ (webhook secret)   │                    │                  │
    │                 │                    │                    │                  │
    │                 │ Parse event type   │                    │                  │
    │                 │                    │                    │                  │
    │                 │                    │ StripeWebhook      │                  │
    │                 │                    │ Event              │                  │
    │                 │                    │───────────────────>│                  │
    │                 │                    │                    │                  │
    │                 │                    │                    │ Find contract by │
    │                 │                    │                    │ PaymentIntent ID │
    │                 │                    │                    │─────────────────>│
    │                 │                    │                    │<─────────────────│
    │                 │                    │                    │                  │
    │                 │                    │                    │ Update contract  │
    │                 │                    │                    │ payment condition│
    │                 │                    │                    │─────────────────>│
    │                 │                    │                    │                  │
    │                 │                    │                    │ If all conditions│
    │                 │                    │                    │ met: fulfill     │
    │                 │                    │                    │ contract         │
    │                 │                    │                    │                  │
    │                 │                    │<───────────────────│                  │
    │                 │<───────────────────│                    │                  │
    │                 │                    │                    │                  │
    │<────────────────│                    │                    │                  │
    │ HTTP 200 OK     │                    │                    │                  │
    │                 │                    │                    │                  │
    └──────────────────────────────────────────────────────────────────────────────┘
```

### Webhook Event Types Handled

| Stripe Event | Internal Event | Handler | Description |
|--------------|----------------|---------|-------------|
| `payment_intent.succeeded` | `StripeWebhookEvent` | `StripeWebhookHandler` | Payment confirmed |
| `payment_intent.payment_failed` | `StripeWebhookEvent` | `StripeWebhookHandler` | Payment failed |
| `charge.refunded` | `StripeWebhookEvent` | `StripeWebhookHandler` | Refund processed |
| `checkout.session.completed` | `StripeWebhookEvent` | `StripeWebhookHandler` | Checkout completed |
| `checkout.session.expired` | `StripeWebhookEvent` | `StripeWebhookHandler` | Checkout expired |

---

## Event Class Hierarchy

```
EventInterface (Component)
    │
    ├── StripeCheckoutSessionRequestEvent
    │       └── Creates Stripe Checkout Session
    │
    ├── StripeCheckoutReturnEvent
    │       └── Handles return from Stripe Checkout
    │
    ├── StripeRefundRequestEvent
    │       └── Processes refund requests
    │
    └── StripeWebhookEvent
            └── Handles Stripe webhook notifications
```

---

## Handler Class Hierarchy

```
EventHandlerInterface (Component)
    │
    ├── StripeCheckoutSessionHandler
    │       └── Creates Stripe Checkout Session via API
    │
    ├── StripeCheckoutReturnHandler
    │       └── Validates return, fulfills contract, creates order
    │
    ├── StripeRefundRequestHandler
    │       └── Processes refund via Stripe API
    │
    └── StripeWebhookHandler
            └── Processes Stripe webhook events
```

---

## Summary Table

| Flow | Events | Success Outcome | Failure Outcome |
|------|--------|-----------------|-----------------|
| **Normal Checkout** | SessionRequest → Return | Order created, contract fulfilled | - |
| **Canceled Checkout** | SessionRequest | Contract remains pending | Contract expires |
| **Declined Payment** | SessionRequest → Return | - | Error displayed, can retry |
| **Admin Refund** | RefundRequest | Refund processed, order updated | Error displayed |
| **Webhook** | WebhookEvent | Contract/order updated | Logged, retry later |

---

**Document Version:** 1.0
**Last Updated:** 2025-12-02
