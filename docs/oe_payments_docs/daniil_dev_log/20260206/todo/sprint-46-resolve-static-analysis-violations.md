# Sprint 46: Resolve Static Analysis Violations Through Code Refactoring

**Date:** 2026-02-06
**Status:** READY FOR IMPLEMENTATION
**Prerequisites:** Sprint 43 completed (interfaces created)
**Principle:** Fix code, don't suppress warnings. Only suppress if caused by OXID core or Stripe SDK.

---

## Executive Summary

The codebase has **20 PHPMD violations** and **58 PHPStan errors** at `--level=max`. Currently many are masked by inflated thresholds (PHPMD) or broad ignore patterns (PHPStan). This sprint resolves violations by refactoring code, then tightens analyser config to prevent regressions.

**Goal:** Zero PHPMD violations at standard thresholds. PHPStan suppressions only for OXID core and Stripe SDK.

---

## Current Violations Inventory

### PHPMD (20 violations)

| File | Rule | Value | Threshold |
|------|------|-------|-----------|
| **OrderRefund.php** | ExcessiveClassComplexity | 113 | 50 |
| **OrderRefund.php** | CyclomaticComplexity (getStripeApiOrderLastCharge) | 11 | 10 |
| **StripeAdapter.php** | ExcessiveClassComplexity | 89 | 50 |
| **StripeAdapter.php** | TooManyMethods | 29 | 25 |
| **StripeAdapter.php** | TooManyPublicMethods | 23 | 10 |
| **IdempotentStripeAdapter.php** | ExcessiveClassComplexity | 59 | 50 |
| **IdempotentStripeAdapter.php** | TooManyMethods | 30 | 25 |
| **IdempotentStripeAdapter.php** | TooManyPublicMethods | 23 | 10 |
| **StripeWebhookProcessor.php** | ExcessiveClassComplexity | 62 | 50 |
| **ModuleConfigurationService.php** | ExcessiveClassComplexity | 62 | 50 |
| **LazyStripeAdapter.php** | TooManyPublicMethods | 16 | 10 |
| **WebhookController.php** | CyclomaticComplexity (render) | 10 | 10 |
| **WebhookController.php** | NPathComplexity (render) | 288 | 200 |
| **StripeCheckoutReturnHandler.php** | CyclomaticComplexity (handle) | 10 | 10 |
| **StripeCheckoutReturnHandler.php** | NPathComplexity (handle) | 384 | 200 |
| **StripeCheckoutSessionHandler.php** | CyclomaticComplexity (handle) | 10 | 10 |
| **StripeCheckoutSessionHandler.php** | NPathComplexity (handle) | 256 | 200 |
| **OxidShopOrderService.php** | CyclomaticComplexity (createOrder) | 11 | 10 |
| **ReturnSessionSecurityService.php** | CyclomaticComplexity (parseUserAgent) | 12 | 10 |
| **WebhookContractFulfillmentHandler.php** | CyclomaticComplexity (handleChargeCaptured) | 10 | 10 |

### PHPStan (58 errors at --level=max)

**Legitimate suppressions (KEEP — 32 errors):**
- Stripe SDK parameter shapes (6 — StripeAdapter SDK call signatures)
- Stripe SDK property access on mixed (16 — `$paymentIntent->charges`, `->data`, `->created`, etc.)
- Stripe SDK response constructor types (10 — `array` vs `array<string, mixed>`)

**OXID core suppressions (KEEP — in phpstan.neon):**
- `oxNew` function, `VENDOR_PATH` constant
- `$model->tablename__fieldname->value` magic properties
- OXID basket iteration mixed types
- OXID Session::getBasket() null check

**Our code issues (FIX — 26 errors):**
- `Cannot cast mixed to int/string` from DB queries (17 — mostly IdempotentStripeAdapter, OxidShopOrderService)
- `Cannot call method load() on mixed` (2 — StaticContent, StripeRefundRequestHandler)
- `Cannot access property ->value on mixed` (3 — OxidStockRestorationService, Order)
- Various other mixed-type issues (4)

---

## Refactoring Plan

### Phase 1: Reduce Class Complexity (PHPMD — highest impact)

#### 1A. OrderRefund.php (complexity 113 → <50)

**Current problem:** Admin controller mixes UI logic, Stripe API calls, and validation.

**Refactoring:**
- Extract `StripeRefundApiService` — handles all Stripe API calls (charge retrieval, refund creation, balance checks)
- Extract `RefundValidationService` — validates amounts, checks permissions
- Keep controller thin: delegate to services, render templates

**Expected result:** OrderRefund complexity drops to ~30, two new focused services at ~25 each.

#### 1B. StripeAdapter.php (complexity 89 → <50)

**Current problem:** Single class implements all 23 PaymentAdapterInterface methods with full Stripe SDK translation.

**Refactoring:** Extract method groups into helper services:
- `StripePaymentIntentHelper` — create/retrieve/capture/cancel PaymentIntent methods
- `StripeRefundHelper` — refund creation, charge refund handling
- `StripeCheckoutSessionHelper` — checkout session create/retrieve
- `StripePaymentMethodHelper` — payment method operations

StripeAdapter delegates to helpers, keeping orchestration role.

**Expected result:** StripeAdapter complexity drops to ~30, four helpers at ~15 each.

#### 1C. StripeWebhookProcessor.php (complexity 62 → <50)

**Refactoring:** Use strategy pattern — extract handler methods into focused handler classes (already partially done with WebhookHandler/ directory). Move remaining inline logic to handler classes.

#### 1D. ModuleConfigurationService.php (complexity 62, 27 methods)

**Refactoring:** Split into sub-config services:
- `StripeKeyConfig` — key management (getToken, getPublishableKey, getSecretKey, isTestMode, validateKeyPair, getKeyValidationError)
- `StripeModeConfig` — capture mode, webhook config, second chance settings
- `StripeWalletConfig` — Apple Pay, Google Pay, Link settings

ModuleConfigurationService becomes a facade or is replaced entirely. Update `ModuleConfigurationServiceInterface` → split into sub-interfaces.

### Phase 2: Reduce Method Complexity (PHPMD — 8 methods)

#### 2A. WebhookController::render() (CC 10, NPath 288)
- Extract `validateWebhookSignature()` and `parseWebhookEvent()` methods
- Use early returns for validation failures

#### 2B. StripeCheckoutReturnHandler::handle() (CC 10, NPath 384)
- Extract `validateCheckoutSession()` and `resolveContract()` methods
- Use guard clauses

#### 2C. StripeCheckoutSessionHandler::handle() (CC 10, NPath 256)
- Extract payment method creation into helper
- Simplify checkout session parameters building

#### 2D. OxidShopOrderService::createOrder() (CC 11)
- Extract `buildOrderLineItems()` and `calculateOrderTotals()`
- Separate order creation from order population

#### 2E. ReturnSessionSecurityService::parseUserAgent() (CC 12)
- Extract browser detection map (array-driven instead of if-chains)
- Use match expression or lookup table

#### 2F. WebhookContractFulfillmentHandler::handleChargeCaptured() (CC 10)
- Extract validation and state transition logic into helper methods

### Phase 3: Fix PHPStan Type Safety (26 errors)

#### 3A. Database query results — add `@var` annotations or wrapper methods

**Pattern to apply:**
```php
// Before (PHPStan error):
$row = $this->connection->fetchAssociative($sql, $params);
$transactionId = (string) $row['transaction_id'];  // Cannot cast mixed

// After:
$row = $this->connection->fetchAssociative($sql, $params);
if (!is_array($row)) {
    return null;
}
$transactionId = is_string($row['transaction_id']) ? $row['transaction_id'] : '';
```

**Files:** IdempotentStripeAdapter (17 casts), OxidShopOrderService (9 casts), OxpaidReconciliationService (2), PaymentIntentSucceededHandler (1)

#### 3B. OXID model field access — add type guards after oxNew()

**Pattern:**
```php
// Before:
$content = oxNew(Content::class);
$content->load($id);  // Cannot call method load() on mixed

// After:
/** @var Content $content */
$content = oxNew(Content::class);
$content->load($id);
```

**Files:** StaticContent (1), StripeRefundRequestHandler (2), OxidStockRestorationService (4), Order (2)

### Phase 4: Tighten Analyser Config

#### 4A. Lower PHPMD thresholds (phpmd.baseline.xml)

After refactoring, change thresholds to standard values:

| Rule | Current | Target |
|------|---------|--------|
| TooManyPublicMethods | 50 | 10 |
| ExcessivePublicCount | 50 | 45 |
| ExcessiveClassComplexity | 120 | 50 |
| CyclomaticComplexity | 20 | 10 |
| NPathComplexity | 5000 | 200 |
| ExcessiveMethodLength | 150 | 100 |
| TooManyFields | 20 | 15 |

**Exception:** LazyStripeAdapter and IdempotentStripeAdapter — proxy/decorator pattern requires mirroring the interface (23 public methods). These need per-class `@SuppressWarnings` annotations with clear justification.

#### 4B. Narrow PHPStan ignore patterns (phpstan.neon)

Remove broad patterns, keep only specific OXID/Stripe SDK suppressions:
- Remove: `'Property .* does not accept mixed'` — fix actual code
- Remove: `'should return .* but returns mixed'` — fix return types
- Keep: All `oxNew`, `VENDOR_PATH`, OXID magic property patterns
- Keep: All phpstan-baseline.neon entries (Stripe SDK)

---

## Files to Create

| File | Purpose |
|------|---------|
| `src/Stripe/Service/StripeRefundApiService.php` | Extracted from OrderRefund |
| `src/Stripe/Service/RefundValidationService.php` | Extracted from OrderRefund |
| `src/Stripe/Adapter/Helper/PaymentIntentHelper.php` | Extracted from StripeAdapter |
| `src/Stripe/Adapter/Helper/RefundHelper.php` | Extracted from StripeAdapter |
| `src/Stripe/Adapter/Helper/CheckoutSessionHelper.php` | Extracted from StripeAdapter |
| `src/Stripe/Adapter/Helper/PaymentMethodHelper.php` | Extracted from StripeAdapter |
| Tests for each new class | |

## Files to Modify

| File | Changes |
|------|---------|
| `src/Stripe/Controller/Admin/OrderRefund.php` | Delegate to services |
| `src/Stripe/Adapter/StripeAdapter.php` | Delegate to helpers |
| `src/Stripe/Webhook/StripeWebhookProcessor.php` | Extract handlers |
| `src/Stripe/Service/ModuleConfigurationService.php` | Split into sub-services |
| `src/Stripe/Adapter/IdempotentStripeAdapter.php` | Fix DB casts |
| `src/Stripe/Adapter/OxidShopOrderService.php` | Fix casts, extract methods |
| 6 handler/controller files | Reduce method complexity |
| `tests/PhpMd/phpmd.baseline.xml` | Lower thresholds |
| `tests/PhpStan/phpstan.neon` | Narrow ignore patterns |

---

## Validation

After each phase:
```bash
docker compose exec -w /var/www/extensions/stripe -T php php vendor/bin/phpunit -c tests/phpunit.xml --testsuite Unit
docker compose exec -w /var/www/extensions/stripe -T php php vendor/bin/phpstan analyse --configuration=tests/PhpStan/phpstan.neon --level=max --no-progress
docker compose exec -w /var/www/extensions/stripe -T php php vendor/bin/phpmd src/ text tests/PhpMd/phpmd.baseline.xml
```

Final: all tests pass, zero PHPMD violations, PHPStan errors only from Stripe SDK / OXID core.

---

## Risks

| Risk | Likelihood | Impact | Mitigation |
|------|------------|--------|------------|
| Regression in refactored code | Medium | High | Full test suite after each extraction |
| Too many files | Low | Low | Only extract when complexity is clearly too high |
| Breaking DI wiring | Low | Medium | Run integration tests after each change |
| Scope creep | Medium | Medium | Phase by phase, validate between phases |

---

## Estimated Effort

| Phase | Estimate | Priority |
|-------|----------|----------|
| Phase 1: Class complexity | 8-12 hours | HIGH |
| Phase 2: Method complexity | 4-6 hours | MEDIUM |
| Phase 3: PHPStan type safety | 2-3 hours | MEDIUM |
| Phase 4: Tighten config | 1-2 hours | LOW (after phases 1-3) |
| **Total** | **15-23 hours** | |

**Recommendation:** Execute Phase 1 first (highest impact), then assess remaining phases.
