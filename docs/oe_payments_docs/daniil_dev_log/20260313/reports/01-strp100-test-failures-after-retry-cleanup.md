# Report: STRP-100 Test Failures After Retry Cleanup Integration

**Date:** 2026-03-13
**Branch:** `b-7.4.x-cancelled-order-STRP-100`
**Severity:** 3 integration test failures
**Sprint:** 73 (done)

---

## Solution

Added `RetryCleanupService::class` stub to the test's anonymous controller subclass in `getServiceFromContainer()`. The stub returns `false` from both `cleanupPreviousAttempt()` and `cleanupForUser()` — matching the real service's behavior when there is no previous contract to clean up.

**File changed:** `tests/Integration/Stripe/Controller/StripeOrderControllerTest.php`
- Added `use OxidEsales\Payments\Stripe\Service\RetryCleanupService`
- Added `RetryCleanupService::class` case returning a no-op anonymous class

**Results:** 3/3 failures fixed. 930 tests, 2529 assertions. PHPStan 0 errors (level max).

---

## Problem

After the STRP-100 back-navigation cleanup feature was merged, 3 integration tests in `StripeOrderControllerTest` fail:

1. `testCreateCheckoutSessionDispatchesEvent` — returns error JSON instead of session ID
2. `testCheckoutSessionContextContainsCaptureModeFromConfig` — `$capturedContext` is null
3. `testCheckoutSessionContextContainsAutomaticCaptureModeByDefault` — `$capturedContext` is null

All 3 tests exercise `createCheckoutSession()`.

## Root Cause

STRP-100 added `cleanupPreviousCheckoutAttempt()` at line 113 of `StripeOrderController::createCheckoutSession()`. This method calls:

```php
$cleanupService = $this->getServiceFromContainer(RetryCleanupService::class);
```

The test's anonymous controller subclass overrides `getServiceFromContainer()` but only handles `ConfigurationValidatorInterface::class`:

```php
protected function getServiceFromContainer(string $serviceName): object
{
    if ($serviceName === ConfigurationValidatorInterface::class) {
        return new class { ... };
    }
    throw new \RuntimeException("Unknown service: $serviceName");
}
```

When `RetryCleanupService::class` is requested, it throws `RuntimeException`, which is caught by the `catch (\Throwable $e)` block at line 163, producing the error JSON response. The event is never dispatched, so `$capturedContext` stays null.

## Fix

Add `RetryCleanupService::class` handling to the test's `getServiceFromContainer()` override. The mock needs no real behavior — `cleanupPreviousAttempt(null)` returns `false` early (no previous contractId in test session), and `cleanupForUser()` is only called in the fallback path.

## Impact

- 3 tests broken, 0 production code issues
- No behavioral change needed in `RetryCleanupService` or `StripeOrderController`
- Pure test gap: tests weren't updated when STRP-100 added the cleanup call
