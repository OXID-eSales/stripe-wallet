# Sprint 7: Webhook Handler for charge.captured - Completion Report

**Status:** COMPLETED
**Date:** 2025-12-16
**Duration:** ~15 minutes

---

## Summary

Enhanced the webhook handler to properly handle `charge.captured` events for contracts in AUTHORIZED state (manual capture mode). When Stripe sends a `charge.captured` webhook after a payment is captured (either via admin UI or Stripe Dashboard), the contract transitions from AUTHORIZED to READY_TO_COMMIT.

---

## Files Modified

### 1. WebhookContractFulfillmentHandler.php

**File:** `src/Stripe/Handler/WebhookContractFulfillmentHandler.php`

Updated `handleChargeCaptured()` method to:
- Detect contracts in AUTHORIZED state
- Transition AUTHORIZED -> READY_TO_COMMIT when capture webhook arrives
- Record captured amount and timestamp on contract
- Save contract state change

Key code addition:
```php
// Sprint 7: Handle manual capture mode - AUTHORIZED -> READY_TO_COMMIT
if ($contract->getState()->isAuthorized() && $contract instanceof PaymentContract) {
    $contract->captureAuthorization();
    $this->contractRepository->save($contract);
    // After capture, contract is READY_TO_COMMIT
    return true;
}
```

### 2. WebhookContractFulfillmentHandlerTest.php

**File:** `tests/Unit/Stripe/Handler/WebhookContractFulfillmentHandlerTest.php`

Added 2 new tests:
- `handlerTransitionsAuthorizedContractOnCapture()` - Verifies AUTHORIZED -> READY_TO_COMMIT transition
- `handlerReturnsNullWhenContractNotFoundOnCapture()` - Verifies null return when contract not found

### 3. StripeCaptureRequestHandler.php (PHPStan fix)

**File:** `src/Stripe/EventSystem/Handler/StripeCaptureRequestHandler.php`

Fixed PHPStan errors by:
- Adding `PaymentContractInterface` import
- Updating method signatures from `object $contract` to `PaymentContractInterface $contract`

---

## Test Results

```
PHPUnit 11.5.44
Tests: 1403, Assertions: 3340
Status: OK (2 new tests added)

New tests:
- handlerTransitionsAuthorizedContractOnCapture (6 assertions)
- handlerReturnsNullWhenContractNotFoundOnCapture (2 assertions)
```

---

## Code Quality

| Check | Status | Notes |
|-------|--------|-------|
| PHPUnit Unit Tests | PASS | 1403 tests |
| PHP CodeSniffer (PSR-12) | PASS | |
| PHPStan Level 6 | WARNING | Pre-existing controller issues |
| PHPMD | WARNING | Pre-existing PaymentContract complexity |

---

## Webhook Flow

### When `charge.captured` Webhook Arrives

```
Stripe sends charge.captured webhook
        |
WebhookController receives event
        |
WebhookProcessingService processes event
        |
WebhookContractFulfillmentHandler.handleChargeCaptured()
        |
Find contract by providerOrderId (PaymentIntent ID)
        |
┌───────────────────────────────────────────────────┐
│ Contract State?                                    │
├───────────────────────────────────────────────────┤
│ AUTHORIZED:                                        │
│   - captureAuthorization() -> READY_TO_COMMIT     │
│   - Save contract                                  │
│   - Return true                                    │
├───────────────────────────────────────────────────┤
│ FULFILLED:                                         │
│   - Already fulfilled (idempotent)                 │
│   - Save captured amount if changed                │
│   - Return false                                   │
├───────────────────────────────────────────────────┤
│ COMMITTED:                                         │
│   - Use ContractFulfillmentService.fulfill()       │
│   - Return true if fulfilled                       │
├───────────────────────────────────────────────────┤
│ Other states:                                      │
│   - Save captured amount if > 0                    │
│   - Return false                                   │
└───────────────────────────────────────────────────┘
```

---

## State Machine Update

The delayed capture flow now properly supports external capture via webhooks:

```
Manual Capture Mode Flow:
=========================

1. Checkout creates PaymentIntent with capture_method='manual'
2. Customer authorizes payment (PaymentIntent status: requires_capture)
3. Return handler transitions contract: PENDING -> AUTHORIZED
4. Admin captures payment (via admin UI or Stripe Dashboard)
5. Stripe sends charge.captured webhook
6. Webhook handler transitions: AUTHORIZED -> READY_TO_COMMIT
7. Subsequent flow commits and fulfills contract
```

---

## Integration Points

1. **WebhookProcessingService** - Routes charge.captured events to handler
2. **ContractRepository** - Persists state transitions
3. **ContractFulfillmentService** - Handles fulfillment for already-committed contracts
4. **PaymentContract.captureAuthorization()** - State transition method (Sprint 1)

---

## Acceptance Criteria

- [x] Webhook handler detects AUTHORIZED contracts
- [x] Transitions AUTHORIZED -> READY_TO_COMMIT on capture
- [x] Records captured amount on contract
- [x] Idempotent - already-fulfilled contracts return false
- [x] Null return when contract not found
- [x] Unit tests pass
- [x] PHPStan errors fixed for StripeCaptureRequestHandler

---

## Test Commands

```bash
# Run unit tests
docker compose exec php php vendor/bin/phpunit -c extensions/stripe/tests/phpunit.xml --testsuite Unit

# Run Sprint 7 tests specifically
docker compose exec php php vendor/bin/phpunit -c extensions/stripe/tests/phpunit.xml \
  --filter "Authorized" extensions/stripe/tests/Unit/Stripe/Handler/WebhookContractFulfillmentHandlerTest.php

# Pre-commit checks
./bin/pre-commit-check.sh
```

---

## Next Sprint

Sprint 8: Unit tests - Comprehensive test coverage for delayed capture feature

