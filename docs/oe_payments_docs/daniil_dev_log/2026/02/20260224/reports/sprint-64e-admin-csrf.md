# Sprint 64e — CSRF on Admin Endpoints (H8)

**Date:** 2026-02-24
**Status:** DONE
**Finding:** H8 (No CSRF on Payment Endpoints — admin part)

## Summary

Added `validateCsrfToken()` to `OrderRefund` admin controller. All three state-changing actions (fullRefund, capturePayment, cancelAuthorization) now check `checkSessionChallenge()` before proceeding.

## Changes

### Modified (1)
- `src/Stripe/Controller/Admin/OrderRefund.php` — Added `validateCsrfToken()` method, added CSRF check at start of `fullRefund()`, `capturePayment()`, `cancelAuthorization()`

### Created (1)
- `tests/Unit/Stripe/Controller/Admin/OrderRefundCsrfTest.php` — 6 tests with `TestableOrderRefundForCsrf` subclass

## Test Results

```
Tests: 6, Assertions: 12, Failures: 0
```

## Issues Resolved
- `OrderActionDispatcher` is `final` — can't subclass for mocking, so testable subclass overrides all 3 action methods to short-circuit at order null check
- Return type `?Order` must match parent declaration
