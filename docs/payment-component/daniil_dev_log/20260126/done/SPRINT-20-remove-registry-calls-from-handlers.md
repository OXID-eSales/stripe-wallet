# SPRINT-20: Remove Registry Calls from Handlers

**Priority:** HIGH
**Estimated Effort:** 4-6h
**Impact:** Fix 96 PHPUnit errors + 3 failures
**Root Cause:** `Registry::getLogger()`, `Registry::getConfig()`, `Registry::getSession()` trigger OXID container build which fails in unit test environment

---

## Problem Statement

Unit tests fail with:
```
ArgumentCountError: Too few arguments to function
OxidEsales\EshopCommunity\Internal\Framework\Module\Facade\ActiveModulesDataProviderBridge::__construct()
```

This happens because handlers call `Registry::*` methods which attempt to build the OXID DI container during unit tests. The container build fails because required constructor arguments are missing in the test environment.

**Affected Handlers (in src/Stripe/EventSystem/Handler/):**

| File | Registry Calls | Lines |
|------|---------------|-------|
| `StripeCancelAuthorizationRequestHandler.php` | `Registry::getConfig()->getShopId()` | 122, 167 |
| `StripeCaptureRequestHandler.php` | `Registry::getConfig()->getShopId()` | 318, 355 |
| `StripeCheckoutReturnHandler.php` | `Registry::getSession()` | 236 |
| `StripeCheckoutSessionHandler.php` | `Registry::getConfig()->getShopUrl()` | 81 |
| `StripeOrderCreationHandler.php` | `Registry::getLogger()`, `Registry::getSession()` | 101, 110, 122, 127, 133, 140, 168, 185, 300, 325 |
| `StripeRefundRequestHandler.php` | `Registry::getConfig()->getShopId()` | 270, 321 |

**Other Affected Files:**
- `src/Stripe/Controller/Admin/OrderRefund.php` - multiple `Registry::getLogger()` calls
- `src/Stripe/Adapter/OxidShopOrderService.php` - multiple `Registry::getLogger()` calls
- `src/Stripe/Service/WebhookProcessingService.php` - MANY calls (DEPRECATED service)
- `src/Stripe/Model/Order.php` - multiple `Registry::getLogger()` calls
- `src/Stripe/Controller/StripeOrderController.php` - `Registry::getLogger()` calls

---

## Requirements

### R1: Remove Registry::getConfig()->getShopId() from Handlers
- Inject `ShopAdapterInterface` which provides `getShopId(): int`
- Replace all `Registry::getConfig()->getShopId()` with `$this->shopAdapter->getShopId()`

### R2: Remove Registry::getConfig()->getShopUrl() from Handlers
- Inject `ShopAdapterInterface` which provides `getShopUrl(): string`
- Replace all `Registry::getConfig()->getShopUrl()` with `$this->shopAdapter->getShopUrl()`

### R3: Remove Registry::getLogger() from Handlers
- All handlers already have `$eventLogger` injected (FileLoggerInterface)
- Replace `Registry::getLogger()` with `$this->eventLogger->log()` or inject `LoggerInterface`

### R4: Remove Registry::getSession() from Handlers
- Pass session data through event context instead of accessing Registry
- Or inject a session service interface

### R5: Update services.yaml
- Add `$shopAdapter` argument to affected handlers

### R6: All tests must pass
- PHPUnit: 0 errors, 0 failures
- PHPStan level 6
- PHPCS PSR-12

---

## Implementation Strategy

### Option A: Inject ShopAdapterInterface (Recommended)
The `ShopAdapterInterface` already exists and provides:
- `getShopId(): int`
- `getShopUrl(): string`
- `translate(string $key): string`

This is the cleanest approach - handlers become platform-agnostic.

### Option B: Pass shopId Through Event Context
Events already have a context array. We could pass `shopId` through the event.
Downside: Requires updating all event dispatchers.

---

## TDD Implementation

### Step 1: Fix StripeCancelAuthorizationRequestHandler

**Current code (lines 122, 167):**
```php
shopId: (int) Registry::getConfig()->getShopId()
```

**Refactored:**
```php
// Constructor
public function __construct(
    private readonly CancelAuthorizationServiceInterface $cancelService,
    private readonly RequestLogServiceInterface $requestLogService,
    private readonly LoggerInterface $logger,
    private readonly FileLoggerInterface $eventLogger,
    private readonly ShopAdapterInterface $shopAdapter  // NEW
) {
}

// Usage
shopId: $this->shopAdapter->getShopId()
```

**services.yaml update:**
```yaml
OxidEsales\Payments\Stripe\EventSystem\Handler\StripeCancelAuthorizationRequestHandler:
    arguments:
      $cancelService: '@OxidEsales\Payments\Stripe\Service\CancelAuthorizationServiceInterface'
      $requestLogService: '@OxidEsales\Payments\Stripe\Service\RequestLogServiceInterface'
      $logger: '@oxid_esales.monolog.logger'
      $eventLogger: '@stripe.events.file_logger'
      $shopAdapter: '@OxidEsales\PaymentComponent\Adapter\ShopAdapterInterface'  # NEW
    tags:
      - { name: payment.event_handler }
    public: false
```

### Step 2: Fix StripeCaptureRequestHandler

Same pattern - inject `ShopAdapterInterface`, replace `Registry::getConfig()->getShopId()`.

### Step 3: Fix StripeRefundRequestHandler

Same pattern - inject `ShopAdapterInterface`, replace `Registry::getConfig()->getShopId()`.

### Step 4: Fix StripeCheckoutSessionHandler

**Current code (line 81):**
```php
$defaultShopUrl = \OxidEsales\EshopCommunity\Core\Registry::getConfig()->getShopUrl();
```

**Refactored:**
```php
// Constructor already has some deps, add ShopAdapterInterface
private readonly ShopAdapterInterface $shopAdapter

// Usage
$defaultShopUrl = $this->shopAdapter->getShopUrl();
```

### Step 5: Fix StripeOrderCreationHandler

This handler has multiple issues:
1. `Registry::getLogger()` - use injected `$eventLogger` instead
2. `Registry::getSession()` - needs session service or pass through event

**For Registry::getLogger():**
```php
// Before
Registry::getLogger()->warning('...');

// After
$this->eventLogger->log('WARNING', [...]);
```

**For Registry::getSession():**
Option A: Pass basket/session data through event
Option B: Inject a SessionServiceInterface

### Step 6: Fix StripeCheckoutReturnHandler

**Current code (line 236):**
```php
$session = Registry::getSession();
```

This is used to restore basket. Need to inject session service.

---

## Affected Handlers Summary

| Handler | Changes Needed |
|---------|---------------|
| `StripeCancelAuthorizationRequestHandler` | + ShopAdapterInterface |
| `StripeCaptureRequestHandler` | + ShopAdapterInterface |
| `StripeRefundRequestHandler` | + ShopAdapterInterface |
| `StripeCheckoutSessionHandler` | + ShopAdapterInterface |
| `StripeOrderCreationHandler` | + ShopAdapterInterface, replace Registry::getLogger() |
| `StripeCheckoutReturnHandler` | + SessionServiceInterface or pass session through event |

---

## Files to Modify

| File | Action |
|------|--------|
| `src/Stripe/EventSystem/Handler/StripeCancelAuthorizationRequestHandler.php` | Inject ShopAdapterInterface |
| `src/Stripe/EventSystem/Handler/StripeCaptureRequestHandler.php` | Inject ShopAdapterInterface |
| `src/Stripe/EventSystem/Handler/StripeRefundRequestHandler.php` | Inject ShopAdapterInterface |
| `src/Stripe/EventSystem/Handler/StripeCheckoutSessionHandler.php` | Inject ShopAdapterInterface |
| `src/Stripe/EventSystem/Handler/StripeOrderCreationHandler.php` | Inject ShopAdapterInterface, replace Registry::getLogger() |
| `src/Stripe/EventSystem/Handler/StripeCheckoutReturnHandler.php` | Handle session access |
| `services.yaml` | Add $shopAdapter argument to handlers |

---

## Lower Priority (Can be separate sprint)

These files also use Registry but may be lower priority:
- `src/Stripe/Controller/Admin/OrderRefund.php` - Admin controller, less critical for unit tests
- `src/Stripe/Adapter/OxidShopOrderService.php` - Service layer
- `src/Stripe/Service/WebhookProcessingService.php` - DEPRECATED, consider deletion
- `src/Stripe/Model/Order.php` - OXID model extension
- `src/Stripe/Controller/StripeOrderController.php` - Frontend controller

---

## Verification

```bash
# Run unit tests only (should show improvement)
docker compose exec php php vendor/bin/phpunit -c extensions/stripe/tests/phpunit.xml --testsuite Unit

# Run full pre-commit check
./bin/pre-commit-check.sh --full

# Expected: All checks pass
# - PHPUnit: 0 errors, 0 failures
# - PHPStan: No errors
# - PHPCS: No style violations
```

---

## Acceptance Criteria

- [ ] No `Registry::getConfig()->getShopId()` in event handlers
- [ ] No `Registry::getConfig()->getShopUrl()` in event handlers
- [ ] No `Registry::getLogger()` in event handlers (use injected logger)
- [ ] No `Registry::getSession()` in event handlers (use injected service or event context)
- [ ] `services.yaml` updated with new dependencies
- [ ] PHPUnit: 0 errors, 0 failures
- [ ] PHPStan: No errors
- [ ] `./bin/pre-commit-check.sh --full` passes
