# Sprint 114.13 — Completion Report

**Date:** 2026-05-27
**Branch:** `b-7.4.x-code-review-STRP-145`
**Commits:** 5 (ac015ef → e1652da)
**Ticket:** STRP-145 / Code Review 114 (final remediation sprint)

---

## R-1 … R-10 Checklist

| # | Requirement | Status | Evidence |
|---|---|---|---|
| R-1 | No `assertTrue(true)` survives in new/modified test files | DONE | `grep -r 'assertTrue(true)' tests/Unit/` — 0 results |
| R-2 | No `createMock(PaymentContract::class)` (concrete) anywhere | DONE | All 7 occurrences replaced with `createMock(PaymentContractInterface::class)` |
| R-3 | T4 self-verification tests deleted | DONE | 5 tests removed from `StripeWebhookTestHelperTest.php`; 5 contract tests kept |
| R-4 | O10: instanceof ladder replaced by mapping table | DONE | `StripeEventTranslator::EVENT_MAP` constant; 10 tests in new `StripeEventTranslatorTest.php` |
| R-5 | §8: OxidContractLinkedOrderUpdater covered | DONE | 7 tests in `OxidContractLinkedOrderUpdaterTest.php` via testable-subclass |
| R-6 | §8: OxidSessionWriter covered | DONE | 4 tests in `OxidSessionWriterTest.php` via `Registry::set(Session::class, mock)` |
| R-7 | §8: StripeCheckoutFooter covered | DONE | 8 tests in `StripeCheckoutFooterTest.php` via inner `TestableStripeCheckoutFooter` |
| R-8 | T6: creds-dependent tests gated via phpunit suite | DONE | `@group requires-stripe-creds` on all 4 live-Stripe test classes; excluded from `Integration` suite |
| R-9 | T6: container-boot is now a hard fail, not a skip | DONE | `markTestSkipped` removed from `PaymentPanelRegistryIntegrationTest` and `ModuleLifecycleTest` |
| R-10 | Full pre-commit gate passes (PHPCS + PHPStan + PHPMD + Unit + Integration) | DONE | See gate results below |

---

## Per-Finding Status

### T3 — Tautological assertion in OrderRefundViewDataProviderTest

**Finding:** Partial-capture regression tests were calling `assertEquals` on hardcoded strings, not actually exercising the provider's logic through a real resolver.

**Fix:** Injected real `StripeChargeAmountResolver` into regression tests. Added explicit delegation test with mock resolver to verify the provider passes the charge through. Removed tautological `assertEquals` calls.

**Files changed:**
- `tests/Unit/Stripe/Controller/Admin/OrderRefundViewDataProviderTest.php`

**Evidence:** `grep -n 'assertEquals.*partial\|assertEquals.*hardcoded' tests/Unit/Stripe/Controller/Admin/OrderRefundViewDataProviderTest.php` — 0 results.

---

### T4 — Test-of-test-helper self-verification in StripeWebhookTestHelperTest

**Finding:** 5 tests in `StripeWebhookTestHelperTest.php` were testing the helper's own signature verification logic — essentially re-testing `stripe/stripe-php`'s `WebhookSignature::verify()`. These tests add no value and fail silently on SDK version bumps.

**Fix:** Deleted the 5 self-verification tests (`verifySignatureAcceptsValidSignature`, `verifySignatureRejectsInvalidSignature`, `verifySignatureRejectsWrongSecret`, `verifySignatureRejectsExpiredTimestamp`, `generatedSignatureWorksWithStripeVerifier`). Retained 5 tests that lock the helper's public API contract (format, payload creators, parseSignature).

**Files changed:**
- `tests/Unit/Helper/StripeWebhookTestHelperTest.php`

**Evidence:** File now has 5 tests (was 10).

---

### T5 — No-value assertions (assertTrue(true), assertInstanceOf on setUp object)

**Finding:**
1. `StripeCaptureRequestHandlerTest::testHandleSkipsNonCaptureRequestEvents` — `assertTrue(true)` with no behavioral coverage
2. `AbstractStripeRequestHandlerTest::logEventIsNoOpWhenNoLoggerProvided` — `assertTrue(true)` as entire assertion body
3. `RequestLogServiceTest::testLogRequestDoesNotThrowOnSuccess` and `testLogExceptionDoesNotThrowOnSuccess` — `assertTrue(true)` alongside `expects(once)` (redundant)
4. `OxpaidReconciliationServiceTest::serviceCanBeInstantiated` — `assertInstanceOf` on `$this->service` (constructed in setUp, zero behavior tested)

**Fix:**
- `StripeCaptureRequestHandlerTest`: replaced with `captureService->expects($this->never())->method('processCapture')` + `processDirectCapture`
- `AbstractStripeRequestHandlerTest`: replaced with parallel handler-with-logger assertion using `expects(once)`
- `RequestLogServiceTest`: removed the two `assertTrue(true)` calls; `expects(once)` already asserts the behavior
- `OxpaidReconciliationServiceTest`: replaced with `findUnpaidOrdersReturnsEmptyArrayWhenNoneExist()` — real behavioral test

**Files changed:**
- `tests/Unit/Stripe/EventSystem/Handler/StripeCaptureRequestHandlerTest.php`
- `tests/Unit/Stripe/EventSystem/Handler/AbstractStripeRequestHandlerTest.php`
- `tests/Unit/Stripe/Service/RequestLogServiceTest.php`
- `tests/Unit/Stripe/Service/OxpaidReconciliationServiceTest.php`

**Evidence:** `grep -rn 'assertTrue(true)' tests/Unit/` — 0 results.

---

### T6 — Integration test suite gating

**Finding:** ~53 tests in the default `Integration` suite were silently skipping when Stripe credentials or the OXID container were unavailable. The suite appeared green while most of the live-Stripe and container-boot layer never executed.

**Fix:**
1. Added `@group requires-stripe-creds` to all 4 live-Stripe adapter test classes
2. Added `@group requires-oxid-container` to `PaymentPanelRegistryIntegrationTest` and `ModuleLifecycleTest`
3. Split `phpunit.xml` into 3 named suites:
   - `Integration` (~87 tests): default, always runnable, excludes live-Stripe and container-boot files
   - `Integration-live-stripe` (~47 tests): requires `STRIPE_TEST_SECRET_KEY` in `.env`
   - `Integration-with-container` (~6 tests): requires booted shop; container-boot failure = hard ERROR
4. Removed `markTestSkipped` from `PaymentPanelRegistryIntegrationTest` and `ModuleLifecycleTest`

**Files changed:**
- `tests/Integration/Stripe/Adapter/StripeAdapterIntegrationTest.php`
- `tests/Integration/Stripe/Adapter/Stripe3DSecureIntegrationTest.php`
- `tests/Integration/Stripe/Adapter/StripeAuthorizationFlowIntegrationTest.php`
- `tests/Integration/Stripe/Adapter/StripePaymentMethodIntegrationTest.php`
- `tests/Integration/Admin/PaymentPanelRegistryIntegrationTest.php`
- `tests/Integration/Module/ModuleLifecycleTest.php`
- `tests/phpunit.xml`
- `tests/SKIPPED_TESTS_REASON.md` (rewritten)

**Default suite counts:**

| | Before (Sprint 114.12) | After (Sprint 114.13) |
|---|---|---|
| Default `Integration` suite tests | ~157 | 87 |
| Silently skipped in default suite | ~53 | 0 (MetadataTest skip is expected/documented) |
| `Integration-live-stripe` suite | (included, skipped) | ~47 explicit |
| `Integration-with-container` suite | (included, skipped) | ~6 explicit, hard-fail |

---

### O10 — StripeEventTranslator instanceof ladder → mapping table

**Finding:** `StripeEventTranslator::translate()` used a chain of `instanceof` checks to map `AbstractProviderRequestEvent` subtypes to Stripe event classes. This violates OCP — adding a new event type requires modifying the method.

**Fix:** Replaced with a static `EVENT_MAP` constant (array keyed by source class string, valued by target class string). `translate()` now does a single array lookup: `self::EVENT_MAP[$event::class] ?? null`.

**Production file:** `src/Stripe/EventSystem/Translator/StripeEventTranslator.php`

**New test file:** `tests/Unit/Stripe/EventSystem/Translator/StripeEventTranslatorTest.php` (10 tests covering `supports()`, each mapped event, context forwarding, unmapped=null, non-concrete context=null)

---

### §8 — Coverage gaps (OxidContractLinkedOrderUpdater, OxidSessionWriter, StripeCheckoutFooter)

**OxidContractLinkedOrderUpdater:**
- Promoted `loadOrder()` private → protected for testability
- Testable subclass overrides `loadOrder()` to avoid `oxNew()` bootstrap
- 7 tests: interface implementation, `markCancelled`/`markFailed` happy paths, empty orderId no-op, order-not-found no-op

**OxidSessionWriter:**
- Uses `Registry::set(Session::class, $mock)` with tearDown cleanup
- 4 tests: interface implementation, `setVariable` key/value, empty orderId no-op

**StripeCheckoutFooter:**
- Inner `TestableStripeCheckoutFooter` overrides constructor (skips OXID parent), `render()`, `addTplParam()`, `getViewParameter()`, `getServiceFromContainer()`
- 8 tests: template name, both tplParams, checkoutData shape, currency default, price cast, publishableKey, exception fallback

---

## Quality Gate Results

### PHPCS (PSR-12)
```
All code style checks passed
```

### PHPStan (level max, with baseline)
```
[OK] No errors
```

### PHPMD (with baseline)
```
(0 new violations — 4 baselined entries unchanged)
```

### Unit Tests
```
Tests: 1123, Assertions: 2691
(baseline Sprint 114.12: 1098/2654 — net +25 tests, +37 assertions)
```

### Integration Tests (default suite)
```
Tests: 87, Assertions: 354
(baseline Sprint 114.12 effective default: ~87 runnable — silently-skipped tests now correctly gated)
```

---

## Commits

| SHA | Message |
|---|---|
| `ac015ef` | STRP-145 Sprint 114.13 (T3+T5): kill no-value/tautological tests; switch to interface mocks |
| `19f971b` | STRP-145 Sprint 114.13 (T4): drop test-of-test-helper self-verification |
| `2138402` | STRP-145 Sprint 114.13 (O10): StripeEventTranslator instanceof ladder → mapping table + tests |
| `8f8ea69` | STRP-145 Sprint 114.13 (§8 coverage): add tests for OxidContractLinkedOrderUpdater + OxidSessionWriter + StripeCheckoutFooter |
| `e1652da` | STRP-145 Sprint 114.13 (T6): gate creds-dependent integration tests; container-boot now hard-fails |

---

## Sprint 114 Code Review — Final Status

All 13 sub-sprints of code review 114 are now complete on branch `b-7.4.x-code-review-STRP-145`.
