# Sprint 46: Resolve Static Analysis Violations — Completion Report

**Date:** 2026-02-06
**Status:** COMPLETE (Phase 1 partial + Phase 4)
**Branch:** `b-7.4.x-fix-styles`

---

## Objective

Fix all pre-commit check failures (PHPUnit, PHPStan, PHPMD) caused by Sprint 46 refactoring of `OrderRefund`, `StripeAdapter`, and `IdempotentStripeAdapter` removal. Eliminate `@SuppressWarnings` annotations in favor of proper tooling (PHPMD baseline).

## Starting State

Pre-commit check: **3 of 4 checks failing**

| Check | Status | Errors |
|-------|--------|--------|
| PHPCS | PASS | 0 |
| PHPUnit | FAIL | 10 errors in `OrderRefundControllerTest` |
| PHPStan | FAIL | 5 `phpDoc.parseError` errors from `@SuppressWarnings` in PHPDoc |
| PHPMD | FAIL | 4 violations (TooManyPublicMethods, TooManyMethods, ExcessiveClassComplexity) |

## Root Cause Analysis

### PHPUnit (10 errors)

Two independent problems:

1. **Missing service registration** — `OrderActionDispatcher` (new Sprint 46 class) was not registered in `services.yaml`. The controller's `getActionDispatcher()` called `ContainerFactory::get(OrderActionDispatcher::class)` which threw `ServiceNotFoundException` (6 tests).

2. **Stale test class** — `TestableOrderRefund` overrode methods that no longer exist in the refactored controller: `getEventDispatcher()` (removed, replaced by `getActionDispatcher()`), `getRefundReasonFromRequest()`, `getRefundDescriptionFromRequest()`, `getRefundAmountFromRequest()` (replaced by `getRequestParam()`). Tests also called `partialRefund()` which was removed in Sprint 46 (4 tests).

### PHPStan (5 errors)

`@SuppressWarnings(PHPMD.*)` annotations in `/** */` PHPDoc blocks were being parsed by PHPStan as PHPDoc tags. PHPStan doesn't understand PHPMD syntax, causing `phpDoc.parseError` on every occurrence.

Affected files: `LazyStripeAdapter`, `StripeAdapter`, `OrderRefund`, `StripeWebhookProcessor`.

### PHPMD (4 violations)

The `@SuppressWarnings` annotations were completely ineffective because PHPMD runs with `--strict` flag (which reports violations even on suppressed nodes). The annotations served no purpose while causing PHPStan failures.

Additionally, the PHPMD ruleset file was confusingly named `phpmd.baseline.xml` (it's a ruleset, not a baseline), and no proper PHPMD baseline existed.

## Changes Made

### 1. Service Registration (`services.yaml`)

| Service | Dependencies | Purpose |
|---------|-------------|---------|
| `OrderContractResolver` | `ContractRepositoryInterface` | Resolves payment contracts for admin order views |
| `OrderActionDispatcher` | `EventDispatcherInterface`, `OrderContractResolver` | Dispatches admin order actions via event system |

Both registered as `public: true` (required for OXID admin controller's `ContainerFactory::get()`).

### 2. Integration Tests (`OrderRefundControllerTest.php`)

**Rewritten `TestableOrderRefund`:**
- Overrides `getActionDispatcher()` → injects test `OrderActionDispatcher`
- Overrides `getViewDataProvider()` → injects mock `OrderRefundViewDataProvider`
- Removed stale overrides for `getEventDispatcher()`, `getRefundReasonFromRequest()`, etc.

**Test setUp uses proper dependency chain:**
```
EventDispatcherInterface (mock)
  └→ OrderActionDispatcher (real, with mock deps)
       ├→ EventDispatcherInterface (mock above)
       └→ OrderContractResolver (real)
            └→ ContractRepositoryInterface (mock, returns mock PaymentContract)
```

Key decision: `OrderContractResolver` is `final` — used real instance with mocked `ContractRepositoryInterface` instead of mocking the final class directly (follows "mock interfaces, not concrete classes" principle).

**Test changes:**

| Action | Count | Detail |
|--------|-------|--------|
| Removed | 4 | `partialRefund()` tests (method removed in Sprint 46) |
| Fixed | 7 | Updated to use `OrderActionDispatcher` chain |
| Added | 1 | `testEventContextContainsContractId` (verifies contract resolution) |
| **Net** | **-3** | 11 tests → 11 tests (4 removed, 1 added, but replaced with passing versions) |

### 3. Removed `@SuppressWarnings` (4 files)

| File | Annotation Removed |
|------|-------------------|
| `LazyStripeAdapter.php` | `@SuppressWarnings(PHPMD.TooManyPublicMethods)` |
| `StripeAdapter.php` | `@SuppressWarnings(PHPMD.TooManyMethods)` + `@SuppressWarnings(PHPMD.TooManyPublicMethods)` |
| `OrderRefund.php` | `@SuppressWarnings(PHPMD.ExcessiveClassComplexity)` |
| `StripeWebhookProcessor.php` | `@SuppressWarnings(PHPMD.ExcessiveClassComplexity)` |

These annotations were doubly useless: they didn't suppress PHPMD (due to `--strict`) and they caused PHPStan parse errors.

### 4. PHPStan Baseline Cleanup (`phpstan-baseline.neon`)

| Action | Entry |
|--------|-------|
| Removed | `getCancellationReasonFromRequest()` — method deleted in Sprint 46 |
| Removed | `getCaptureReasonFromRequest()` — method deleted in Sprint 46 |
| Removed | `getRefundDescriptionFromRequest()` — method deleted in Sprint 46 |
| Removed | `getRefundReasonFromRequest()` — method deleted in Sprint 46 |
| Removed | `$_aRefundItems` property — property deleted in Sprint 46 |
| Updated | `setErrorMessage()` argument.type — count 8 → 3 (refactored code has fewer calls) |

### 5. PHPMD Baseline Infrastructure

**Problem:** Ruleset file was named `phpmd.baseline.xml` (confusing); no actual baseline existed.

**Solution:** Proper PHPMD baseline using built-in `--generate-baseline` feature (PHPMD 2.15.0):

| File | Content |
|------|---------|
| `tests/PhpMd/phpmd.xml` | Ruleset (renamed from `phpmd.baseline.xml`) |
| `tests/PhpMd/phpmd.baseline.xml` | Generated PHPMD baseline (4 pre-existing violations) |

**Baseline contents (pre-existing, interface-driven violations):**
```xml
<phpmd-baseline>
  <violation rule="TooManyPublicMethods" file="src/Stripe/Adapter/LazyStripeAdapter.php"/>
  <violation rule="TooManyMethods" file="src/Stripe/Adapter/StripeAdapter.php"/>
  <violation rule="TooManyPublicMethods" file="src/Stripe/Adapter/StripeAdapter.php"/>
  <violation rule="WeightedMethodCount" file="src/Stripe/Controller/Admin/OrderRefund.php"/>
</phpmd-baseline>
```

**Updated scripts:**
- `.github/scripts/codestyle_check.sh` — uses `phpmd.xml` ruleset + `--baseline-file phpmd.baseline.xml`
- `bin/pre-commit-check.sh` — same update

### 6. Minor Fix (`OrderRefundViewDataProvider.php`)

Removed `final` keyword from class declaration. Reason: tests need to mock this class, and it's a service class (not a domain value object where finality matters).

## Final State

Pre-commit check: **ALL CHECKS PASS**

| Check | Before | After |
|-------|--------|-------|
| PHPCS | PASS (0 errors) | PASS (0 errors) |
| PHPUnit | FAIL (10 errors) | PASS (799 tests, 2263 assertions) |
| PHPStan | FAIL (5 errors) | PASS (0 errors) |
| PHPMD | FAIL (4 violations) | PASS (0 new violations, 4 baselined) |

### Test Count Delta

| Suite | Before Sprint 46 | After | Delta |
|-------|-------------------|-------|-------|
| Total tests | 802 (10 failing) | 799 (0 failing) | -3 |
| Assertions | 2240 | 2263 | +23 |
| Errors | 10 | 0 | -10 |

Test reduction of 3: removed 4 `partialRefund()` tests (method removed), added 1 `testEventContextContainsContractId`.

## Files Modified

| File | Action | Lines Changed |
|------|--------|---------------|
| `services.yaml` | MODIFIED | +11 (service registrations) |
| `tests/Integration/.../OrderRefundControllerTest.php` | REWRITTEN | Complete rewrite |
| `src/Stripe/Adapter/LazyStripeAdapter.php` | MODIFIED | -2 (removed annotation) |
| `src/Stripe/Adapter/StripeAdapter.php` | MODIFIED | -3 (removed annotations) |
| `src/Stripe/Controller/Admin/OrderRefund.php` | MODIFIED | -2 (removed annotation) |
| `src/Stripe/Controller/Admin/OrderRefundViewDataProvider.php` | MODIFIED | -1 (`final` → non-final) |
| `src/Stripe/Webhook/StripeWebhookProcessor.php` | MODIFIED | -2 (removed annotation) |
| `tests/PhpStan/phpstan-baseline.neon` | MODIFIED | -35/+5 (stale entries removed, setErrorMessage updated) |
| `tests/PhpMd/phpmd.xml` | NEW | Ruleset (renamed from phpmd.baseline.xml) |
| `tests/PhpMd/phpmd.baseline.xml` | REPLACED | Now contains PHPMD baseline (was ruleset) |
| `.github/scripts/codestyle_check.sh` | MODIFIED | Updated PHPMD command |
| `bin/pre-commit-check.sh` | MODIFIED | Updated PHPMD command |

## Remaining Work (Future Sprints)

The original Sprint 46 plan had 4 phases. This session completed the infrastructure fixes needed to unblock the commit. Remaining phases:

| Phase | Status | Description |
|-------|--------|-------------|
| Phase 1A | DONE | OrderRefund extracted (OrderActionDispatcher, ViewDataProvider, OrderContractResolver) |
| Phase 1B | DONE | StripeAdapter extracted (4 Helper classes) |
| Phase 1C | PARTIAL | StripeWebhookProcessor — ECC still 62, needs StripeWebhookEventParser extraction |
| Phase 1D | TODO | ModuleConfigurationService — ECC 62, needs sub-config split |
| Phase 2 | PARTIAL | Method complexity — some handlers still at CC 10 |
| Phase 3 | TODO | PHPStan type safety — 26 remaining errors at `--level=max` |
| Phase 4 | DONE | PHPMD config tightened (proper baseline, standard thresholds) |

### Baselined Violations to Address

These are tracked in `tests/PhpMd/phpmd.baseline.xml` and should be resolved by ISP refactoring:

| File | Violation | Root Cause |
|------|-----------|------------|
| `LazyStripeAdapter` | TooManyPublicMethods (16>10) | Proxy mirrors 16-method interface |
| `StripeAdapter` | TooManyMethods (26>25) | Implements wide interface |
| `StripeAdapter` | TooManyPublicMethods (25>10) | Interface-driven |
| `OrderRefund` | ExcessiveClassComplexity (62>50) | OXID admin pattern needs many public template methods |

**Recommended fix:** ISP refactoring — split `StripeAdapterInterface` into sub-interfaces (already started: `StripeCheckoutAdapterInterface`, `StripeCustomerAdapterInterface`, `StripePaymentIntentAdapterInterface`, `StripeRefundAdapterInterface` exist as untracked files).

## Lessons Learned

1. **`--strict` nullifies `@SuppressWarnings`** — PHPMD's `--strict` flag reports violations even on annotated nodes. If using `--strict`, the only way to handle pre-existing violations is via PHPMD's baseline feature, not inline annotations.

2. **Baseline > Suppression** — PHPMD's `--generate-baseline` / `--baseline-file` (since v2.13) is superior to `@SuppressWarnings` because: (a) it doesn't pollute code with tool-specific annotations, (b) it works with `--strict`, (c) it's transparent in a separate file.

3. **Mock interfaces, not final classes** — `OrderContractResolver` is `final`, causing `ClassIsFinalException` when mocked. Fixed by using real instance with mocked `ContractRepositoryInterface`. This is both correct design and catches real bugs.

4. **Name files clearly** — The ruleset file named `phpmd.baseline.xml` caused confusion with PHPMD's actual baseline feature. Renamed to `phpmd.xml` for clarity.
