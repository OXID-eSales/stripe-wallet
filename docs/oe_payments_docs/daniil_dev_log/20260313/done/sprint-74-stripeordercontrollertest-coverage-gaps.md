# Sprint 74: StripeOrderControllerTest Coverage Gaps

**Date:** 2026-03-13
**Ticket:** STRP-100 (test quality)
**Branch:** `b-7.4.x-cancelled-order-STRP-100`
**Report:** `reports/02-critical-examination-stripeordercontrollertest.md`

---

## Problem Summary

10 findings (1 CRITICAL, 3 HIGH, 4 MEDIUM, 2 LOW) in `StripeOrderControllerTest`. Tests are faking results, missing security-critical validation paths, and leaving `processContextResults()` completely unverified. The test file also lives in `Integration/` but is a pure unit test.

## Approach

- Fix in the existing test file (move it to Unit first)
- No new test classes — extend `createControllerWithMocks` where needed
- Expose `$tplParams` and `$lastError` for assertions where the anonymous class already captures them
- Follow TDD: each step adds a **failing** test first, then adjusts the test infrastructure to make it meaningful

---

## Steps

### Step 1: Move test file from Integration/ to Unit/ (F5)

**What:**
- Move `tests/Integration/Stripe/Controller/StripeOrderControllerTest.php` → `tests/Unit/Stripe/Controller/StripeOrderControllerTest.php`
- Update namespace from `Tests\Integration\Stripe\Controller` → `Tests\Unit\Stripe\Controller`
- Update `phpunit.xml` if needed (check testsuite paths)

**Why:** The test has no DB, no OXID bootstrap, no DI container. It belongs in Unit.

**Verification:** `--testsuite Unit` still picks it up, `--testsuite Integration` no longer includes it.

---

### Step 2: Fix captureMode default test — assert real config service default (F1)

**What:**
The current test is non-informative because the stub setup does `$options['captureMode'] ?? 'automatic'` — the test asserts its own hardcoded fallback.

Fix: remove the `?? 'automatic'` fallback from `createControllerWithMocks`. Instead, the `StubControllerRequestHelper` should have a default `captureMode` that matches the real `ModuleConfigurationService::getCaptureMode()` default (which is `'automatic'` — see `ModuleConfigurationService.php:268`).

The stub already sets `public string $captureMode = 'automatic'` at line 26 of `StubControllerRequestHelper.php`. So the fix is:

```php
// BEFORE (faking):
$helper->captureMode = $options['captureMode'] ?? 'automatic';

// AFTER (honest):
if (isset($options['captureMode'])) {
    $helper->captureMode = $options['captureMode'];
}
```

Now `testCheckoutSessionContextContainsAutomaticCaptureModeByDefault` asserts the stub's built-in default (which mirrors the real service default), and only `testCheckoutSessionContextContainsCaptureModeFromConfig` overrides it to `'manual'`.

**Why:** The test must prove the controller passes through whatever the helper provides, without the test setup injecting the expected answer.

**Verification:** Both captureMode tests still pass. Temporarily change stub default to `'manual'` — the "automatic by default" test should fail.

---

### Step 3: Add `processContextResults()` tests — 3DS, error display, orderId (F3, F2)

**What:** Add 3 tests that verify `processContextResults()` side effects by inspecting the anonymous class state after the event handler sets context values.

Expose `$tplParams` and make `$lastError` accessible from the anonymous class:
- Change `private array $tplParams` → `public array $tplParams` in the anonymous class
- Store a reference to `$helper` (StubControllerRequestHelper) so tests can read `$helper->lastError`

**Test 3a:** `testExecuteStripePaymentSets3DSTemplateParams`
```
Arrange: dispatch callback sets context:
  - requires3DS → true
  - clientSecret → 'secret_123'
  - paymentIntentId → 'pi_3ds'
  - redirectTarget → 'order'
Act: executeStripePayment()
Assert:
  - tplParams['stripe3DSRequired'] === true
  - tplParams['stripeClientSecret'] === 'secret_123'
  - tplParams['paymentIntentId'] === 'pi_3ds'
```

**Test 3b:** `testExecuteStripePaymentDisplaysErrorFromContext`
```
Arrange: dispatch callback sets context:
  - error → 'Card declined'
  - redirectTarget → 'payment'
Act: executeStripePayment()
Assert:
  - helper->lastError === 'Card declined'
```

**Test 3c:** `testExecuteStripePaymentSetsOrderIdInSession`
```
Arrange: dispatch callback sets context:
  - orderId → 'order_abc'
  - redirectTarget → 'thankyou'
Act: executeStripePayment()
Assert:
  - helper->sessionVars['sess_challenge'] === 'order_abc'
```

**Why:** `processContextResults()` is the controller's post-dispatch orchestration. It handles 3DS, errors, and session state. All 3 paths are completely untested.

---

### Step 4: Add `checkoutSuccess` security validation tests (F4)

**What:** 4 tests covering the validation gates in `checkoutSuccess()`:

**Test 4a:** `testCheckoutSuccessReturnsPaymentOnMissingContractId`
```
Arrange: sessionId='cs_test', contractId=null, contractToken='token_123'
Assert: returns 'payment', dispatch never called
```

**Test 4b:** `testCheckoutSuccessReturnsPaymentOnMissingContractToken`
```
Arrange: sessionId='cs_test', contractId='contract_123', contractToken=null
Assert: returns 'payment', dispatch never called
```

**Test 4c:** `testCheckoutSuccessReturnsPaymentOnInvalidToken`
```
Arrange: sessionId='cs_test', contractId='contract_123', contractToken='bad_token'
         helper->tokenValidationResult = false
Assert: returns 'payment', dispatch never called
```

**Test 4d:** `testCheckoutSuccessReturnsPaymentOnContractIdMismatch`
```
Arrange: sessionId='cs_test'
         contractIdFromRequest='contract_from_url'
         contractIdFromSession='contract_from_session' (different!)
         contractToken='valid_token', tokenValidationResult=true
Assert: returns 'payment', dispatch never called
```

Note: For 4d, the `createControllerWithMocks` currently sets both `contractIdFromRequest` and `contractIdFromSession` from the same `$options['contractId']` key. Need to allow setting them independently:
```php
$helper->contractIdFromRequest = $options['contractIdFromRequest'] ?? $options['contractId'] ?? null;
$helper->contractIdFromSession = $options['contractIdFromSession'] ?? $options['contractId'] ?? null;
```

**Why:** These are Sprint 67a H3 security paths — token validation before business logic. Zero coverage means a regression could silently disable token checks.

---

### Step 5: Add `createCheckoutSession` error path tests (F7)

**What:** 4 tests for error paths in `createCheckoutSession()`:

**Test 5a:** `testCreateCheckoutSessionReturns403OnExpiredSession`
```
Arrange: helper->sessionChallengeResult = false
Act: createCheckoutSession()
Assert: output JSON has 'error' key containing 'Session expired'
```

**Test 5b:** `testCreateCheckoutSessionReturnsErrorOnInvalidApiKey`
```
Arrange: ConfigurationValidator->getKeyValidationError() returns 'Invalid key'
         basketNotEmpty=true, hasUser=true
Act: createCheckoutSession()
Assert: output JSON has 'error' key, dispatch never called
```

**Test 5c:** `testCreateCheckoutSessionReturnsErrorOnEmptyBasket`
```
Arrange: basketNotEmpty=false, hasUser=true
Act: createCheckoutSession()
Assert: output JSON has 'error' key, dispatch never called
```

**Test 5d:** `testCreateCheckoutSessionReturnsErrorOnNoUser`
```
Arrange: basketNotEmpty=true, hasUser=false
Act: createCheckoutSession()
Assert: output JSON has 'error' key, dispatch never called
```

For 5b, the `getServiceFromContainer` override needs to be configurable for `ConfigurationValidatorInterface`. Add an `$options['keyValidationError']` that the anonymous class reads:
```php
if ($serviceName === ConfigurationValidatorInterface::class) {
    $error = $this->options['keyValidationError'] ?? null;
    return new class ($error) {
        public function __construct(private ?string $error) {}
        public function getKeyValidationError(): ?string { return $this->error; }
        public function validateKeyPair(): bool { return $this->error === null; }
    };
}
```

**Why:** The catch-all `catch (\Throwable)` is exactly what hid the Sprint 73 bug. Testing error paths ensures future service additions don't silently break.

---

### Step 6: Add `checkoutCancel` cleanup failure test (F6)

**What:** 1 test in `StripeOrderControllerRetryTest` (where the other cancel tests live):

**Test 6a:** `testCheckoutCancelContinuesOnCleanupFailure`
```
Arrange: contractIdFromSession='contract_fail'
         cleanupService->cleanupPreviousAttempt() throws RuntimeException
Act: checkoutCancel()
Assert:
  - returns 'payment' (graceful degradation)
  - session variables still cleared (clearStripeSessionVariables called)
```

**Why:** The controller has a try/catch around cleanup. If cleanup throws, the user should still be redirected to payment page and session should be cleared.

---

### Step 7: Add missing edge case tests (F8, F9, F10)

**Test 7a (F9):** `testExecuteStripePaymentReturnsPaymentOnExpiredSession`
```
Arrange: helper->sessionChallengeResult = false, paymentIntentId='pi_test'
Act: executeStripePayment()
Assert: returns 'payment', dispatch never called
```

**Test 7b (F10):** `testStripeReturnContextContainsRedirectStatusAndContractId`
```
Arrange: paymentIntentId='pi_test', redirectStatus='succeeded', contractId='contract_123'
         dispatch callback captures context
Act: stripeReturn()
Assert:
  - capturedContext->get('redirectStatus') === 'succeeded'
  - capturedContext->get('contractId') === 'contract_123'
  - capturedContext->get('paymentIntentId') === 'pi_test'
```

**Test 7c (F8):** Fix user mock inconsistency — not a new test but a refactor of `createControllerWithMocks`:
```php
// Use basket user as the controller's getUser() return value
// instead of creating a separate mock
```

In the anonymous class, change `getUser()` to delegate to the basket mock's user:
```php
public function getUser(): ?User
{
    if (!($this->options['hasUser'] ?? true)) {
        return null;
    }
    $basketUser = $this->stubHelper->basket?->getBasketUser();
    return $basketUser instanceof User ? $basketUser : null;
}
```

Remove the separate `createUserMock()` call at line 331. This ensures `getUser()` and `basket->getBasketUser()` return the same object instance, matching real controller behavior.

**Why:** Consistency between test and production object graphs prevents false positives.

---

### Step 8: Run full pre-commit check

```bash
docker compose exec -T php php vendor/bin/phpunit -c extensions/stripe/tests/phpunit.xml
```

**Expected:** All new tests pass. No regressions.

---

## Test Count Summary

| Step | New Tests | Finding |
|------|-----------|---------|
| 1 | 0 (move) | F5 |
| 2 | 0 (fix) | F1 |
| 3 | 3 | F3, F2 |
| 4 | 4 | F4 |
| 5 | 4 | F7 |
| 6 | 1 | F6 |
| 7 | 2 + refactor | F8, F9, F10 |
| 8 | 0 (verify) | — |
| **Total** | **14 new tests** | **10 findings** |

## Principles

- **TDD:** Each step writes the test first, then adjusts infrastructure
- **No overengineering:** Reuse existing `createControllerWithMocks` / anonymous class pattern. No new abstractions
- **DI:** Mock interfaces, not concretions
- **Clean code:** Small focused tests, AAA pattern, descriptive names
- **DRY:** Extend options array rather than duplicating helper creation
- **LSP:** Anonymous class must faithfully mirror real controller's delegation patterns (F8 fix)
