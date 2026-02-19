# Sprint 4: CaptureRequestedEvent and Handler - Completion Report

**Status:** COMPLETED
**Date:** 2025-12-16
**Duration:** ~45 minutes

---

## Summary

Created the event and handler infrastructure for processing manual capture requests from admin backend or automated processes.

---

## Files Created

### 1. StripeCaptureRequestEvent.php

**File:** `src/Stripe/EventSystem/Event/StripeCaptureRequestEvent.php`

Event for requesting payment capture. Follows the same pattern as StripeRefundRequestEvent.

Context data:
- INPUT: contractId, orderId, paymentIntentId, amount, initiator, reason
- OUTPUT: captureSuccess, captureId, capturedAmount, captureCurrency, capturedAt, error

### 2. StripeCaptureRequestHandler.php

**File:** `src/Stripe/EventSystem/Handler/StripeCaptureRequestHandler.php`

Handler responsibilities:
1. Receive event and extract parameters
2. Load and validate contract (must be in AUTHORIZED state)
3. Get PaymentIntent ID from event or contract
4. Call Stripe adapter to execute capture
5. Transition contract from AUTHORIZED to READY_TO_COMMIT
6. Log request/response
7. Set results in context

### 3. Unit Tests

**Files:**
- `tests/Unit/Stripe/EventSystem/Event/StripeCaptureRequestEventTest.php` (13 tests)
- `tests/Unit/Stripe/EventSystem/Handler/StripeCaptureRequestHandlerTest.php` (11 tests)

---

## Test Results

```
PHPUnit 11.5.44
Tests: 1398, Assertions: 3319
Status: OK

New tests added: 24
```

### Event Tests (13)

| Test | Description |
|------|-------------|
| `testGetContractIdReturnsValue` | Retrieves contract ID from context |
| `testGetContractIdReturnsNullWhenMissing` | Returns null when missing |
| `testGetOrderIdReturnsValue` | Retrieves order ID from context |
| `testGetPaymentIntentIdReturnsValue` | Retrieves PaymentIntent ID |
| `testGetAmountReturnsValue` | Retrieves capture amount |
| `testGetAmountReturnsNullForFullCapture` | Null means full capture |
| `testIsFullCaptureReturnsTrueWhenAmountIsNull` | Full capture detection |
| `testIsFullCaptureReturnsFalseWhenAmountIsSet` | Partial capture detection |
| `testGetInitiatorReturnsValue` | Retrieves initiator (admin/webhook/api/cron) |
| `testGetInitiatorReturnsAdminAsDefault` | Default initiator is 'admin' |
| `testGetReasonReturnsValue` | Retrieves capture reason |
| `testGetIdempotencyKeyReturnsValue` | Retrieves idempotency key |
| `testContextCanBeModifiedByHandler` | Handler can set output values |

### Handler Tests (11)

| Test | Description |
|------|-------------|
| `testHandleReturnsHandledEventClass` | Correct event class mapping |
| `testHandleSkipsNonCaptureRequestEvents` | Ignores unrelated events |
| `testHandleSetsErrorWhenContractIdMissing` | Error on missing contract ID |
| `testHandleSetsErrorWhenContractNotFound` | Error on unknown contract |
| `testHandleSetsErrorWhenContractNotInAuthorizedState` | Error on wrong state |
| `testHandleSetsErrorWhenNoPaymentIntentFound` | Error on missing PI |
| `testHandleSuccessfulCapture` | Full capture flow |
| `testHandleUsesPaymentIntentIdFromEvent` | PI from event override |
| `testHandlePassesPartialAmount` | Partial capture support |
| `testHandleSetsErrorOnException` | Error handling on API failure |

---

## Code Quality

| Check | Status | Notes |
|-------|--------|-------|
| PHPUnit Unit Tests | PASS | 1398 tests (+24 new) |
| PHP CodeSniffer (PSR-12) | PASS | |
| PHPStan Level 6 | WARNING | Pre-existing controller issues |
| PHPMD | WARNING | Pre-existing PaymentContract complexity |

---

## Event Flow Diagram

```
┌─────────────────────────────────────┐
│ Admin clicks "Capture Payment"      │
│         OR                          │
│ External trigger (API/webhook/cron) │
└─────────────────┬───────────────────┘
                  │
                  ▼
┌─────────────────────────────────────┐
│ EventContext([                      │
│   'contractId' => 'contract_123',   │
│   'initiator' => 'admin',           │
│   'amount' => null // full capture  │
│ ])                                  │
└─────────────────┬───────────────────┘
                  │
                  ▼
┌─────────────────────────────────────┐
│ StripeCaptureRequestEvent           │
└─────────────────┬───────────────────┘
                  │
                  ▼
┌─────────────────────────────────────┐
│ StripeCaptureRequestHandler         │
│ 1. Validate contract in AUTHORIZED  │
│ 2. Get PaymentIntent ID             │
│ 3. Call StripeAdapter.capture()     │
│ 4. Transition to READY_TO_COMMIT    │
│ 5. Set success results in context   │
└─────────────────┬───────────────────┘
                  │
                  ▼
┌─────────────────────────────────────┐
│ Context results:                    │
│ - captureSuccess: true              │
│ - captureId: 'ch_xxx'               │
│ - capturedAmount: 99.99             │
│ - captureCurrency: 'EUR'            │
└─────────────────────────────────────┘
```

---

## State Transition

```
Before capture:
  Contract state: AUTHORIZED
  PaymentIntent status: requires_capture

After capture:
  Contract state: READY_TO_COMMIT
  PaymentIntent status: succeeded
```

---

## Usage Example

```php
// From admin controller:
$context = new EventContext([
    'contractId' => $contractId,
    'initiator' => 'admin',
    'amount' => null, // null = full capture
    'reason' => 'Order ready to ship',
]);

$event = new StripeCaptureRequestEvent($context);
$this->eventDispatcher->dispatch($event);

if ($context->get('captureSuccess')) {
    // Payment captured successfully
    $captureId = $context->get('captureId');
    $amount = $context->get('capturedAmount');
} else {
    // Handle error
    $error = $context->get('error');
}
```

---

## Commands Used

```bash
# Run capture request tests
docker compose exec php php vendor/bin/phpunit -c extensions/stripe/tests/phpunit.xml \
  --testsuite Unit --filter "StripeCaptureRequest"

# Pre-commit checks
./bin/pre-commit-check.sh
```

---

## Next Sprint

Sprint 5: Handle authorization in return flow (transition contract to AUTHORIZED when PaymentIntent status is requires_capture)
