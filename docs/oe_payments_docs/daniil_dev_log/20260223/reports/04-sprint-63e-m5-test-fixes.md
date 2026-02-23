# Sprint 63e Completion Report — M5 Test Fixes (State Guard Alignment)

**Date:** 2026-02-23
**Branch:** `b-7.4.x-security-STRP-99`
**Finding:** M5 — No State Guard on Amounts (tests broken by pre-existing fix)

---

## Summary

The M5 fix (`PaymentContract::setCapturedAmount()` state guard requiring COMMITTED or FULFILLED) was already applied in payment-component but 7 tests across both modules were not updated. This sprint aligned all tests with the state guard behavior.

---

## Root Cause

`PaymentContract::setCapturedAmount()` throws `DomainException` unless the contract is in COMMITTED or FULFILLED state. Several tests were calling it on contracts in DRAFT, AUTHORIZED, or PENDING states.

---

## Changes Made

### Stripe Module (5 fixes)

| File | Change |
|------|--------|
| `src/Stripe/Service/StripeRefundService.php` | Added `is_finite()` check before partial refund check — ensures INF/NAN reaches parent's "finite number" error message |
| `src/Stripe/Adapter/OxidSessionAdapter.php` | Added `setBasket()` and `setUser()` methods (missing from `SessionAdapterInterface`) |
| `tests/Unit/Stripe/Handler/WebhookContractFulfillmentHandlerTest.php` | Removed `getCapturedAmount()` assertions for AUTHORIZED and PENDING state tests; changed PENDING save expectation to `never()` |
| `tests/Integration/Stripe/Webhook/DelayedCaptureIntegrationTest.php` | Same: removed amount assertions for AUTHORIZED/PENDING; changed PENDING save to `never()` |

### payment-component Module (4 fixes)

| File | Change |
|------|--------|
| `tests/Integration/Contract/ContractCaptureRefundTest.php` | Added `transitionToCommitted()` and `transitionToFulfilled()` helpers; each test now transitions contract to valid state before `setCapturedAmount()` |

---

## Test Results

### Stripe Module
- **822 tests, 2300 assertions, 0 failures**
- PHPCS: 0 errors
- PHPStan (level max): 0 errors
- PHPMD: 0 new violations
- **Status: COMMITABLE**

### payment-component Module
- **76 tests, 261 assertions, 0 failures** (1 skipped)

---

## Design Decision

The handler (`WebhookContractFulfillmentHandler::handleChargeCaptured`) was already correct — it only calls `recordCapturedAmount()` in FULFILLED or COMMITTED states. The tests were asserting the old pre-guard behavior where amounts could be set in any state. The fix was test-only for the handler; the `StripeRefundService` needed a minor code fix for correct error message ordering.
