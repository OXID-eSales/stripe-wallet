# Sprint 13: Webhook URL Fix - COMPLETED

**Date:** 2025-12-08
**Status:** COMPLETE
**Branch:** b-7.4.x

---

## Problem

Stripe webhooks returning 404 error because `getWebhookUrl()` method was calling a non-existent method.

**Root Cause:**
```php
// ModuleConfigurationService.php:241
$shopUrl = $this->config->getShopUrl();  // BUG!
```

`$this->config` was of type `ModuleConfiguration` which does NOT have `getShopUrl()` method.

---

## Solution (TDD Approach)

### Sprint 13.1: RED Phase (Failing Tests)

Added 5 tests to `tests/Unit/Component/Service/ModuleConfigurationServiceTest.php`:

```php
public function testGetWebhookUrlReturnsNonEmptyString(): void
public function testGetWebhookUrlContainsWebhookController(): void
public function testGetWebhookUrlStartsWithHttpScheme(): void
public function testGetWebhookUrlNoDoubleSlashes(): void
public function testGetWebhookUrlUsesStripeWebhookController(): void
```

**Test Result (before fix):**
```
Error: Call to undefined method MockObject_ModuleConfiguration::getShopUrl()
Tests: 5, Errors: 5
```

### Sprint 13.2: GREEN Phase (Implementation)

**Changes to `ModuleConfigurationService.php`:**

1. Renamed `$this->config` → `$this->moduleConfig` for clarity
2. Added optional `ShopAdapterInterface` constructor parameter (DI)
3. Added private `getShopBaseUrl()` method with adapter/Registry fallback
4. Fixed `getWebhookUrl()` to use new helper method
5. Fixed controller name to `osc_stripe_webhook` (matches metadata.php registration)

**Fixed Code:**
```php
public function __construct(
    private ContextInterface $context,
    private ModuleConfigurationDaoInterface $moduleConfigurationDao,
    private ?ShopAdapterInterface $shopAdapter = null,  // NEW: LSP-compliant
) {
    $this->moduleConfig = $this->moduleConfigurationDao->get(...);
}

public function getWebhookUrl(): string
{
    $shopUrl = $this->getShopBaseUrl();
    return rtrim($shopUrl, '/') . '/index.php?cl=osc_stripe_webhook';
}

private function getShopBaseUrl(): string
{
    if ($this->shopAdapter !== null) {
        return $this->shopAdapter->getShopUrl();  // DI path (testable)
    }
    return Registry::getConfig()->getShopUrl();   // Fallback (BC)
}
```

**Test Result (after fix):**
```
OK (5 tests, 17 assertions)
```

---

## Verification

### Pre-commit Checks

```
✓ PHP Code Sniffer passed
✓ PHPUnit tests passed (1229 tests, 2783 assertions)
✓ PHPStan passed (No errors)
✓ PHPMD passed

ALL CHECKS PASSED
Status: COMMITABLE
```

---

## SOLID Compliance

| Principle | Application |
|-----------|-------------|
| **SRP** | `getShopBaseUrl()` handles only URL retrieval |
| **OCP** | Can extend by providing different `ShopAdapterInterface` |
| **LSP** | Uses `ShopAdapterInterface`, not concrete class |
| **ISP** | `ShopAdapterInterface` is focused, not bloated |
| **DIP** | Depends on abstraction (`ShopAdapterInterface`), not concretion |

---

## Files Modified

1. `src/Stripe/Service/ModuleConfigurationService.php`
   - Lines changed: ~20
   - Added: `ShopAdapterInterface` injection, `getShopBaseUrl()` method

2. `tests/Unit/Component/Service/ModuleConfigurationServiceTest.php`
   - Added: 5 new tests (tests 35-39)
   - Updated: `setUp()` to include `ShopAdapterInterface` mock

---

## Expected Webhook URL Format

After fix, `getWebhookUrl()` returns:
```
https://your-shop.com/index.php?cl=osc_stripe_webhook
```

This URL should be configured in Stripe Dashboard → Webhooks → Add Endpoint.

---

## E2E Integration Test

Added `tests/Integration/Stripe/Webhook/WebhookEndpointE2ETest.php` with 9 tests:

```php
webhookEndpointExistsAndDoesNotReturn404()  // CRITICAL: verifies no 404
webhookReturns400Or401ForMissingSignature()
webhookReturns400ForInvalidSignature()
webhookReturns400ForExpiredSignature()
webhookReturns400ForMalformedJson()
webhookHandlesAllEventTypes()
webhookResponseTimeIsAcceptable()           // <5000ms threshold
webhookIsAccessibleViaHttps()
webhookReturnsNon404Status()
```

**Test Target:** `https://daniil.oxiddev.de/index.php?cl=osc_stripe_webhook`

**Result:** All 9 tests pass - endpoint is reachable and responds correctly.

---

## Additional Fixes

### DI Injection Fix (WebhookController.php)

**Problem:** `oxideshop.log` showed:
```
Too few arguments to function ModuleConfigurationService::__construct(),
0 passed, at least 2 expected
```

**Root Cause:** `WebhookController::init()` used `Registry::get()` which calls `oxNew()`.
`oxNew()` does NOT support dependency injection - it just calls constructor with no arguments.

**Fix:** Changed to use Symfony DI container:
```php
// Before (broken)
$this->config = Registry::get(ModuleConfigurationService::class);

// After (working)
$container = ContainerFactory::getInstance()->getContainer();
$this->config = $container->get(ModuleConfigurationService::class);
```

### Abstract Component WebhookController

Made `src/Component/Controller/Webhook/WebhookController.php` abstract since all
webhook processing goes through `src/Stripe/Controller/Webhook/WebhookController`
which extends the Component-level class.
