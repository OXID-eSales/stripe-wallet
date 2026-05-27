# Sprint 114.5 — Dead-code sweep: Completion Report

**Date:** 2026-05-27
**Branch:** `b-7.4.x-code-review-STRP-145`
**Sprint spec:** `sprints/sprint-114.5-dead-code-sweep.md`

---

## Summary

8 items executed (O2, O4, O5, O6, O7, O8, O9), 1 deferred (O10).
One commit per item, all gates green.

---

## Per-item Results

### O2 — Delete StripeCaptureService + StripeRefundService (commit `74b1a5a`)

**Grep proof (src/ and sibling modules):**
```
grep -rn "StripeCaptureService|StripeRefundService" src/ metadata.php
→ Only definitions found (no callers)
grep -rn "..." paypal/ one-page-checkout/
→ Only a doc mention in paypal/docs (not code)
```

**Deleted:**
- `src/Stripe/Service/StripeCaptureService.php`
- `src/Stripe/Service/StripeRefundService.php`
- `services.yaml` lines 772–801 (both registrations + comment block)
- `tests/Unit/Stripe/Service/StripeCaptureServiceTest.php`
- `tests/Unit/Stripe/Service/StripeRefundServiceTest.php`
- `tests/Unit/Stripe/Service/StripeRefundServiceValidationTest.php`

**LOC removed:** ~968 lines

---

### O4 — Remove dead ConfigurationValidator methods (commit `0a716d4`)

**Grep proof (src/):**
```
grep -rn "validateConfiguration|validateKeyPair|testConnection|validateApiKeyFormat" src/
→ Only definitions in ConfigurationValidator.php and ConfigurationValidatorInterface.php
  (plus StripeAdapter.php's own testConnection() — different class)
```

**Deleted from `ConfigurationValidator`:** `validateConfiguration()`, `validateApiKeyFormat()`, `testConnection()`, `validateKeyPair()`
**Deleted from `ConfigurationValidatorInterface`:** same 4 method declarations
**Removed from `ConfigurationValidator`:** `$adapterFactory` ctor arg, 3 constants (TEST_KEY_PREFIX, LIVE_KEY_PREFIX, WEBHOOK_SECRET_PREFIX)
**Updated tests:** `StripeOrderControllerSecurityTest` anonymous class stub simplified; `StripeOrderControllerTest` anonymous class stub simplified.

**Kept:** `getKeyValidationError()` (used at `ModuleConfiguration.php` + `StripeOrderController.php`)

---

### O5 — Remove Stripe3DSRequiredEvent + dispatch (commit `1305600`)

**3DS data trace:** `handlePending()` sets `requires3DS`, `clientSecret`, `redirectTarget` on the `EventContext` **before** dispatching `Stripe3DSRequiredEvent`. The event carried no extra data; no handler existed for it. Removing the dispatch is safe — the context values are what the frontend reads.

**Grep proof:**
```
grep -rn "Stripe3DSRequiredEvent" src/
→ Only: Event class definition, and its import+dispatch in StripePaymentStatusHandler.php
```

**Deleted:**
- `src/Stripe/EventSystem/Event/Stripe3DSRequiredEvent.php`
- `tests/Unit/Stripe/EventSystem/Event/Stripe3DSRequiredEventTest.php`
- Dispatch call removed from `StripePaymentStatusHandler::handlePending()`

**Test update:** `testDispatches3DSRequiredOnRequiresAction` renamed `testSets3DSContextOnRequiresAction` — now asserts `requires3DS=true`, `clientSecret`, `redirectTarget='order'` on context AND asserts `eventDispatcher::dispatch` is **never** called (proving R-7.3 compliance).

---

### O6 — Remove dead StripeStatusMapper methods (commit `adcf495`)

**Grep proof:**
```
grep -rn "StripeStatusMapper::fromPaymentIntent|StripeStatusMapper::isProcessing|StripeStatusMapper::isAuthorized" src/ paypal/ one-page-checkout/
→ Zero matches
```
Note: `->getState()->isAuthorized()` (contract state method) is unrelated — confirmed not a false positive.

**Deleted from `StripeStatusMapper`:** `fromPaymentIntent()`, `isProcessing()`, `isAuthorized()`
**Deleted from `StripeStatusMapperTest`:** 8 test methods covering these three methods.

**Kept:** `toNormalized()`, `requiresAction()`, `isCaptured()`, `isCancelled()` (all have live callers).

---

### O7 — Remove speculative Payment model methods (commit `e6c8462`)

**Grep proof:**
```
grep -rn "isOtherSourced|getPaymentProvider|requiresStripeConfiguration|getStripePaymentMethodType|supportsStripeFeature" src/ views/ paypal/ one-page-checkout/
→ Zero matches (only tests + definitions)
```

**Which kept, which removed:**
- **KEPT:** `isStripePaymentMethod()` — called from `StripeOrderController` and `PaymentController`
- **REMOVED:** `isOtherSourced()`, `getPaymentProvider()`, `requiresStripeConfiguration()`, `getStripePaymentMethodType()`, `supportsStripeFeature()`

**Deleted tests:** 20 test methods across 5 method groups in `PaymentTest.php`.

---

### O8 — Remove getSessionDetails() + getShopBaseUrl() (commit `92ddd9d`)

**Grep proof:**
```
grep -rn "getSessionDetails|getShopBaseUrl" src/
→ Only definitions — CheckoutReturnService.php, CheckoutReturnServiceInterface.php,
   ModuleConfigurationService.php
```

**CheckoutReturnService:**
- Removed `getSessionDetails()` from class and interface
- Real paths use `validateReturn()` exclusively

**ModuleConfigurationService:**
- Removed `getShopBaseUrl()` (only caller of `$shopAdapter`)
- Dropped optional `?ShopAdapterInterface $shopAdapter` ctor arg
- Removed `use OxidEsales\PaymentBase\Adapter\ShopAdapterInterface` import
- `getWebhookUrl()` continues to use `getSslShopBaseUrl()` unchanged

**Test updates:**
- `CheckoutReturnServiceTest`: removed 2 `getSessionDetails` test methods
- `ModuleConfigurationServiceWebhookUrlTest`: updated `testWebhookUrlIgnoresHttpBaseUrlWhenSslBaseAvailable` — removed the `getShopBaseUrl()` override (method no longer exists); intent of the test is preserved through the SSL assertion

---

### O9 — Delete QUICK_RETURN_MAX constant (commit `65e5c80`)

**Grep proof:**
```
grep -rn "QUICK_RETURN_MAX" src/ tests/
→ Only: definition in ReturnSessionSecurityService.php (with @phpstan-ignore)
```

**Deleted:** `QUICK_RETURN_MAX = 900` constant + its `@phpstan-ignore classConstant.unused` line.

No test changes needed — no test referenced this constant.

---

### O10 — StripeEventTranslator instanceof ladder → map (DEFERRED)

The `StripeEventTranslator::translate()` method has a 3-item `instanceof` ladder. Converting to a map (e.g., `[abstract::class => fn(...) => new Concrete(...)]`) would add factory closures with no readability gain at 3 items. Sprint 114.13 is the planned home for this refactor. No functional change to defer.

---

## Grep Proof Summary

| Symbol | src/ callers | views/ callers | Sibling modules |
|--------|-------------|----------------|-----------------|
| StripeCaptureService | 0 (definition only) | — | 0 (doc only) |
| StripeRefundService | 0 (definition only) | — | 0 |
| validateConfiguration() | 0 | — | 0 |
| validateKeyPair() | 0 | — | 0 |
| testConnection() (ConfigValidator) | 0 | — | 0 |
| validateApiKeyFormat() | 0 | — | 0 |
| Stripe3DSRequiredEvent | 1 dispatch (removed) | — | 0 |
| fromPaymentIntent() | 0 | — | 0 |
| isProcessing() | 0 | — | 0 |
| isAuthorized() (mapper) | 0 | — | 0 |
| isOtherSourced() | 0 | 0 | 0 |
| getPaymentProvider() | 0 | 0 | 0 |
| requiresStripeConfiguration() | 0 | 0 | 0 |
| getStripePaymentMethodType() | 0 | 0 | 0 |
| supportsStripeFeature() | 0 | 0 | 0 |
| getSessionDetails() | 0 | — | 0 |
| getShopBaseUrl() | 0 | — | 0 |
| QUICK_RETURN_MAX | 0 | — | 0 |

---

## LOC Delta (approximate)

| Item | LOC removed |
|------|-------------|
| O2 (StripeCaptureService + StripeRefundService + 3 tests) | ~968 |
| O4 (ConfigurationValidator methods + interface + test stubs) | ~177 |
| O5 (Stripe3DSRequiredEvent + test + dispatch) | ~152 |
| O6 (StripeStatusMapper methods + 8 tests) | ~138 |
| O7 (Payment model methods + 20 tests) | ~471 |
| O8 (getSessionDetails + getShopBaseUrl + 3 tests) | ~112 |
| O9 (QUICK_RETURN_MAX constant + suppress) | ~2 |
| **Total** | **~2020** |

---

## PHPMD Baseline Delta

Baseline unchanged. No items we deleted were tracked in the baseline. The 4 baselined entries (LazyStripeAdapter, StripeAdapter ×2, StripeOrderController) remain valid — none were affected by this sprint.

---

## Test Counts

| Stage | Tests | Assertions |
|-------|-------|------------|
| Before (after 114.4, Unit) | 954 | 2344 |
| After (Unit) | 859 | 2188 |
| After (Integration) | 141 | 356 (53 skipped — live API) |

Test count reduction (95 unit tests) is justified: all removed tests covered deleted code.

---

## Pre-commit Gate Results

| Check | Result |
|-------|--------|
| PHPCS (PSR-12) | PASS — 0 errors |
| PHPStan (level max) | PASS — 0 errors |
| PHPMD | PASS — 0 new violations (4 baselined unchanged) |
| PHPUnit Unit | PASS — 859 tests, 2188 assertions |
| PHPUnit Integration | PASS — 141 tests, 356 assertions (53 skipped) |

**Module activation note:** `oe:module:activate` fails with "Controller namespace duplication: WebhookController" — this is a **pre-existing issue** confirmed by testing before any changes were applied (same error on clean HEAD). Not caused by this sprint.

---

## Commit Hashes

| Item | Hash |
|------|------|
| O2 | `74b1a5a` |
| O4 | `0a716d4` |
| O5 | `1305600` |
| O6 | `adcf495` |
| O7 | `e6c8462` |
| O8 | `92ddd9d` |
| O9 | `65e5c80` |

---

## R-1…R-10 Checklist

- [x] **R-1 TDD:** Deletion sprints invert TDD: characterized behavior before deletion; updated tests removed only for deleted code; O5 updated test asserts surviving path (3DS context) works correctly after event removed.
- [x] **R-2 SOLID:** No god-objects introduced; PHPMD baseline not grown; ISP improved (interfaces shrunk).
- [x] **R-3 LI:** No security-weakening overrides; no instanceof downcasts.
- [x] **R-4 DI:** No new `ContainerFactory::getInstance`; removed `$shopAdapter` dependency was dead weight.
- [x] **R-5 Clean Code:** No else expressions; explicit imports; no magic literals; `@phpstan-ignore` removed with underlying dead code (O9).
- [x] **R-6 DevOps-first:** All checks pass; cache cleared + PHP restarted after services.yaml changes; no new suppressions.
- [x] **R-7 Event-driven:** O5 removed orphan event (R-7.3); no orphan handlers introduced.
- [x] **R-8 Contract-aware:** No contract lifecycle changes.
- [x] **R-9 No overengineering:** All deleted symbols had grep-proven zero callers; LOC trended down (~2020 LOC removed).
- [x] **R-10 Persistence:** No writes touched; no repository methods moved.
