# Sprint 82: STRP-118 Fix Manual Capture -- Committed Contract Cannot Be Captured

**Date:** 2026-04-08
**Branch:** `b-7.4.x`
**Ticket:** STRP-118
**Status:** done

## Objective

Fix two bugs in the admin manual capture flow:
1. Capture fails with "Cannot capture: contract not in AUTHORIZED state (current: committed)"
2. Refund section shown for uncaptured manual-capture orders (nothing to refund)

## Root Cause

When a manual capture order returns from Stripe Checkout, the contract state transitions:
`NOT_FINISHED -> PENDING -> READY_TO_COMMIT -> COMMITTED`, **skipping AUTHORIZED**.

This happens because `PaymentAuthorizedEventHandler` (payment-component) fulfills the `payment_authorized` condition, which auto-transitions to `READY_TO_COMMIT` via `fulfillCondition()`. The `authorize()` method is never called.

The `StripeCaptureRequestHandler` only accepted `AUTHORIZED` state for capture, rejecting `COMMITTED`.

## Subtasks

| # | Task | File(s) | Status | Acceptance Criteria |
|---|------|---------|--------|---------------------|
| 1 | Write failing unit tests (TDD RED) | `StripeCaptureRequestHandlerTest.php`, `CaptureServiceTest.php`, `OrderRefundVisibilityTest.php` | done | 3 handler tests + 2 service tests + 4 visibility tests |
| 2 | Write failing integration test (TDD RED) | `ManualCaptureIntegrationTest.php` | done | 3 tests: committed capture, authorized regression, reject non-capturable |
| 3 | Write Playwright E2E spec | `stripe-manual-capture-fix.spec.ts` | done | 4 tests: no error on load, refund hidden, capture success, refund visible after |
| 4 | Fix StripeCaptureRequestHandler | `StripeCaptureRequestHandler.php:120` | done | Accept both AUTHORIZED and COMMITTED states |
| 5 | Fix CaptureService | `CaptureService.php:103-112` | done | AUTHORIZED->captureAuthorization(), COMMITTED->fulfill() |
| 6 | Fix OrderRefund | `OrderRefund.php:158-163` | done | isOrderRefundable() returns false when isOrderCapturable() |
| 7 | Update existing tests for new behavior | `StripeCaptureRequestHandlerTest.php`, `CaptureServiceTest.php` | done | Error message updated, mock state added |

## Files Changed

### Source (3 files)

```
src/Stripe/EventSystem/Handler/StripeCaptureRequestHandler.php  # Accept COMMITTED state
src/Stripe/Service/CaptureService.php                            # COMMITTED->fulfill() branch
src/Stripe/Controller/Admin/OrderRefund.php                      # Hide refund when capturable
```

### Tests (4 files)

```
tests/Unit/Stripe/EventSystem/Handler/StripeCaptureRequestHandlerTest.php  # +3 tests, 1 updated
tests/Unit/Stripe/Service/CaptureServiceTest.php                           # +2 tests, 2 updated
tests/Unit/Stripe/Controller/Admin/OrderRefundVisibilityTest.php           # NEW: 4 tests
tests/Integration/Stripe/Controller/Admin/ManualCaptureIntegrationTest.php # NEW: 3 tests
tests/e2e/playwright/playwright/tests/admin/stripe-manual-capture-fix.spec.ts  # NEW: 4 tests
```

## Test Results

```
Unit:        809 tests, 1937 assertions, 0 failures
Integration: 30 tests, 120 assertions, 0 failures (Controller/Admin + Webhook)
PHPStan:     0 new errors (6 pre-existing in other files)
```

## Lessons Learned

- **Contract state machine can skip states**: The `fulfillCondition()` auto-transition to READY_TO_COMMIT means the `authorize()` step is only reached if the handler explicitly calls it. When payment-component handles `PaymentAuthorizedEvent`, it fulfills conditions, not authorizes.
- **Fix in Stripe module, not payment-component**: Rather than modifying the shared payment-component to be aware of manual capture, the Stripe module's handler and service accept both AUTHORIZED and COMMITTED states. This is safer and avoids cross-package changes.
- **COMMITTED + requires_capture = valid capture scenario**: The key insight is that Stripe's PaymentIntent status (`requires_capture`) is the source of truth for whether capture is needed, not the contract state alone.
