# Sprint 114.11a — Completion Report

**Sprint:** 114.11a (S5, S6, S7 from code-review 114 DIP/SRP cleanups)
**Branch:** `b-7.4.x-code-review-STRP-145`
**Date:** 2026-05-28
**Mode:** One commit per finding

---

## Commits

| # | Hash | Finding | Description |
|---|------|---------|-------------|
| 1 | `7b65e61` | S7 | Drop redundant in-code `getPriority()` from `StripeCheckoutSessionHandler` and `StripeCaptureRequestHandler` |
| 2 | `b0e79bd` | S6 | Inject `StripeClientFactory` into `StripeWebhookEndpointApi`; add CRUD tests |
| 3 | `7ae17bb` | S5 | Eliminate ad-hoc `ContainerFactory::getInstance()` calls; use `init()` + getter seams |

---

## S7 — Handler priority consistency

**Services.yaml tag values vs in-code overrides (parity check):**

| Handler | services.yaml tag priority | in-code `getPriority()` | Disposition |
|---------|---------------------------|------------------------|-------------|
| `StripeContractCreationHandler` | 100 | 100 | **KEPT** — `EventListenerProvider::registerHandler()` reads `getPriority()` (not the tag) for dispatch sort order. Dropping it would silently change dispatch priority from 100→0, breaking contract-first ordering. Payment-base would need updating before this can be tag-driven. |
| `StripeCheckoutSessionHandler` | 0 | 0 | **DROPPED** — returns 0 = fallback; method was redundant |
| `StripeCaptureRequestHandler` | (no priority tag, default 0) | 0 | **DROPPED** — returns 0 = fallback; method was redundant |

**Rationale for keeping `StripeContractCreationHandler::getPriority()`:**
`EventListenerProvider::registerHandler()` (payment-base) uses:
```php
$priority = method_exists($handler, 'getPriority') ? $handler->getPriority() : 0;
```
The services.yaml `priority:` tag controls Symfony DI injection order only, not dispatch order. A follow-up sprint against payment-base can migrate `registerHandler()` to accept tag-supplied priority and remove the final override.

**Tests added:** `testDoesNotOverridePriorityMethod()` in each of the two affected handler test classes (RED before removal, GREEN after).

---

## S6 — StripeWebhookEndpointApi via factory

**`forKey()` factory method signature:**
```php
// StripeClientProviderInterface (new)
public function forKey(string $apiKey): StripeClient;

// StripeClientFactory (implements StripeClientProviderInterface)
public function forKey(string $apiKey): StripeClient
{
    return new StripeClient([
        'api_key'        => $apiKey,
        'stripe_version' => '2024-11-20.acacia',  // same pin as create()
    ]);
}
```

**`StripeWebhookEndpointApi` change:**
- Constructor now accepts `StripeClientProviderInterface $clientProvider`
- Private `client(string $apiKey)` now calls `$this->clientProvider->forKey($apiKey)` instead of `new StripeClient($apiKey)`

**services.yaml additions:**
- `StripeClientProviderInterface` → alias `StripeClientFactory`
- `StripeWebhookEndpointApi` arguments: `$clientProvider: '@StripeClientProviderInterface'`

**§8 coverage gap closed — `StripeWebhookEndpointApiTest` covers 15 tests:**
- `create()`: 4 tests (factory called with key, result structure, connect flag, API error wrapping)
- `update()`: 3 tests (factory called, result null secret, API error wrapping)
- `listAll()`: 4 tests (factory called, returns IDs, URL filter, API error wrapping)
- `delete()`: 2 tests (factory called with key, API error wrapping)
- Constructor: 1 test (accepts StripeClientProviderInterface)
- **WHY separate interface:** `StripeClientFactory` is `final`, so R-4.1 (interface dependency) requires a testability seam. `StripeClientProviderInterface` is narrow (one method, one purpose) per R-2.3 ISP.

---

## S5 — Eliminate ad-hoc ContainerFactory calls

**End-state `grep -rn "ContainerFactory::getInstance" src/` (only legitimate sites remain):**

```
src/Stripe/Traits/ServiceContainer.php:24    — KEEP: canonical wrapped seam (sprint spec)
src/Stripe/Core/Events.php:124               — KEEP: OXID module lifecycle hook (sprint spec)
src/Stripe/Controller/Admin/StripeConnect.php:49      — init() single-resolution ✓
src/Stripe/Controller/Admin/ModuleConfiguration.php:57 — lazy getter ✓
src/Stripe/Controller/Admin/ModuleConfiguration.php:69 — lazy getter ✓
src/Stripe/Controller/Admin/ModuleConfiguration.php:80 — lazy getter ✓
src/Stripe/Controller/Admin/ModuleConfiguration.php:94 — lazy getter (getConfigurationValidator) ✓
src/Stripe/Controller/Admin/ModuleConfiguration.php:105— lazy getter (getModuleSettingBridge) ✓
src/Stripe/Controller/Webhook/WebhookController.php:48 — init() single-resolution ✓
```

**Zero ad-hoc ContainerFactory calls in business logic.**

**Per-file changes:**

| File | Change |
|------|--------|
| `Model/Order.php` | Added `ServiceContainer` trait; replaced 2 `ContainerFactory::getInstance()` calls in `fetchStripeCharge()` and `getChargeAmountResolver()` with `$this->getServiceFromContainer()` |
| `Controller/Admin/ModuleConfiguration.php` | Added `$configurationValidator` + `getConfigurationValidator()` (protected seam) + `$moduleSettingBridge` + `getModuleSettingBridge()` (private); `stripeGetKeyValidationError()` and `saveModuleSetting()` use getters |
| `Controller/Admin/StripeConnect.php` | Moved `ContainerFactory` resolution from `__construct()` to `init()` (R-4.2: OXID admin controllers resolve in init(), not constructor) |
| `Controller/Webhook/WebhookController.php` | Added `$cleanupService` property (protected); resolve `RetryCleanupService` in `init()`; `cleanupStaleNotFinishedOrders()` uses stored property — eliminates mid-request re-fetch (former line 177, R-4.2 violation) |

**Tests added:**
- `WebhookControllerCleanupTest`: 3 tests — stored service used (not ContainerFactory), non-zero result handled, exception swallowed
- `ModuleConfigurationValidatorTest`: 2 tests — `stripeGetKeyValidationError()` delegates to injected validator via getter seam

---

## Test gate (before / after)

| Suite | Before (Unit) | After (Unit) | After (Integration) |
|-------|--------------|-------------|---------------------|
| Tests | 1047 | 1069 (+22) | 141 |
| Assertions | 2571 | 2603 (+32) | 356 |
| Failures | 0 | 0 | 0 |

**New tests: +22 unit tests across 5 new files and 2 modified files.**

---

## Quality gate (all green)

- PHPCS: 0 errors (PSR-12)
- PHPStan: 0 errors (level max, `--configuration=tests/PhpStan/phpstan.neon`)
- PHPMD: 0 new violations (baseline unchanged: 4 baselined entries)
- PHPUnit Unit: 1069/1069 passed
- PHPUnit Integration: 88/141 passed, 53 skipped (pre-existing skip pattern, unrelated to sprint)

---

## R-1…R-10 checklist

- [x] **R-1 TDD**: RED tests written before each production change; characterization tests for refactors; no method-under-test re-implementation in doubles
- [x] **R-2 SOLID**: No god-objects added; PHPMD baseline unchanged; `StripeClientProviderInterface` is narrow (ISP); `getConfigurationValidator()`/`getModuleSettingBridge()` add single-purpose lazy getters
- [x] **R-3 LI**: No security-weakening overrides; no `instanceof` downcasts added
- [x] **R-4 DI**: No new `ContainerFactory` calls in business logic; `StripeWebhookEndpointApi` constructor-injected; OXID controllers resolve once in `init()`/lazy-getters; `StripeConnect` moved from `__construct()` to `init()`
- [x] **R-5 Clean Code**: All methods ≤25 lines; no `else` added; explicit imports; no magic literals
- [x] **R-6 DevOps-first**: PHPCS/PHPStan/PHPMD/PHPUnit all green; no new suppressions; services.yaml updated for S6 (requires `oe:cache:clear` + `docker compose restart php` on deployment)
- [x] **R-7 Event-driven**: No event system changes in this sprint (S5/S6/S7 are structural, not behavioral)
- [x] **R-8 Contract-aware**: No contract state machine changes
- [x] **R-9 No overengineering**: `StripeClientProviderInterface` has exactly one method used by exactly one caller; no speculative abstractions; dead `new StripeClient($apiKey)` removed
- [x] **R-10 Persistence**: No writes; no `->save(` calls added

---

## Notes / follow-up

- **S7 partial**: `StripeContractCreationHandler::getPriority()=100` must remain until `EventListenerProvider::registerHandler()` in payment-base is updated to use tag-supplied priority for dispatch ordering. The `priority: 100` tag is already present in services.yaml (correctly), so adding tag-support to payment-base will complete the migration atomically.
- **S6 `StripeClientProviderInterface`**: Narrow interface following ISP. If `StripeClientFactory` ever needs a different concrete implementation (e.g. for multi-account support), the interface is the extension point.
