# Development Log - 2025-12-16

**Branch:** b-7.4.x-code-review-STRP-75
**Focus:** Delayed/Manual Capture Feature Implementation
**JIRA Ticket:** STRP-XX (TBD)

---

## Executive Summary

Implement **configurable capture mode** for the Stripe payment module, allowing merchants to choose between:
1. **Automatic (Instant) Capture** - Funds are captured immediately upon authorization
2. **Manual (Delayed) Capture** - Funds are authorized but captured later via admin action or event trigger

This is a standard feature in payment processing that allows merchants to:
- Capture only when goods are shipped
- Partially capture for partial shipments
- Void uncapturable authorizations after timeout
- Comply with card network rules (authorization validity periods)

---

## Task Description

### Business Requirements

1. **Module Configuration Setting**
   - New dropdown in admin backend: "Capture Mode"
   - Options: "Automatic" (default) | "Manual"
   - Setting stored in module configuration (`sStripeCaptureMode`)

2. **Contract State Machine Awareness**
   - New contract state: `AUTHORIZED` (between `PENDING` and `READY_TO_COMMIT`)
   - For manual capture: Contract transitions to `AUTHORIZED` instead of `READY_TO_COMMIT`
   - Capture action transitions: `AUTHORIZED` → `READY_TO_COMMIT` → `COMMITTED` → `FULFILLED`

3. **Admin Backend Integration**
   - Display capture status on order detail page
   - "Capture Payment" button for orders in `AUTHORIZED` state
   - Partial capture support (capture less than authorized amount)

4. **Event-Driven Capture Triggers**
   - `CaptureRequestedEvent` - Emitted when capture is initiated
   - `PaymentCapturedEvent` - Emitted on successful capture
   - Handlers process capture through Stripe SDK

5. **Webhook Integration**
   - Handle `payment_intent.amount_capturable_updated` webhook
   - Handle `charge.captured` webhook
   - Update contract/order state accordingly

---

## Technical Analysis

### Current Architecture (Automatic Capture)

```
User clicks "Pay" → Stripe Checkout Session
                         ↓
                capture_method: 'automatic'
                         ↓
              Payment authorized + captured
                         ↓
             PaymentIntent.status: 'succeeded'
                         ↓
               Webhook: payment_intent.succeeded
                         ↓
          Contract: PENDING → READY_TO_COMMIT → COMMITTED → FULFILLED
```

### Target Architecture (Manual Capture)

```
User clicks "Pay" → Stripe Checkout Session
                         ↓
                capture_method: 'manual'
                         ↓
               Payment authorized only
                         ↓
             PaymentIntent.status: 'requires_capture'
                         ↓
               Webhook: payment_intent.succeeded
                         ↓
          Contract: PENDING → AUTHORIZED (NEW STATE)
                         ↓
           [WAIT for admin/event trigger]
                         ↓
              Admin clicks "Capture" OR
              Event: order_shipped triggers capture
                         ↓
               CaptureRequestedEvent emitted
                         ↓
              StripeAdapter::capturePayment()
                         ↓
             PaymentIntent.status: 'succeeded'
                         ↓
               Webhook: charge.captured
                         ↓
          Contract: AUTHORIZED → READY_TO_COMMIT → COMMITTED → FULFILLED
```

### Stripe SDK Reference

```php
// Create PaymentIntent with manual capture
$paymentIntent = $stripe->paymentIntents->create([
    'amount' => 1099,
    'currency' => 'eur',
    'capture_method' => 'manual',  // Key setting
    'payment_method_types' => ['card'],
]);

// Capture later
$stripe->paymentIntents->capture($paymentIntentId, [
    'amount_to_capture' => 1099,  // Optional for partial capture
]);
```

### Checkout Session with Manual Capture

```php
$session = $stripe->checkout->sessions->create([
    'mode' => 'payment',
    'payment_intent_data' => [
        'capture_method' => 'manual',
    ],
    'line_items' => [...],
    'success_url' => '...',
    'cancel_url' => '...',
]);
```

---

## Contract State Machine Changes

### Current States

```
DRAFT → PENDING → READY_TO_COMMIT → COMMITTED → FULFILLED
                                              ↘ CANCELLED
                                              ↘ EXPIRED
                                              ↘ FAILED
```

### Proposed States (with AUTHORIZED)

```
DRAFT → PENDING → AUTHORIZED → READY_TO_COMMIT → COMMITTED → FULFILLED
                      │                                     ↘ CANCELLED
                      │                                     ↘ EXPIRED
                      │                                     ↘ FAILED
                      ↓
              (timeout: 7 days)
                      ↓
                   EXPIRED
```

### State Transitions

| From | To | Trigger |
|------|-----|---------|
| PENDING | AUTHORIZED | Payment authorized (manual capture mode) |
| PENDING | READY_TO_COMMIT | Payment captured (automatic capture mode) |
| AUTHORIZED | READY_TO_COMMIT | Manual capture executed |
| AUTHORIZED | EXPIRED | Authorization timeout (7 days) |
| AUTHORIZED | CANCELLED | Admin cancels/voids authorization |

---

## Files to Create/Modify

### New Files

| File | Purpose |
|------|---------|
| `src/Component/Contract/ContractState.php` | Add `AUTHORIZED` state |
| `src/Component/EventSystem/Event/Contract/ContractAuthorizedEvent.php` | New event |
| `src/Component/EventSystem/Event/Payment/CaptureRequestedEvent.php` | Capture trigger event |
| `src/Stripe/EventSystem/Handler/StripeCaptureHandler.php` | Handle capture requests |
| `src/Stripe/EventSystem/Handler/StripeAuthorizationHandler.php` | Handle authorization state |
| `src/Stripe/Service/CaptureConfigurationService.php` | Read capture mode config |
| `views/twig/admin/stripe_order_capture.html.twig` | Admin capture UI |

### Files to Modify

| File | Change |
|------|--------|
| `metadata.php` | Add `sStripeCaptureMode` setting |
| `src/Stripe/Service/CheckoutSessionService.php` | Pass capture_method to Stripe |
| `src/Stripe/Adapter/StripeAdapter.php` | Already has `capturePayment()` method |
| `src/Stripe/EventSystem/Handler/StripeCheckoutReturnHandler.php` | Handle authorized state |
| `src/Component/EventSystem/EventListenerProvider.php` | Register new handlers |
| `translations/de/osc_stripe_wallet_lang.php` | German translations |
| `translations/en/osc_stripe_wallet_lang.php` | English translations |

---

## Events & Handlers Overview

### New Events

1. **ContractAuthorizedEvent**
   - Emitted when: Contract transitions to AUTHORIZED state
   - Subscribers: Order status update, notification services

2. **CaptureRequestedEvent**
   - Emitted when: Admin clicks capture OR external trigger
   - Contains: order_id, amount, idempotency_key, triggered_by
   - Subscribers: StripeCaptureHandler

3. **PaymentCapturedEvent** (exists, extend)
   - Emitted when: Stripe confirms capture
   - Subscribers: Contract state transition, OXPAID update

### Handler Flow

```
CaptureRequestedEvent
       ↓
StripeCaptureHandler
       ↓
   ┌─────────────────┐
   │ 1. Load contract │
   │ 2. Validate state│
   │ 3. Get PaymentIntent ID
   │ 4. Call Stripe capture
   │ 5. Emit PaymentCapturedEvent
   └─────────────────┘
       ↓
PaymentCapturedEvent
       ↓
PaymentCapturedEventHandler
       ↓
   ┌─────────────────┐
   │ 1. Contract → READY_TO_COMMIT
   │ 2. Update OXPAID
   │ 3. Trigger order creation
   └─────────────────┘
```

---

## Configuration Schema

### metadata.php Addition

```php
['group' => 'STRIPE_GENERAL', 'name' => 'sStripeCaptureMode', 'type' => 'select', 'value' => 'automatic', 'position' => 40, 'constraints' => 'automatic|manual'],
```

### Admin Backend Display

```
┌─────────────────────────────────────────────┐
│ Stripe Configuration                        │
├─────────────────────────────────────────────┤
│ Mode: [Test ▼]                              │
│ Test Secret Key: sk_test_***                │
│ Test Public Key: pk_test_***                │
│                                             │
│ Capture Mode: [Automatic ▼]                 │
│   ○ Automatic - Capture immediately         │
│   ○ Manual - Capture later (on shipping)    │
│                                             │
│ Webhook Endpoint: https://...               │
└─────────────────────────────────────────────┘
```

---

## Database Considerations

### Contract Table

No schema changes required - capture mode is configuration, not per-contract data.

However, we may want to track:
- `OXCAPTUREMODE` - 'automatic' or 'manual' (stored at contract creation time)
- This allows historical accuracy even if config changes

### Order Table (oxorder)

Consider adding to contract metadata:
- `capture_mode` - How this order was configured
- `authorized_amount` - Original authorization amount
- `captured_amount` - Amount actually captured (for partial captures)

---

## Test Strategy

### Unit Tests

1. **ContractState** - Test new AUTHORIZED state transitions
2. **CaptureRequestedEvent** - Event data validation
3. **StripeCaptureHandler** - Mock Stripe calls, test state transitions
4. **CaptureConfigurationService** - Config reading

### Integration Tests

1. **Manual Capture Flow** - Full E2E with test mode Stripe
2. **Partial Capture** - Capture less than authorized
3. **Capture Timeout** - Test expiration handling
4. **Webhook Processing** - Test `charge.captured` webhook

### E2E Tests (Playwright)

1. Admin capture button functionality
2. Capture confirmation dialog
3. Order status updates after capture

---

## Sprints

| Sprint | Description | Estimated Effort |
|--------|-------------|------------------|
| Sprint 1 | Add `AUTHORIZED` state to ContractState | 1h |
| Sprint 2 | Add module configuration setting | 1h |
| Sprint 3 | Modify CheckoutSessionService for capture_method | 2h |
| Sprint 4 | Create CaptureRequestedEvent and handler | 3h |
| Sprint 5 | Handle authorization state in return flow | 2h |
| Sprint 6 | Admin backend capture UI | 3h |
| Sprint 7 | Webhook handler for charge.captured | 2h |
| Sprint 8 | Unit tests for all new code | 3h |
| Sprint 9 | Integration tests | 2h |
| Sprint 10 | Documentation updates | 1h |

**Total Estimated:** ~20h

---

## Open Questions

1. **Partial Capture UI** - Should admin be able to specify capture amount?
2. **Auto-Capture Cron** - Should we auto-capture after X days if not manually captured?
3. **Void/Cancel** - Should we add void functionality for uncaptured authorizations?
4. **Per-Payment-Method Config** - Should capture mode be configurable per payment method?

---

## References

- [Stripe Manual Capture Docs](https://stripe.com/docs/payments/capture-later)
- [Stripe Checkout Session API](https://stripe.com/docs/api/checkout/sessions/create)
- [Stripe PaymentIntent Capture API](https://stripe.com/docs/api/payment_intents/capture)
- [Authorization Validity Periods](https://stripe.com/docs/payments/capture-later#authorization-validity)

---

## Files in This Directory

```
20251216/
├── README.md                           # This file - task description
├── status.md                           # Current progress status
├── todo/
│   ├── sprint-1-authorized-state.md    # ContractState changes
│   ├── sprint-2-module-config.md       # Module settings
│   ├── sprint-3-checkout-session.md    # CheckoutSession changes
│   ├── sprint-4-capture-event-handler.md # Events and handlers
│   ├── sprint-5-return-flow.md         # Authorization state handling
│   ├── sprint-6-admin-ui.md            # Backend capture UI
│   ├── sprint-7-webhook-handler.md     # Webhook integration
│   ├── sprint-8-unit-tests.md          # Unit tests
│   ├── sprint-9-integration-tests.md   # Integration tests
│   └── sprint-10-documentation.md      # Docs updates
├── done/                               # Completed sprint reports
└── puml/                               # Diagrams
    └── delayed-capture-flow.puml
```

---

**Last Updated:** 2025-12-16
**Author:** Claude Code
