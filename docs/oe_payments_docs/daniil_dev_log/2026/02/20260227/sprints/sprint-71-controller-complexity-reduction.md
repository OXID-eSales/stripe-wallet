# Sprint 71 — StripeOrderController Complexity Reduction (STRP-103)

**Date:** 2026-02-27
**Branch:** `b-7.4.x`
**Type:** Refactoring (PHPMD compliance)
**Approach:** Extract helper class, keep single controller, no URL changes

---

## Problem

PHPMD reports `ExcessiveClassComplexity` on `StripeOrderController` (WMC 60, threshold 50).

```
src/Stripe/Controller/StripeOrderController.php:36  ExcessiveClassComplexity
The class StripeOrderController has an overall complexity of 60 which is very high.
The configured complexity threshold is 50.
```

### Root Cause Analysis

The controller has **25 methods** total. The 4 action methods are lean (delegate to handlers). The bulk of complexity comes from **15 data accessor methods** wrapping `Registry::` static calls for testability, plus **6 helper/framework wrapper methods**.

| Category | Count | Est. WMC |
|----------|-------|----------|
| Action methods | 4 | ~23 |
| Data accessors (`get*FromRequest`, `get*FromSession`, etc.) | 15 | ~16 |
| Helpers + framework wrappers | 6 | ~9 |
| **Total** | **25** | **~60** |

The accessors are not controller logic — they are infrastructure concerns (reading request params, session vars, config). Extracting them into a helper class reduces the controller to its actual responsibility: dispatch events.

### Constraint: No URL Changes

OXID controller routing is tied to `cl=order`. All JS, templates, Stripe return URLs, and in-flight checkout sessions depend on this. Changing URLs is high-risk with no functional benefit. **All methods stay on the same `cl=order` route.**

---

## Solution: Extract `ControllerRequestHelper`

Move data accessor and infrastructure methods into a helper class. The controller delegates to the helper for all `Registry::` interactions.

### Architecture After Refactoring

```
StripeOrderController (extends OrderController)
├── executeStripePayment()        — Payment Element submit
├── createCheckoutSession()       — AJAX: Stripe Checkout Session
├── checkoutSuccess()             — Return from Stripe hosted page
├── stripeReturn()                — Payment Element 3DS return
├── processContextResults()       — Shared result handling
└── $requestHelper                — injected ControllerRequestHelper

ControllerRequestHelper
├── getPaymentIntentIdFromRequest()
├── getSessionPaymentIntentId()
├── getCheckoutSessionIdFromRequest()
├── getContractIdFromRequest()
├── getContractTokenFromRequest()
├── getRedirectStatusFromRequest()
├── getContractIdFromSession()
├── getBasketFromSession()
├── getSessionId()
├── getShopId()
├── getShopUrl()
├── getCaptureMode()
├── getUser()
├── setSessionVariable()
├── deleteSessionVariable()
├── validateSessionChallenge()
├── validateContractToken()
├── addErrorToDisplay()
├── logError()
└── clearStripeSessionVariables()
```

---

## Step 1 — Create `ControllerRequestHelper`

**New file:** `src/Stripe/Controller/ControllerRequestHelper.php`

Extract these methods from `StripeOrderController`:

**Request accessors (6):**
- `getPaymentIntentIdFromRequest(): ?string`
- `getCheckoutSessionIdFromRequest(): ?string`
- `getContractIdFromRequest(): ?string`
- `getContractTokenFromRequest(): ?string`
- `getRedirectStatusFromRequest(): ?string`
- `getSessionPaymentIntentId(): ?string`

**Session accessors (4):**
- `getContractIdFromSession(): ?string`
- `getBasketFromSession(): Basket`
- `getSessionId(): string`
- `setSessionVariable(string $key, mixed $value): void`
- `deleteSessionVariable(string $key): void`

**Config accessors (3):**
- `getShopId(): int`
- `getShopUrl(): string`
- `getCaptureMode(): string`

**Validation (2):**
- `validateSessionChallenge(): bool`
- `validateContractToken(?string $contractId, ?string $contractToken): bool`

**Utilities (3):**
- `addErrorToDisplay(string $message): void`
- `logError(string $message, \Throwable $e): void`
- `clearStripeSessionVariables(): void`

**Total: 20 methods** extracted from controller.

The helper wraps `Registry::getRequest()`, `Registry::getSession()`, `Registry::getConfig()`. All methods are `public` (called by controller). The helper is injected via constructor or lazy-created in the controller.

Since OXID controllers are instantiated via `oxNew()`, the helper will be created internally:

```php
private function getRequestHelper(): ControllerRequestHelper
{
    if ($this->requestHelper === null) {
        $this->requestHelper = new ControllerRequestHelper(
            $this->getServiceFromContainer(ContractTokenService::class),
            $this->getServiceFromContainer(ModuleConfigurationServiceInterface::class)
        );
    }
    return $this->requestHelper;
}
```

### Acceptance Criteria
- [ ] `ControllerRequestHelper` contains all extracted methods
- [ ] No dependency on `OrderController` — plain service class
- [ ] All methods are `public`
- [ ] Constructor receives `ContractTokenService` and `ModuleConfigurationServiceInterface`

---

## Step 2 — Refactor `StripeOrderController`

**File:** `src/Stripe/Controller/StripeOrderController.php`

Replace direct `Registry::` calls with helper delegation:

```php
// Before
$sessionId = $this->getCheckoutSessionIdFromRequest();

// After
$sessionId = $this->getRequestHelper()->getCheckoutSessionIdFromRequest();
```

**Remove from controller:** all 20 extracted methods.

**Keep in controller:**
- 4 action methods: `executeStripePayment()`, `createCheckoutSession()`, `checkoutSuccess()`, `stripeReturn()`
- `processContextResults()` — uses template params via `addTplParam()` (OXID parent method)
- `getEventDispatcher()` — uses `ServiceContainer` trait
- `getRequestHelper()` — lazy factory
- `addTplParam()` — delegates to OXID parent
- `exitWithJson()` — controller-specific (calls `exit`)
- `getUser()` — overrides OXID parent, keep in controller

**Expected methods remaining:** ~10
**Expected WMC:** ~35 (well under 50)

### Acceptance Criteria
- [ ] Controller has ≤12 methods
- [ ] WMC under 50
- [ ] No URL changes — `cl=order` preserved everywhere
- [ ] All 4 action methods work identically

---

## Step 3 — Tests

### New: `ControllerRequestHelperTest.php`

**File:** `tests/Unit/Stripe/Controller/ControllerRequestHelperTest.php`

Test the extracted helper in isolation (no OXID framework needed for most methods — mock `Registry` via testable subclass or dependency injection).

- `testGetPaymentIntentIdFromRequestReturnsStringValue`
- `testGetPaymentIntentIdFromRequestReturnsNullForNonString`
- `testGetCheckoutSessionIdFromRequestReturnsStringValue`
- `testGetContractIdFromSessionReturnsNullWhenEmpty`
- `testValidateContractTokenReturnsFalseForNullInputs`
- `testValidateContractTokenDelegatesToService`
- `testValidateSessionChallengeDelegatesToSession`
- `testClearStripeSessionVariablesDeletesAllKeys`
- `testGetCaptureModeReturnsConfigValue`

### Existing Tests
- No `StripeOrderControllerTest` exists — controller is thin, tested via handler tests
- All 723+ existing unit tests must continue to pass

### Acceptance Criteria
- [ ] ControllerRequestHelper tests pass
- [ ] All existing tests pass (no regressions)

---

## Step 4 — Pre-Commit Validation

```bash
./bin/pre-commit-check.sh
```

### Acceptance Criteria
- [ ] PHPCS: 0 errors (PSR-12)
- [ ] PHPStan: 0 errors (level max)
- [ ] PHPMD: 0 new violations — `StripeOrderController` under WMC 50
- [ ] PHPUnit: all tests pass

---

## Fallback: Baseline Suppression

If during implementation the refactoring proves too invasive (e.g., `Registry::` mocking issues in helper class, OXID parent method dependencies that can't be cleanly extracted), the fallback is to add `StripeOrderController` to the PHPMD baseline:

**File:** `tests/PhpMd/phpmd.baseline.xml`
```xml
<violation rule="PHPMD\Rule\Design\WeightedMethodCount" file="src/Stripe/Controller/StripeOrderController.php"/>
```

This follows the existing pattern (4 entries already baselined for similar interface-driven or OXID-pattern classes). Use this only if Step 2 cannot bring WMC under 50.

---

## Expected WMC After Refactoring

| Class | Methods | Est. WMC | Threshold |
|-------|---------|----------|-----------|
| `ControllerRequestHelper` | 20 | ~22 | 50 |
| `StripeOrderController` | ~10 | ~35 | 50 |

Both under 50. No baseline entry needed.

---

## Files Changed Summary

| Action | File |
|--------|------|
| **NEW** | `src/Stripe/Controller/ControllerRequestHelper.php` |
| **MODIFY** | `src/Stripe/Controller/StripeOrderController.php` |
| **NEW** | `tests/Unit/Stripe/Controller/ControllerRequestHelperTest.php` |

**No changes to:** `metadata.php`, JS, templates, URLs, `buildSuccessUrl()`.
