# Sprint 82: STRP-118 Fix Manual Capture — Committed Contract Cannot Be Captured

**Date:** 2026-04-08
**Branch:** `b-7.4.x`
**Ticket:** STRP-118

## Problem

When an order is placed with **manual capture** payment intent (`capture_method: manual`), the admin panel shows:

> Error: Cannot capture: contract not in AUTHORIZED state (current: committed)

The capture button is visible (Stripe API confirms `requires_capture`), but clicking it fails because the `StripeCaptureRequestHandler` requires `AUTHORIZED` state while the contract is in `COMMITTED` state.

Additionally, the **refund section is shown** for uncaptured manual-capture orders, which is incorrect -- you cannot refund what hasn't been captured.

## Root Cause Analysis

### State Flow Bug

The checkout return flow for manual capture dispatches `PaymentAuthorizedEvent`, which triggers `PaymentAuthorizedEventHandler` (in payment-component). This handler:

1. Fulfills the `payment_authorized` condition on the contract
2. When all conditions are fulfilled, `PaymentContract::fulfillCondition()` auto-transitions to `READY_TO_COMMIT`
3. `StripeOrderCreationHandler` then commits the contract to order: `COMMITTED`

**Result:** Contract goes `NOT_FINISHED -> PENDING -> READY_TO_COMMIT -> COMMITTED`, **skipping `AUTHORIZED` entirely**.

The `PaymentContract::authorize()` method is never called because the payment-component's `PaymentAuthorizedEventHandler` doesn't distinguish between auto-capture and manual-capture flows.

### Why AUTHORIZED State Is Skipped

The `authorize()` transition requires `PENDING` state, but `fulfillCondition()` on a PENDING contract with all conditions met auto-transitions to `READY_TO_COMMIT`. There's no hook point to intercept this.

### Template Bug

`isOrderRefundable()` checks Stripe's charge data but doesn't consider whether the payment has been captured. For uncaptured orders, `amount_captured = 0` but the method still returns true if a charge exists.

## Fix Strategy

**Do NOT modify payment-component** (separate package). Fix in Stripe module only.

### Fix 1: StripeCaptureRequestHandler — accept COMMITTED state

The handler's `processCapture()` validates `isAuthorized()`. Change to also accept `isCommitted()` for manual capture orders where the contract reached COMMITTED without going through AUTHORIZED.

### Fix 2: CaptureService — handle COMMITTED -> FULFILLED transition

`CaptureService::transitionContractState()` calls `captureAuthorization()` (requires AUTHORIZED). Add branch:
- If AUTHORIZED: `captureAuthorization()` (existing flow)
- If COMMITTED: `fulfill()` (new flow for this bug)

### Fix 3: OrderRefund — hide refund for uncaptured orders

In `OrderRefund::isOrderRefundable()`, return `false` when `isOrderCapturable()` is true (payment not yet captured = nothing to refund).

## TDD Approach

### Unit Tests (RED first)

1. **StripeCaptureRequestHandler**: test capture succeeds when contract is in COMMITTED state
2. **CaptureService**: test `processCapture()` calls `fulfill()` for committed contracts
3. **OrderRefund**: test `isOrderRefundable()` returns false when `isOrderCapturable()` is true

### Integration Tests (RED first)

1. Contract in COMMITTED state with `requires_capture` PaymentIntent -> admin capture succeeds
2. After capture, contract transitions to FULFILLED

### E2E Playwright Tests (RED first)

1. Manual capture order: capture button works without error
2. Manual capture order (uncaptured): refund section is NOT visible
3. After capture: refund section becomes visible

## Subtasks

| # | Task | File(s) | Status | Acceptance Criteria |
|---|------|---------|--------|---------------------|
| 1 | Write failing unit tests | `tests/Unit/Stripe/EventSystem/Handler/StripeCaptureRequestHandlerTest.php`, `tests/Unit/Stripe/Service/CaptureServiceTest.php`, `tests/Unit/Stripe/Controller/Admin/OrderRefundTest.php` | pending | Tests fail with current code |
| 2 | Write failing integration test | `tests/Integration/Stripe/Controller/Admin/ManualCaptureIntegrationTest.php` | pending | Test fails with current code |
| 3 | Write failing Playwright test | `tests/e2e/playwright/playwright/tests/admin/stripe-manual-capture-fix.spec.ts` | pending | Test fails with current code |
| 4 | Fix StripeCaptureRequestHandler | `src/Stripe/EventSystem/Handler/StripeCaptureRequestHandler.php` | pending | Accept COMMITTED state |
| 5 | Fix CaptureService | `src/Stripe/Service/CaptureService.php` | pending | Handle COMMITTED->FULFILLED |
| 6 | Fix OrderRefund + template | `src/Stripe/Controller/Admin/OrderRefund.php` | pending | Hide refund when capturable |
| 7 | Verify all tests pass | - | pending | All new tests GREEN |

## Deliverables

- 3 unit test files (new/updated)
- 1 integration test file (new)
- 1 Playwright E2E spec (new)
- 3 source files modified
- Sprint completion report