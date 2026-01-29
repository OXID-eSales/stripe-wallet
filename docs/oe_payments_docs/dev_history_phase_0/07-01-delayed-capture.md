# Delayed/Manual Capture Feature

**Version:** 2.0.0
**Date:** 2025-12-16
**Sprint:** STRP-75

---

## Overview

The delayed capture feature allows merchants to **authorize payments without immediately capturing funds**. This is useful for scenarios where:

- Orders need manual review before fulfillment
- Stock availability needs verification
- Fraud checks require additional time
- Multi-step fulfillment workflows

## How It Works

### Automatic Capture (Default)

```
Customer places order → Payment authorized & captured → Funds transferred
```

### Manual Capture (Delayed)

```
Customer places order → Payment authorized only → Admin reviews →
Admin captures payment → Funds transferred
```

---

## Configuration

### Module Setting

Navigate to **Extensions → Modules → Stripe Payments → Settings → Basic Configuration**

| Setting | Options | Description |
|---------|---------|-------------|
| Capture Mode | Automatic (default) | Payment captured immediately on checkout |
| | Manual | Payment authorized only, capture later via admin |

### Stripe Dashboard

When manual capture is enabled:
- PaymentIntents are created with `capture_method: manual`
- Authorized payments show status `requires_capture` in Stripe Dashboard
- Authorizations expire after 7 days (card payments)

---

## Contract State Machine

The delayed capture feature adds the `AUTHORIZED` state to the contract lifecycle:

### Automatic Capture Flow

```
DRAFT → PENDING → READY_TO_COMMIT → COMMITTED → FULFILLED
```

### Manual Capture Flow

```
DRAFT → PENDING → AUTHORIZED → READY_TO_COMMIT → COMMITTED → FULFILLED
                      ↓
              (Admin captures)
```

### State Descriptions

| State | Description |
|-------|-------------|
| `PENDING` | Contract created, waiting for payment authorization |
| `AUTHORIZED` | Payment authorized but not captured (manual mode only) |
| `READY_TO_COMMIT` | Payment captured, ready for order creation |
| `COMMITTED` | Order created, linked to contract |
| `FULFILLED` | Order completed, payment settled |

---

## Admin Interface

### Capture Payment

When a payment requires capture, the admin order view shows:

1. **Notice Banner**: "Payment Capture Required" alert
2. **Capture Form**:
   - Authorized amount display
   - Optional capture note field
   - "Capture Payment" button

### Location

**Administer Orders → Order Details → Stripe Tab**

### UI Flow

```
Admin opens order
        ↓
Sees "Payment Capture Required" notice
        ↓
Reviews order details
        ↓
Clicks "Capture Payment"
        ↓
Payment captured via Stripe API
        ↓
Success message displayed
        ↓
Capture section disappears
        ↓
Refund section becomes available
```

---

## Technical Implementation

### Key Components

| Component | Purpose |
|-----------|---------|
| `ContractState::authorized()` | New state for authorized payments |
| `PaymentContract::authorize()` | Transition PENDING → AUTHORIZED |
| `PaymentContract::captureAuthorization()` | Transition AUTHORIZED → READY_TO_COMMIT |
| `StripeCaptureRequestEvent` | Event for capture requests |
| `StripeCaptureRequestHandler` | Handles capture via Stripe API |
| `WebhookContractFulfillmentHandler` | Handles charge.captured webhooks |
| `ModuleConfigurationService::getCaptureMode()` | Returns configured capture mode |

### Event Flow

#### Admin Capture

```
Admin clicks "Capture Payment"
        ↓
OrderRefund::capturePayment()
        ↓
StripeCaptureRequestEvent dispatched
        ↓
StripeCaptureRequestHandler::handle()
        ↓
StripeAdapter::capturePayment()
        ↓
PaymentIntent.capture() API call
        ↓
Contract: AUTHORIZED → READY_TO_COMMIT
```

#### Webhook Handling

```
Stripe sends charge.captured webhook
        ↓
WebhookController receives event
        ↓
WebhookContractFulfillmentHandler::handleChargeCaptured()
        ↓
Contract state checked
        ↓
If AUTHORIZED: captureAuthorization() → READY_TO_COMMIT
If COMMITTED: fulfill() → FULFILLED
```

---

## Stripe API Integration

### PaymentIntent Creation (Manual Mode)

```php
$paymentIntent = $stripe->paymentIntents->create([
    'amount' => 9999,
    'currency' => 'eur',
    'capture_method' => 'manual',  // Key setting
    'payment_method_types' => ['card'],
]);
```

### Capture Payment

```php
$stripe->paymentIntents->capture($paymentIntentId, [
    'amount_to_capture' => 9999,  // Optional: for partial capture
]);
```

### PaymentIntent Statuses

| Status | Description |
|--------|-------------|
| `requires_payment_method` | Awaiting payment method |
| `requires_confirmation` | Awaiting confirmation |
| `requires_action` | Awaiting customer action (3DS) |
| `requires_capture` | **Authorized, awaiting capture** |
| `succeeded` | Payment captured |
| `canceled` | Payment canceled |

---

## Webhooks

### Relevant Events

| Event | Trigger | Handler Action |
|-------|---------|----------------|
| `charge.captured` | Payment captured | Transition AUTHORIZED → READY_TO_COMMIT |
| `payment_intent.succeeded` | Payment completed | Fulfill contract if COMMITTED |
| `payment_intent.payment_failed` | Payment failed | Mark contract as FAILED |

### Idempotency

The webhook handler is idempotent:
- Already fulfilled contracts return `false` (no error)
- Captured amount is recorded regardless of state
- Multiple webhooks for same payment are handled safely

---

## Testing

### Unit Tests

```bash
# Run delayed capture unit tests
docker compose exec php php vendor/bin/phpunit -c extensions/stripe/tests/phpunit.xml \
  --group sprint-7

# Run CheckoutReturnResult tests
docker compose exec php php vendor/bin/phpunit -c extensions/stripe/tests/phpunit.xml \
  extensions/stripe/tests/Unit/Stripe/DTO/CheckoutReturnResultTest.php
```

### Integration Tests

```bash
# Run delayed capture integration tests
docker compose exec php php vendor/bin/phpunit -c extensions/stripe/tests/phpunit.xml \
  extensions/stripe/tests/Integration/Stripe/Webhook/DelayedCaptureIntegrationTest.php
```

### E2E Tests

```bash
# Run Playwright checkout tests
cd tests/e2e/playwright && npx playwright test tests/checkout/
```

---

## Translations

### English

| Key | Text |
|-----|------|
| `STRIPE_CAPTURE_PAYMENT` | Capture Payment |
| `STRIPE_CAPTURE_REQUIRED` | Payment Capture Required |
| `STRIPE_CAPTURE_REQUIRED_TEXT` | This payment has been authorized but not yet captured... |
| `STRIPE_CAPTURE_AMOUNT_TEXT` | Authorized amount to capture |
| `STRIPE_CAPTURE_REASON` | Capture note (optional) |
| `STRIPE_CAPTURE_SUBMIT` | Capture Payment |
| `STRIPE_CAPTURE_SUCCESSFUL` | Payment capture was successful. |
| `STRIPE_CAPTURE_FAILED` | Payment capture failed. |

### German

| Key | Text |
|-----|------|
| `STRIPE_CAPTURE_PAYMENT` | Zahlung erfassen |
| `STRIPE_CAPTURE_REQUIRED` | Zahlungserfassung erforderlich |
| `STRIPE_CAPTURE_REQUIRED_TEXT` | Diese Zahlung wurde autorisiert, aber noch nicht erfasst... |
| `STRIPE_CAPTURE_AMOUNT_TEXT` | Autorisierter Betrag zur Erfassung |
| `STRIPE_CAPTURE_REASON` | Erfassungshinweis (optional) |
| `STRIPE_CAPTURE_SUBMIT` | Zahlung erfassen |
| `STRIPE_CAPTURE_SUCCESSFUL` | Zahlungserfassung war erfolgreich. |
| `STRIPE_CAPTURE_FAILED` | Zahlungserfassung fehlgeschlagen. |

---

## Limitations & Future Enhancements

### Current Limitations

1. **Full capture only**: Partial capture not yet supported
2. **No auto-capture cron**: Manual capture only via admin UI
3. **No void/cancel**: Cannot void authorized payments from admin

### Planned Enhancements

| Feature | Priority | Description |
|---------|----------|-------------|
| Partial capture | Medium | Capture less than authorized amount |
| Auto-capture cron | Low | Automatically capture after X days |
| Void authorization | Medium | Cancel authorized payments |
| Capture via API | Low | REST API endpoint for capture |

---

## Troubleshooting

### Payment Not Showing "Requires Capture"

1. Verify capture mode is set to "Manual" in module settings
2. Check PaymentIntent status in Stripe Dashboard
3. Ensure webhook endpoint is configured correctly

### Capture Button Not Visible

1. Order must have valid transaction ID
2. PaymentIntent status must be `requires_capture`
3. Check browser console for JavaScript errors

### Webhook Not Updating Contract

1. Verify webhook endpoint URL in Stripe Dashboard
2. Check webhook signing secret matches configuration
3. Review webhook logs in `SHOPROOT/log/StripeTransactions.log`

---

## Related Documentation

- [Contract State Machine](./02-database-and-models.md)
- [Webhook Processing](./05-webhooks.md)
- [Event System](./01-architecture-layers.md)
- [Stripe API Reference](https://stripe.com/docs/api/payment_intents/capture)
