# Sprint 5: Handle Authorization in Return Flow - Completion Report

**Status:** COMPLETED
**Date:** 2025-12-16
**Duration:** ~30 minutes

---

## Summary

Modified the Stripe checkout return flow to handle the `requires_capture` status from PaymentIntents when manual capture mode is enabled. When a payment is authorized but not yet captured, the contract transitions to AUTHORIZED state instead of proceeding with order creation.

---

## Files Modified

### 1. CheckoutReturnResult.php

**File:** `src/Stripe/DTO/CheckoutReturnResult.php`

Added PaymentIntent status tracking:
- New `paymentIntentStatus` property
- Updated `success()` factory method to accept PaymentIntent status
- Added `getPaymentIntentStatus(): ?string` getter
- Added `isRequiresCapture(): bool` helper
- Added `isCaptured(): bool` helper

### 2. CheckoutReturnService.php

**File:** `src/Stripe/Service/CheckoutReturnService.php`

- Extract PaymentIntent status from expanded Session object
- Pass status to `CheckoutReturnResult::success()`
- Added `extractPaymentIntentStatus()` private method

### 3. StripeCheckoutReturnHandler.php

**File:** `src/Stripe/EventSystem/Handler/StripeCheckoutReturnHandler.php`

- Added handling for `requires_capture` PaymentIntent status
- When `requires_capture`: transition contract to AUTHORIZED, don't dispatch PaymentAuthorizedEvent
- When `succeeded`: continue with existing flow (dispatch PaymentAuthorizedEvent)
- Added `handleRequiresCaptureStatus()` private method

### 4. PaymentContractInterface.php

**File:** `src/Component/Contract/PaymentContractInterface.php`

- Added `authorize(): void` method declaration
- Added `captureAuthorization(): void` method declaration
- Updated state documentation to include AUTHORIZED state

---

## Test Results

```
PHPUnit 11.5.44
Tests: 1401, Assertions: 3332
Status: OK (with pre-existing deprecations)

New tests added: 3
```

### New Handler Tests

| Test | Description |
|------|-------------|
| `testHandleRequiresCaptureTransitionsContractToAuthorized` | `requires_capture` status transitions to AUTHORIZED |
| `testHandleRequiresCaptureStoresPaymentIntentId` | PaymentIntent ID stored in contract metadata |
| `testHandleSucceededDispatchesEventNormally` | `succeeded` status follows existing flow |

---

## Code Quality

| Check | Status | Notes |
|-------|--------|-------|
| PHPUnit Unit Tests | PASS | 1401 tests (+3 new) |
| PHP CodeSniffer (PSR-12) | PASS | |
| PHPStan Level 6 | WARNING | Pre-existing controller issues |
| PHPMD | WARNING | Pre-existing PaymentContract complexity |

---

## Flow Diagram

### Automatic Capture (existing)

```
User completes Stripe Checkout
          |
PaymentIntent.status = 'succeeded'
          |
StripeCheckoutReturnHandler
          |
dispatchPaymentEvent() -> PaymentAuthorizedEvent
          |
Contract: PENDING -> READY_TO_COMMIT
          |
Order created
```

### Manual Capture (new)

```
User completes Stripe Checkout
          |
PaymentIntent.status = 'requires_capture'
          |
StripeCheckoutReturnHandler
          |
handleRequiresCaptureStatus()
          |
Contract: PENDING -> AUTHORIZED
          |
[Wait for manual capture from admin]
          |
StripeCaptureRequestHandler (Sprint 4)
          |
Contract: AUTHORIZED -> READY_TO_COMMIT
          |
Order created
```

---

## Key Code Changes

### CheckoutReturnResult - New Methods

```php
public function getPaymentIntentStatus(): ?string
{
    return $this->paymentIntentStatus;
}

public function isRequiresCapture(): bool
{
    return $this->paymentIntentStatus === 'requires_capture';
}

public function isCaptured(): bool
{
    return $this->paymentIntentStatus === 'succeeded';
}
```

### Handler - Routing Based on Status

```php
// Step 6: Handle based on PaymentIntent status
if ($result->isRequiresCapture()) {
    // Manual capture mode: transition to AUTHORIZED, wait for capture
    $this->handleRequiresCaptureStatus($contract, $result, $context);
} else {
    // Automatic capture or succeeded: dispatch normal payment flow
    $this->dispatchPaymentEvent($result, $context);
}
```

### Handler - handleRequiresCaptureStatus

```php
private function handleRequiresCaptureStatus(
    PaymentContractInterface $contract,
    CheckoutReturnResult $result,
    EventContext $context
): void {
    // Store PaymentIntent ID for later capture
    $contract->setMetadata('payment_intent_id', $paymentIntentId);

    // Transition contract to AUTHORIZED (not READY_TO_COMMIT)
    $contract->authorize();
    $this->contractRepository->save($contract);

    // Set context values for downstream processing
    $context->set('paymentStatus', 'authorized');
    $context->set('requiresCapture', true);
    $context->set('redirectTarget', 'thankyou');
}
```

---

## Status Mapping

| PaymentIntent Status | Capture Mode | Contract State | Order Created |
|---------------------|--------------|----------------|---------------|
| `succeeded` | Automatic | READY_TO_COMMIT -> COMMITTED -> FULFILLED | Yes |
| `requires_capture` | Manual | AUTHORIZED | No (wait for capture) |
| `requires_action` | Either | PENDING | No |

---

## Acceptance Criteria Checklist

- [x] `requires_capture` status transitions contract to AUTHORIZED
- [x] `succeeded` status transitions contract to READY_TO_COMMIT (existing behavior)
- [x] PaymentIntent ID is stored in contract metadata for later capture
- [x] Order is NOT created for `requires_capture` (contract stays in AUTHORIZED)
- [x] Existing automatic capture flow unchanged
- [x] All unit tests pass (1401 tests)
- [x] PHPStan level 6 passes (pre-existing issues only)
- [x] PSR-12 code style passes

---

## Test Commands

```bash
# Run return handler tests
docker compose exec php php vendor/bin/phpunit -c extensions/stripe/tests/phpunit.xml \
  --testsuite Unit --filter "StripeCheckoutReturnHandler"

# Run all checkout return tests
docker compose exec php php vendor/bin/phpunit -c extensions/stripe/tests/phpunit.xml \
  --testsuite Unit --filter "CheckoutReturn"

# Pre-commit checks
./bin/pre-commit-check.sh
```

---

## Integration with Sprint 4

This sprint integrates with Sprint 4 (CaptureRequestedEvent):

1. **Sprint 5 (this):** Customer completes checkout -> PaymentIntent status = `requires_capture` -> Contract AUTHORIZED
2. **Sprint 4:** Admin triggers capture -> StripeCaptureRequestHandler -> Stripe API capture -> Contract READY_TO_COMMIT

---

## Next Sprint

Sprint 6: Admin backend capture UI - Add capture button to admin order view

