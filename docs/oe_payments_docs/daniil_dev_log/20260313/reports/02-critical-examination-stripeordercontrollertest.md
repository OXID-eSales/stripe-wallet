# Report: Critical Examination of StripeOrderControllerTest

**Date:** 2026-03-13
**File under review:** `tests/Integration/Stripe/Controller/StripeOrderControllerTest.php`
**Related file:** `tests/Unit/Stripe/Controller/StripeOrderControllerRetryTest.php`
**Controller:** `src/Stripe/Controller/StripeOrderController.php`

---

## Summary

The test file has **structural problems** that make several tests non-informative, silently passing without proving what they claim. The core issue: the anonymous controller subclass overrides so many methods that the tests verify the stub behavior, not the controller logic. Additionally, several controller code paths have zero test coverage.

---

## Findings

### F1: CRITICAL — `captureMode` Default Test Is Faking Results

**Test:** `testCheckoutSessionContextContainsAutomaticCaptureModeByDefault`
**Claim:** "captureMode should default to 'automatic' when not specified"
**Reality:** The test proves nothing about defaults.

```php
// In createControllerWithMocks():
$helper->captureMode = $options['captureMode'] ?? 'automatic';  // line 348
```

The test omits `captureMode` from options, so the `?? 'automatic'` fallback in the **test helper setup** provides the value. The test is asserting the test's own default, not the controller's or the `ModuleConfigurationServiceInterface`'s. If someone changed the real config service default to `'manual'`, this test would still pass green.

**Severity:** Non-informative — faking a result.

### F2: HIGH — `testExecuteReturnsRedirectFromContext` Doesn't Assert Error Was Displayed

**Test:** `testExecuteReturnsRedirectFromContext`
**Claim:** Tests that the controller returns redirect from context (payment failure scenario).
**Gap:** The test sets `error: 'Payment failed'` in context and verifies redirect target is `'payment'`, but never asserts that `addErrorToDisplay()` was called. The `processContextResults()` method handles error display (line 346 of controller), but no test verifies this path. The `StubControllerRequestHelper` tracks `$lastError` but it's never read.

**Severity:** Insufficient — error display path is untested.

### F3: HIGH — `processContextResults()` Has Zero Direct Test Coverage

The `processContextResults()` method handles 3 distinct paths:
1. **3DS requirement** → sets `stripe3DSRequired`, `stripeClientSecret`, `paymentIntentId` as template params
2. **Error display** → calls `addErrorToDisplay()`
3. **Order success** → sets `sess_challenge` session variable

None of these are verified in any test. The anonymous class overrides `addTplParam()` to store in `$this->tplParams` (line 394-397), but `$tplParams` is private to the anonymous class and never asserted. It's dead test infrastructure.

**Severity:** Absent edge case testing — 3DS flow is completely untested.

### F4: HIGH — `checkoutSuccess` Token Validation Edge Cases Missing

The controller's `checkoutSuccess()` has 4 validation gates:
1. Missing session ID → tested ✓
2. Non-string contractId/contractToken → **not tested**
3. Invalid contract token → **not tested**
4. Contract ID mismatch (URL vs session) → **not tested**

The `StubControllerRequestHelper` always returns `tokenValidationResult = true` (line 346). No test sets it to `false`. No test sends `contractId` in the request that differs from `contractIdFromSession`. These are security-critical paths (Sprint 67a H3) with no test coverage.

**Severity:** Absent — security validation paths untested.

### F5: MEDIUM — Test Class Labelled "Integration" But Is Actually a Unit Test

The class lives in `tests/Integration/` and the file docblock says "Unit tests for StripeOrderController." It uses no database, no OXID bootstrap, no DI container — it's purely mocked. This is a unit test in an integration test directory.

The classification matters: integration test runs are slower and sometimes skipped in quick CI. If this is miscategorised, developers might not run it during rapid TDD cycles.

**Severity:** Misleading — doesn't affect correctness but breaks test taxonomy.

### F6: MEDIUM — `checkoutCancel()` Not Tested In This File

`checkoutCancel()` (lines 279-295 of controller) has test coverage only in `StripeOrderControllerRetryTest`. But `StripeOrderControllerTest` claims to be the main controller test file covering all endpoints. A developer reading this file would assume all public methods are tested here.

`StripeOrderControllerRetryTest` does test `checkoutCancel` but doesn't test:
- Cleanup failure (exception thrown by `cleanupPreviousAttempt`) — the controller has a try/catch that logs and continues. No test verifies this graceful degradation.

**Severity:** Missing edge case.

### F7: MEDIUM — `createCheckoutSession` Error Paths Untested

The controller's `createCheckoutSession()` has these error paths:
1. Session challenge fails → 403 JSON → **not tested**
2. API key validation fails → RuntimeException → caught → error JSON → **not tested**
3. Empty basket → RuntimeException → caught → error JSON → **not tested**
4. No user → RuntimeException → caught → error JSON → **not tested**
5. Event handler throws → caught → error JSON → **not tested**

Only the happy path is tested. The `catch (\Throwable)` block is the exact mechanism that silently swallowed the Sprint 73 bug — proving these paths need coverage.

**Severity:** Missing — the catch-all hides failures.

### F8: MEDIUM — User Mock Inconsistency Between `getUser()` and Basket

In `createControllerWithMocks()`, two separate User mocks can be created:
- Line 331: `createUserMock()` for `getUser()` override
- Line 458: `createMock(User::class)` inside `createBasketMock()` for `basket->getBasketUser()`

These are different mock instances. In the real controller, `getUser()` (line 361-367) delegates to `basket->getBasketUser()` — they return the same object. The test creates two different users with the same ID, hiding any bugs that depend on object identity (`===`).

**Severity:** Non-informative — could mask identity-dependent bugs.

### F9: LOW — `executeStripePayment` Session Expired Path Untested

The stub always sets `sessionChallengeResult = true` (line 346). No test verifies `executeStripePayment()` returns `'payment'` when session challenge fails. The retry test file tests this for `createCheckoutSession` but not for `executeStripePayment`.

**Severity:** Missing edge case.

### F10: LOW — `stripeReturn` Context Data Not Verified

`testStripeReturnDispatchesEvent` verifies the event type and redirect result, but never checks that `redirectStatus` and `contractId` are passed into the context. Compare with `testContextContainsBasketData` which does verify context contents for `executeStripePayment`. The same pattern should apply to `stripeReturn`.

**Severity:** Insufficient — dispatches event but doesn't verify data correctness.

---

## Summary Table

| # | Severity | Finding | Type |
|---|----------|---------|------|
| F1 | CRITICAL | captureMode default test asserts its own stub default | Faking results |
| F2 | HIGH | Error display path never asserted | Insufficient |
| F3 | HIGH | processContextResults() completely untested (3DS, errors, orderId) | Absent |
| F4 | HIGH | checkoutSuccess token validation edge cases missing | Absent (security) |
| F5 | MEDIUM | Integration test is actually a unit test | Misleading |
| F6 | MEDIUM | checkoutCancel cleanup failure path untested | Missing edge case |
| F7 | MEDIUM | createCheckoutSession error/validation paths untested | Missing edge cases |
| F8 | MEDIUM | Two different User mocks hide identity bugs | Non-informative |
| F9 | LOW | executeStripePayment expired session untested | Missing edge case |
| F10 | LOW | stripeReturn context data not verified | Insufficient |

**CRITICAL:** 1 | **HIGH:** 3 | **MEDIUM:** 4 | **LOW:** 2
