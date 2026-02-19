# Sprint 6: Admin Backend Capture UI - Completion Report

**Status:** COMPLETED
**Date:** 2025-12-16
**Duration:** ~25 minutes

---

## Summary

Added capture functionality to the admin order view. When a payment is in `requires_capture` status (manual capture mode), the admin can now capture the payment directly from the order refund tab.

---

## Files Modified

### 1. OrderRefund.php (Controller)

**File:** `src/Stripe/Controller/Admin/OrderRefund.php`

Added capture methods:
- `capturePayment()` - Dispatches StripeCaptureRequestEvent to capture authorized payment
- `processCaptureResults()` - Processes capture results from event context
- `isOrderCapturable()` - Checks if PaymentIntent status is `requires_capture`
- `getCaptureableAmount()` - Returns formatted authorized amount
- `getPaymentIntentStatus()` - Returns PaymentIntent status for display
- `wasCaptureSuccessful()` - Returns if last capture was successful
- `getLastCapturedAmount()` - Returns captured amount from last operation
- `getCaptureReasonFromRequest()` - Gets capture reason from form

### 2. stripe_order_refund.html.twig (Template)

**File:** `views/twig/admin/stripe_order_refund.html.twig`

Added:
- Capture success message display
- Capture notice section (shows when payment requires capture)
- Capture form with amount display, reason field, and submit button
- CSS styles for capture-related elements

### 3. Translations

**Files:**
- `views/admin_twig/en/stripe_lang.php`
- `views/admin_twig/de/stripe_lang.php`

Added translation keys:
- `STRIPE_CAPTURE_PAYMENT`
- `STRIPE_CAPTURE_REQUIRED`
- `STRIPE_CAPTURE_REQUIRED_TEXT`
- `STRIPE_CAPTURE_AMOUNT_TEXT`
- `STRIPE_CAPTURE_REASON`
- `STRIPE_CAPTURE_REASON_PLACEHOLDER`
- `STRIPE_CAPTURE_SUBMIT`
- `STRIPE_CAPTURE_SUCCESSFUL`
- `STRIPE_CAPTURE_FAILED`
- `STRIPE_CAPTURE_NO_ORDER`
- `STRIPE_CAPTURE_NO_TRANSACTION`

---

## Test Results

```
PHPUnit 11.5.44
Tests: 1401, Assertions: 3332
Status: OK

No new tests added (controller tests are integration-level)
```

---

## Code Quality

| Check | Status | Notes |
|-------|--------|-------|
| PHPUnit Unit Tests | PASS | 1401 tests |
| PHP CodeSniffer (PSR-12) | PASS | |
| PHPStan Level 6 | WARNING | Pre-existing controller issues |
| PHPMD | WARNING | Pre-existing PaymentContract complexity |

---

## UI Flow

### When Payment Requires Capture (Manual Mode)

```
Admin opens Order > Stripe tab
        |
Shows notice: "Payment Capture Required"
        |
Shows capture form:
  - Authorized amount
  - Optional reason field
  - "Capture Payment" button
        |
Admin clicks "Capture Payment"
        |
Controller dispatches StripeCaptureRequestEvent
        |
StripeCaptureRequestHandler processes capture
        |
Success: Shows "Payment capture was successful"
Error: Shows error message
```

### After Capture

Once payment is captured, the capture section disappears and the refund section becomes available (since payment is now refundable).

---

## Screenshot (Conceptual)

```
┌─────────────────────────────────────────────────────────────┐
│ STRIPE TAB - Order #12345                                   │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│ ┌─────────────────────────────────────────────────────────┐ │
│ │ ℹ️ Payment Capture Required                              │ │
│ │ This payment has been authorized but not yet captured.  │ │
│ │ You must capture the payment to complete the transaction│ │
│ └─────────────────────────────────────────────────────────┘ │
│                                                             │
│ ┌─ Payment Details ───────────────────────────────────────┐ │
│ │ Payment type: stripe_card                               │ │
│ │ Transaction ID: pi_abc123...                            │ │
│ └─────────────────────────────────────────────────────────┘ │
│                                                             │
│ ┌─ Capture Payment ───────────────────────────────────────┐ │
│ │ Authorized amount to capture: €99.99 EUR                │ │
│ │                                                         │ │
│ │ Capture note (optional):                                │ │
│ │ [_______________________________________]               │ │
│ │                                                         │ │
│ │ [Capture Payment]                                       │ │
│ └─────────────────────────────────────────────────────────┘ │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

---

## Integration Points

1. **StripeCaptureRequestEvent** (Sprint 4): The controller dispatches this event
2. **StripeCaptureRequestHandler** (Sprint 4): Processes the capture via Stripe API
3. **Stripe API**: PaymentIntent.capture() is called
4. **Contract State**: Transitions from AUTHORIZED to READY_TO_COMMIT

---

## Key Code Additions

### Controller - capturePayment()

```php
public function capturePayment(): void
{
    $oOrder = $this->getOrder();
    if ($oOrder === null) {
        $this->setErrorMessage(Registry::getLang()->translateString('STRIPE_CAPTURE_NO_ORDER'));
        $this->_blSuccessfulCapture = false;
        return;
    }

    $paymentIntentId = $oOrder->oxorder__oxtransid->value ?? null;
    if (empty($paymentIntentId)) {
        $this->setErrorMessage(Registry::getLang()->translateString('STRIPE_CAPTURE_NO_TRANSACTION'));
        $this->_blSuccessfulCapture = false;
        return;
    }

    $context = new EventContext([
        'orderId' => $oOrder->getId(),
        'contractId' => $this->getContractIdFromOrder($oOrder),
        'paymentIntentId' => $paymentIntentId,
        'amount' => null, // Full capture
        'initiator' => 'admin',
        'reason' => $this->getCaptureReasonFromRequest(),
    ]);

    $event = new StripeCaptureRequestEvent($context);
    $this->getEventDispatcher()->dispatch($event);

    $this->_oEventContext = $context;
    $this->processCaptureResults($context);
}
```

### Controller - isOrderCapturable()

```php
public function isOrderCapturable(): bool
{
    if ($this->wasCaptureSuccessful() === true) {
        return false;
    }

    $oApiOrder = $this->getStripeApiOrder();
    if ($oApiOrder === null) {
        return false;
    }

    $status = $oApiOrder->status ?? '';
    return $status === 'requires_capture';
}
```

### Template - Capture Section

```twig
{% set blIsOrderCapturable = oView.isOrderCapturable() %}
{% if blIsOrderCapturable == true %}
    <fieldset class="captureNotice message">
        <strong>{{ translate({ ident: "STRIPE_CAPTURE_REQUIRED" }) }}</strong>
        {{ translate({ ident: "STRIPE_CAPTURE_REQUIRED_TEXT" }) }}
    </fieldset>

    <fieldset class="capturePayment">
        <legend>{{ translate({ ident: "STRIPE_CAPTURE_PAYMENT" }) }}</legend>
        <form name="captureForm" action="{{ oViewConf.getSelfLink() }}" method="post">
            {{ oViewConf.getHiddenSid()|raw }}
            <input type="hidden" name="cl" value="stripe_order_refund">
            <input type="hidden" name="oxid" value="{{ oxid }}">
            <input type="hidden" name="fnc" value="capturePayment">

            <span>{{ translate({ ident: "STRIPE_CAPTURE_AMOUNT_TEXT" }) }}:
                  {{ oView.getCaptureableAmount() }}</span><br><br>

            <label for="capture_reason">{{ translate({ ident: "STRIPE_CAPTURE_REASON" }) }}:</label>
            <input type="text" name="capture_reason" placeholder="..."><br>

            <input type="submit" value="{{ translate({ ident: "STRIPE_CAPTURE_SUBMIT" }) }}">
        </form>
    </fieldset>
{% endif %}
```

---

## Acceptance Criteria

- [x] Capture button visible when PaymentIntent status is `requires_capture`
- [x] Capture button hidden after successful capture
- [x] Capture triggers StripeCaptureRequestEvent
- [x] Success message displayed after successful capture
- [x] Error message displayed on capture failure
- [x] Translations added (English and German)
- [x] All unit tests pass
- [x] PHPStan/PHPMD warnings are pre-existing only

---

## Test Commands

```bash
# Run unit tests
docker compose exec php php vendor/bin/phpunit -c extensions/stripe/tests/phpunit.xml --testsuite Unit

# Pre-commit checks
./bin/pre-commit-check.sh
```

---

## Next Sprint

Sprint 7: Webhook handler for charge.captured - Handle webhook events when payment is captured externally

