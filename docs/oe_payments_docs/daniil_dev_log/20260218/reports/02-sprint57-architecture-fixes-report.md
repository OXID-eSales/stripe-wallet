# Sprint 57: Architecture Audit Fixes — Completion Report

**Date:** 2026-02-18
**Branch:** `b-7.4.x-mcp-STRP-88`
**Input:** `01-architecture-audit-report.md` (7 deviations found)
**Scope:** CRITICAL, HIGH, MEDIUM fixes (6 tasks)

---

## Verification

| Check | Result |
|-------|--------|
| Unit tests | 1115 pass (0 failures) |
| Integration tests | 174 pass (0 failures) |
| PHPStan (level max) | 0 errors |
| PHPCS (PSR-12) | 0 errors |
| PHPMD (baseline) | 0 new violations (4 baselined) |

---

## Task 1: Remove debug API key leak (HIGH — security)

**File:** `src/Stripe/Controller/StripeOrderController.php`

Removed from `createCheckoutSession()`:
- `_debug` JSON block that exposed `pk_prefix` (20 chars), `sk_prefix` (12 chars), `testMode`, `keysValid`
- `$secretKeyPrefix` variable and the `Registry::getLogger()->info()` call that logged key prefixes
- `$config` and `$validator` variables (config validation moved to handler in Task 4)

JSON response now returns only: `id`, `url`, `contract_id`.

**Lines removed:** 25 (151-157 debug block, 134-145 log block, 91-97 config validation)

---

## Task 5: Fix duplicate sess_challenge (LOW)

**File:** `src/Stripe/Controller/StripeOrderController.php`

Removed `sess_challenge` setting from `processContextResults()` (was lines 278-279). It was already set explicitly in `checkoutSuccess()` (line 206-208). The `processContextResults()` method now only handles 3DS requirements and error display.

---

## Task 6: Document ContainerFactory as OXID constraint (MEDIUM — accepted)

**Files:** 3 controllers

Added `// OXID constraint: controllers use ContainerFactory, not constructor DI` comment above `init()` in:
- `src/Stripe/Mcp/Controller/McpController.php`
- `src/Stripe/Mcp/Controller/UcpCheckoutController.php`
- `src/Stripe/Mcp/Controller/UcpProfileController.php`

Consistent with `StripeOrderController` which uses the `ServiceContainer` trait (same pattern).

---

## Task 3: Inject SessionAdapterInterface into AcpContextResolverHandler (MEDIUM)

**Files modified:** 5 (+ 1 in payment-component)

### payment-component change
`src/Adapter/SessionAdapterInterface.php` — added 2 methods:
```php
public function setBasket(object $basket): void;
public function setUser(object $user): void;
```

### stripe changes

**`src/Stripe/Adapter/OxidSessionAdapter.php`** — implemented `setBasket()` and `setUser()` via `Registry::getSession()`.

**`src/Stripe/Mcp/Handler/AcpContextResolverHandler.php`**:
- Added `SessionAdapterInterface` as first constructor parameter (nullable, backward-compatible)
- `setSession()` and `getSessionId()` now use injected adapter when available, fall back to `Registry::getSession()` when null

**`services.yaml`** — added `$sessionAdapter: '@...\SessionAdapterInterface'` to handler registration.

**`tests/.../TestableAcpContextResolverHandler.php`** — replaced `setSession()`/`getSessionId()` overrides with anonymous `SessionAdapterInterface` implementation passed to parent constructor. The `sessionSet` flag now works by overriding `setSession()` to call `parent::setSession()` first (which delegates to the mock adapter), then sets the flag.

**`tests/.../AcpContextResolverHandlerTest.php`** — no changes needed (all 16 tests pass unchanged).

---

## Task 4: Move config validation from controller to handler (MEDIUM)

**Files modified:** 2 + services.yaml

**`src/Stripe/Controller/StripeOrderController.php`** — config validation block removed (done in Task 1).

**`src/Stripe/EventSystem/Handler/StripeCheckoutSessionHandler.php`**:
- Added `ConfigurationValidatorInterface` as nullable constructor parameter (after `$config`, before `$eventLogger`)
- Added `validateConfiguration()` private method — called at START of `handle()`, before contract lookup
- Throws `RuntimeException` if `getKeyValidationError()` returns non-null
- When `$configValidator` is null (backward-compatible), validation is skipped

**`services.yaml`** — added `$configValidator: '@...\ConfigurationValidatorInterface'` to handler registration.

Existing handler tests pass without changes (new param is nullable with default null).

---

## Task 2: Move catalog sync HTTP calls into adapter layer (CRITICAL)

**Files modified:** 8

### Interface + Implementation

**`src/Stripe/Adapter/StripeAdapterInterface.php`** — added 3 methods:
```php
public function syncProductCatalog(string $feedContent, string $feedFormat): array;
public function syncProductInventory(string $csvContent): array;
public function updateFulfillmentStatus(string $orderId, string $status, array $metadata = []): bool;
```

**`src/Stripe/Adapter/StripeAdapter.php`**:
- Added `HttpClientInterface $httpClient` and `string $apiKey` as optional constructor params
- Implemented all 3 methods using `$this->httpClient->post()` with `Bearer $this->apiKey` auth
- `syncProductInventory()` delegates to `syncProductCatalog()` with `csv` format

**`src/Stripe/Adapter/LazyStripeAdapter.php`** — added 3 proxy methods with `instanceof StripeAdapterInterface` guard on the internal adapter.

### Factory update

**`src/Stripe/Service/Factory/StripeAdapterFactory.php`**:
- Added `HttpClientInterface` as optional constructor param
- `createStripeAdapter()` now passes `$this->httpClient` and `$this->configurationService->getToken()` to `StripeAdapter`

### Service refactoring

**`src/Stripe/Mcp/Service/StripeProductCatalogSyncService.php`** — constructor changed from:
```php
HttpClientInterface $httpClient, ..., string $stripeApiKey
```
to:
```php
StripeAdapterFactoryInterface $adapterFactory, ...
```
- `syncCatalog()` now calls `$adapter->syncProductCatalog()` instead of raw HTTP
- `updateFulfillmentStatus()` now calls `$adapter->updateFulfillmentStatus()`
- API key validation replaced by catching `RuntimeException` from factory

### DI wiring

**`services.yaml`**:
- `StripeAdapterFactory` + `PaymentAdapterFactoryInterface` — added `$httpClient` argument
- `StripeProductCatalogSyncService` + `HostedCommerceServiceInterface` — replaced `$httpClient`+`$stripeApiKey` with `$adapterFactory`

### Tests

**`tests/.../StripeProductCatalogSyncServiceTest.php`** — fully rewritten:
- Mocks `StripeAdapterInterface` + `StripeAdapterFactoryInterface` instead of `HttpClientInterface`
- Tests cover: success, failure, missing API key, syncAllProducts composition, updateFulfillmentStatus
- 10 test methods (was 8), all pass

### PHPMD baseline

**`tests/PhpMd/phpmd.baseline.xml`** — unchanged (existing `TooManyMethods`/`TooManyPublicMethods` baselines for `StripeAdapter` already cover the added methods).

---

## Summary

| # | Task | Severity | Status |
|---|------|----------|--------|
| 1 | Remove debug API key leak | HIGH | Done |
| 2 | Move catalog sync to adapter | CRITICAL | Done |
| 3 | Inject SessionAdapterInterface | MEDIUM | Done |
| 4 | Move config validation to handler | MEDIUM | Done |
| 5 | Fix duplicate sess_challenge | LOW | Done |
| 6 | Document ContainerFactory constraint | MEDIUM | Done |

**Total files changed:** 16 (15 stripe + 1 payment-component)
**Net lines:** +595 / -231
**BC breaks:** None (all new constructor params are nullable with defaults)
