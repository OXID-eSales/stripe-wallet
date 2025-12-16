# Sprint 3: Fix Manual Capture Redirect Issue

**Date:** 2025-12-17
**Status:** DONE
**Branch:** b-7.4.x-code-review-STRP-75

---

## Problem Statement

E2E test failed: After completing Stripe payment in manual capture mode, users were redirected to `cl=start&redirected=1` instead of `cl=thankyou`.

### Symptom
```
Final URL: https://daniil.oxiddev.de/index.php?cl=start&redirected=1&lang=0&force_sid=xxx
Expected:  https://daniil.oxiddev.de/index.php?cl=thankyou&lang=0&force_sid=xxx
```

---

## Root Cause Analysis

Using the event logging from Sprint 2, we identified the issue:

### Event Log Output
```
[2025-12-17 10:13:02] EVENT Step 6: Handle payment status {"isRequiresCapture":true}
[2025-12-17 10:13:02] EVENT Step 6a: Manual capture mode - calling handleRequiresCaptureStatus
[2025-12-17 10:13:02] EVENT StripeCheckoutReturnHandler::handle() END {"redirectTarget":"thankyou","orderId":null}
```

**Key Finding:** `orderId: null` - No order was being created in manual capture mode.

### Problem Chain

1. Stripe returns with `paymentIntentStatus: "requires_capture"` (manual capture mode)
2. `handleRequiresCaptureStatus()` was NOT dispatching `PaymentAuthorizedEvent`
3. Without this event, no order was created
4. Controller set `redirectTarget=thankyou` but with no order ID
5. OXID's thankyou page requires `sess_challenge` (order ID) to display
6. Without `sess_challenge`, OXID redirects to start page

### Code Before Fix

```php
private function handleRequiresCaptureStatus(...): void
{
    // ... logging and setup ...

    // Store PaymentIntent ID for later capture
    $contract->setMetadata('payment_intent_id', $paymentIntentId);
    $this->contractRepository->save($contract);

    // BUG: Only set redirect target, but NO order was created!
    $context->set('redirectTarget', 'thankyou');
}
```

---

## Solution

### 1. Dispatch PaymentAuthorizedEvent in Manual Capture Mode

Modified `handleRequiresCaptureStatus()` to dispatch `PaymentAuthorizedEvent`:

```php
private function handleRequiresCaptureStatus(...): void
{
    // ... existing setup code ...

    // Set context flag for downstream handlers
    $context->set('requiresCapture', true);

    // Dispatch PaymentAuthorizedEvent to trigger order creation
    $event = new PaymentAuthorizedEvent(
        context: $context,
        authorizationId: $paymentIntentId,
        providerOrderId: $paymentIntentId,
        amount: $amount,
        currency: $currency
    );

    $this->eventDispatcher->dispatch($event);

    // Set redirect based on order creation result
    if ($context->get('orderId') !== null) {
        $context->set('redirectTarget', 'thankyou');
    }
}
```

### 2. Skip OXPAID Update for Manual Capture

Modified `StripeOrderCreationHandler` to conditionally update OXPAID:

```php
// In handlePostOrderCreation()
$requiresCapture = $context->get('requiresCapture') === true;
if (!$requiresCapture) {
    $this->updateOrderPaidTimestamp($orderId, $contract->getProviderOrderId());
} else {
    $this->logEvent('StripeOrderCreationHandler: Skipping OXPAID (manual capture mode)');
}
```

---

## Files Modified

| File | Changes |
|------|---------|
| `StripeCheckoutReturnHandler.php` | Dispatch `PaymentAuthorizedEvent` in manual capture mode |
| `StripeOrderCreationHandler.php` | Conditional OXPAID update; refactored for PHPMD compliance |

---

## Event Flow After Fix

```
StripeCheckoutReturnHandler::handle()
├── Step 6a: Manual capture mode detected
├── handleRequiresCaptureStatus()
│   ├── Set context.requiresCapture = true
│   ├── Dispatch PaymentAuthorizedEvent
│   │   └── PaymentAuthorizedEventHandler::handle()
│   │       ├── Transition contract DRAFT -> PENDING
│   │       ├── Fulfill payment_authorized condition
│   │       ├── Contract becomes READY_TO_COMMIT
│   │       └── Dispatch ContractReadyToCommitEvent
│   │           └── StripeOrderCreationHandler::handle()
│   │               ├── Create order (#56)
│   │               ├── Skip OXPAID (requiresCapture=true)
│   │               └── Set context.orderId
│   └── Set redirectTarget=thankyou (orderId exists)
└── END {"redirectTarget":"thankyou","orderId":"xxx"}
```

---

## Verification

### Event Log After Fix
```
[2025-12-17 10:57:25] EVENT handleRequiresCaptureStatus: Dispatching PaymentAuthorizedEvent
[2025-12-17 10:57:25] EVENT PaymentAuthorizedEventHandler::handle() START
[2025-12-17 10:57:25] EVENT StripeOrderCreationHandler: Order created {"orderId":"xxx","orderNumber":56}
[2025-12-17 10:57:25] EVENT StripeOrderCreationHandler: Skipping OXPAID (manual capture mode)
[2025-12-17 10:57:25] EVENT handleRequiresCaptureStatus: After dispatch {"orderId":"xxx"}
[2025-12-17 10:57:25] EVENT StripeCheckoutReturnHandler::handle() END {"redirectTarget":"thankyou","orderId":"xxx"}
```

### E2E Test Result
```
✓ CHECKOUT FLOW COMPLETED SUCCESSFULLY
Final URL: https://daniil.oxiddev.de/index.php?cl=thankyou&lang=0&force_sid=xxx
1 passed (49.2s)
```

---

## Technical Notes

### OXPAID Behavior

| Capture Mode | OXPAID at Order Creation | OXPAID Updated When |
|--------------|--------------------------|---------------------|
| Automatic | Set immediately | N/A |
| Manual | NULL (not set) | Capture webhook received |

### Why This Approach

1. **Order Required**: OXID's thankyou page needs `sess_challenge` to display
2. **OXPAID Deferred**: For manual capture, payment isn't "paid" until captured
3. **Webhook Updates**: `charge.captured` webhook will set OXPAID later

---

## Related Code

- `PaymentAuthorizedEventHandler` - Transitions contract and dispatches `ContractReadyToCommitEvent`
- `StripeOrderCreationHandler` - Creates OXID order from contract
- `OrderPaymentStateService` - Updates OXPAID timestamp

