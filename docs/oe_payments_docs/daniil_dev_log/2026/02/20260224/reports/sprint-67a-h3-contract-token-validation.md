# Sprint 67a — H3: Contract Token Validation in Controller

**Date:** 2026-02-24
**Status:** DONE
**Finding:** H3 — Contract Tokens Not Validated (CVSS 5.5, HIGH)
**Package:** stripe

## Problem

`ContractTokenService` exists with full HMAC-SHA256 token generation/validation (20 existing unit tests). However, `StripeOrderController::checkoutSuccess()` never called `validateToken()`. The URL parameters `contract_id` and `contract_token` passed through unvalidated into the `EventContext` and on to event handlers.

An attacker who intercepts or guesses a `contract_id` could craft a return URL that completes a different user's payment flow or links an order to the wrong session.

## Root Cause

The last-mile wiring was missing — the service was built and tested but never integrated into the controller's return flow.

## Fix

Added token validation as the **first** check in `checkoutSuccess()`, before session matching and before any event dispatching.

### Flow Change

```
BEFORE:
  1. Validate session_id
  2. Read contract_id + contract_token from URL (no validation)
  3. Match contract_id against session
  4. Dispatch event

AFTER:
  1. Validate session_id
  2. Read contract_id + contract_token from URL
  3. Reject if either is missing (non-string)
  4. Validate token via ContractTokenService::validateToken()   ← NEW
  5. Match contract_id against session
  6. Dispatch event
```

## Files Modified (1)

- `src/Stripe/Controller/StripeOrderController.php`
  - Added `use ContractTokenService` import
  - Rewrote `checkoutSuccess()` with token validation before event dispatch
  - Added `getContractIdFromRequest()` — extracts from `Registry::getRequest()`
  - Added `getContractTokenFromRequest()` — extracts from `Registry::getRequest()`
  - Added `validateContractToken(?string, ?string): bool` — delegates to `ContractTokenService`
  - Added `getContractTokenService(): ContractTokenService` — DI container resolution
  - Added `addErrorToDisplay(string): void` — testability wrapper for `Registry::getUtilsView()`

## Files Created (1)

### Tests
- `tests/Unit/Stripe/Controller/StripeOrderControllerTokenTest.php`
  - Testable subclass `TestableStripeOrderControllerForToken` overrides all framework methods
  - Tracks event dispatch via `wasEventDispatched()` flag
  - Controls token validation result via `setTokenValidationResult(bool)`

## Test Results

```
Tests: 5, Assertions: 11, Failures: 0
```

| # | Test | What It Proves |
|---|------|----------------|
| 1 | `checkoutSuccessRejectsInvalidContractToken` | Invalid token → `'payment'`, error shown, event NOT dispatched |
| 2 | `checkoutSuccessAcceptsValidContractToken` | Valid token → event IS dispatched |
| 3 | `checkoutSuccessRejectsMissingContractToken` | Null token → rejected before validation |
| 4 | `checkoutSuccessRejectsMissingContractId` | Null contract_id → rejected before validation |
| 5 | `checkoutSuccessValidatesTokenBeforeSessionCheck` | Token check runs BEFORE session mismatch check |

## SOLID Compliance

- **S**: `validateContractToken()` delegates to `ContractTokenService` — controller doesn't know HMAC details
- **O**: Existing CSRF tests unaffected — new subclass, new test file
- **L**: `ContractTokenService` implements `TokenServiceInterface` — substitutable
- **I**: Each new accessor method extracts one request parameter
- **D**: Controller depends on `ContractTokenService` via DI container, not direct instantiation
