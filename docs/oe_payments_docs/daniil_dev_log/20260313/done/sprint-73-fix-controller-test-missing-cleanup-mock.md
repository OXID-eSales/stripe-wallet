# Sprint 73: Fix StripeOrderControllerTest Missing RetryCleanupService Mock

**Date:** 2026-03-13
**Ticket:** STRP-100 (continuation)
**Branch:** `b-7.4.x-cancelled-order-STRP-100`
**Report:** `reports/01-strp100-test-failures-after-retry-cleanup.md`

---

## Problem Summary

STRP-100 added `cleanupPreviousCheckoutAttempt()` to `createCheckoutSession()`, which resolves `RetryCleanupService` from the DI container. The test's anonymous controller subclass doesn't handle this service, causing 3 test failures.

## Fix

**Single change:** Add `RetryCleanupService::class` to the test's `getServiceFromContainer()` in `StripeOrderControllerTest`.

The mock service needs minimal behavior — a no-op stub that satisfies the type contract. The cleanup method receives `null` contractId in tests (StubControllerRequestHelper has no session state), so `cleanupPreviousAttempt(null)` returns `false` immediately. The fallback `cleanupForUser()` path needs a stub too since `getUser()` returns a mock user.

---

## Steps

### Step 1: Add RetryCleanupService mock to getServiceFromContainer

**File:** `tests/Integration/Stripe/Controller/StripeOrderControllerTest.php`

**What:**
- Add `use` import for `RetryCleanupService`
- Add a case in `getServiceFromContainer()` for `RetryCleanupService::class`
- Return a mock with `cleanupPreviousAttempt()` → `false` and `cleanupForUser()` → `false`

**Why:** The controller's `createCheckoutSession()` now requires this service. Without it, the try/catch swallows the RuntimeException and returns error JSON.

**Verification:** Run the 3 failing tests:
```bash
docker compose exec -T php php vendor/bin/phpunit -c extensions/stripe/tests/phpunit.xml \
  --filter "testCreateCheckoutSessionDispatchesEvent|testCheckoutSessionContextContainsCaptureModeFromConfig|testCheckoutSessionContextContainsAutomaticCaptureModeByDefault" \
  extensions/stripe/tests/Integration/Stripe/Controller/StripeOrderControllerTest.php
```

### Step 2: Run full pre-commit check

```bash
docker compose exec -w /var/www/extensions/stripe -T php ./bin/pre-commit-check.sh --full
```

**Expected:** 921 tests, 0 failures.

---

## Principles Applied

- **TDD:** Tests must pass before code ships — this is a test gap from STRP-100
- **DI:** Mock the service interface, not the concrete implementation details
- **No overengineering:** Minimal stub, no new test classes or abstractions
- **SOLID (LSP):** The mock satisfies the same contract as the real service
