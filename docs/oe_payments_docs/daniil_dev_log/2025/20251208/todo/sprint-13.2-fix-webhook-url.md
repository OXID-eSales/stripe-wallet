# Sprint 13.2: Fix `getWebhookUrl()` Bug (GREEN Phase)

## Development Principles

| Principle | Application in This Sprint |
|-----------|---------------------------|
| **TDD-FIRST** | Tests from Sprint 13.1 must pass after fix (GREEN) |
| **SOLID - SRP** | `ModuleConfigurationService` should only manage config, not URL generation complexity |
| **LSP** | Inject `ShopAdapterInterface`, not concrete class |
| **DI** | Add optional parameter to maintain backward compatibility |
| **No Over-Engineering** | Simple fix - don't create new services unless necessary |
| **No Duplicate Code** | Reuse existing `OxidShopAdapter::getShopUrl()` |
| **No Reinventing** | `ShopAdapterInterface` already exists - use it! |

---

## Objective

Fix the broken `getWebhookUrl()` method in `ModuleConfigurationService`.

---

## Bug Analysis

**File**: `src/Stripe/Service/ModuleConfigurationService.php`
**Lines**: 239-243

### Current (Broken) Code

```php
public function getWebhookUrl(): string
{
    $shopUrl = $this->config->getShopUrl();  // BUG!
    return rtrim($shopUrl, '/') . '/index.php?cl=osc_stripe_webhook';
}
```

### Problem

`$this->config` is of type `ModuleConfiguration`:
```php
private ModuleConfiguration $config;  // Line 48
```

The `ModuleConfiguration` class is:
```
OxidEsales\EshopCommunity\Internal\Framework\Module\Configuration\DataObject\ModuleConfiguration
```

This class does **NOT** have a `getShopUrl()` method. The method exists on:
```
OxidEsales\Eshop\Core\Config
```

---

## Solution Options

### Option A: Direct Registry Call (Simple Fix)

**Pros**: Minimal change, quick fix
**Cons**: Couples to OXID Registry, less testable

```php
public function getWebhookUrl(): string
{
    $shopUrl = \OxidEsales\Eshop\Core\Registry::getConfig()->getShopUrl();
    return rtrim($shopUrl, '/') . '/index.php?cl=stripe_webhook';
}
```

### Option B: Inject ShopAdapterInterface (SOLID Fix)

**Pros**: Follows DI principles, testable, platform-agnostic
**Cons**: Requires constructor change and services.yaml update

```php
// Constructor change
public function __construct(
    private ContextInterface $context,
    private ModuleConfigurationDaoInterface $moduleConfigurationDao,
    private ShopAdapterInterface $shopAdapter,  // ADD
) {
    $this->config = $this->moduleConfigurationDao->get(
        Module::MODULE_ID,
        $this->context->getCurrentShopId()
    );
}

// Method fix
public function getWebhookUrl(): string
{
    $shopUrl = $this->shopAdapter->getShopUrl();
    return rtrim($shopUrl, '/') . '/index.php?cl=stripe_webhook';
}
```

### Option C: Create Dedicated Property for OXID Config (Hybrid)

```php
private ModuleConfiguration $moduleConfig;  // Renamed
private Config $shopConfig;

public function __construct(
    private ContextInterface $context,
    private ModuleConfigurationDaoInterface $moduleConfigurationDao,
) {
    $this->moduleConfig = $this->moduleConfigurationDao->get(...);
    $this->shopConfig = \OxidEsales\Eshop\Core\Registry::getConfig();
}

public function getWebhookUrl(): string
{
    return rtrim($this->shopConfig->getShopUrl(), '/') . '/index.php?cl=stripe_webhook';
}
```

---

## Recommended: Option B (SOLID Fix)

### Step 1: Update Constructor

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Stripe\Service;

use OxidEsales\Eshop\Core\Config;
use OxidEsales\Eshop\Core\Registry;
use OxidEsales\EshopCommunity\Internal\Framework\Module\Configuration\Dao\ModuleConfigurationDaoInterface;
use OxidEsales\EshopCommunity\Internal\Framework\Module\Configuration\DataObject\ModuleConfiguration;
use OxidEsales\EshopCommunity\Internal\Transition\Utility\ContextInterface;
use OxidSolutionCatalysts\Payments\Component\Adapter\ShopAdapterInterface;
use OxidSolutionCatalysts\Payments\Component\Service\ServiceInterface;
use OxidSolutionCatalysts\Payments\Stripe\Module;
use Throwable;

class ModuleConfigurationService implements ServiceInterface
{
    private ModuleConfiguration $moduleConfig;

    public function __construct(
        private ContextInterface $context,
        private ModuleConfigurationDaoInterface $moduleConfigurationDao,
        private ?ShopAdapterInterface $shopAdapter = null,  // Optional for BC
    ) {
        $this->moduleConfig = $this->moduleConfigurationDao->get(
            Module::MODULE_ID,
            $this->context->getCurrentShopId()
        );
    }

    // ... existing methods ...

    /**
     * Get the webhook URL for Stripe configuration
     */
    public function getWebhookUrl(): string
    {
        $shopUrl = $this->getShopBaseUrl();
        return rtrim($shopUrl, '/') . '/index.php?cl=stripe_webhook';
    }

    /**
     * Get shop base URL using adapter or fallback to Registry
     */
    private function getShopBaseUrl(): string
    {
        if ($this->shopAdapter !== null) {
            return $this->shopAdapter->getShopUrl();
        }

        // Fallback for backward compatibility
        return Registry::getConfig()->getShopUrl();
    }

    // Update get() to use $this->moduleConfig instead of $this->config
    public function get(string $name): mixed
    {
        try {
            return $this->moduleConfig->getModuleSetting($name)->getValue();
        } catch (Throwable $e) {
            return '';
        }
    }
}
```

### Step 2: Update services.yaml

```yaml
# In services.yaml, add ShopAdapterInterface to ModuleConfigurationService

OxidSolutionCatalysts\Payments\Stripe\Service\ModuleConfigurationService:
    arguments:
        $context: '@OxidEsales\EshopCommunity\Internal\Transition\Utility\ContextInterface'
        $moduleConfigurationDao: '@OxidEsales\EshopCommunity\Internal\Framework\Module\Configuration\Dao\ModuleConfigurationDaoInterface'
        $shopAdapter: '@OxidSolutionCatalysts\Payments\Stripe\Adapter\OxidShopAdapter'
    public: true
```

---

## Controller Key Decision

Two webhook controllers are registered:

| Controller Key | Class | Notes |
|---------------|-------|-------|
| `osc_stripe_webhook` | Component\Controller\Webhook\WebhookController | Generic component-level |
| `stripe_webhook` | Stripe\Controller\Webhook\WebhookController | Stripe-specific with full logging |

**Recommendation**: Use `stripe_webhook` as it has:
- Raw request logging
- Signature verification
- WebhookProcessingService integration
- Contract fulfillment handling

---

## Implementation Checklist

- [ ] Rename `$this->config` to `$this->moduleConfig` (clarity)
- [ ] Add optional `ShopAdapterInterface` parameter to constructor
- [ ] Add `getShopBaseUrl()` private method with fallback
- [ ] Update `getWebhookUrl()` to use `getShopBaseUrl()`
- [ ] Change controller key from `osc_stripe_webhook` to `stripe_webhook`
- [ ] Update `services.yaml` to inject `OxidShopAdapter`
- [ ] Run unit tests
- [ ] Run integration tests

---

## Verification

After fix, tests should pass:

```bash
# Unit tests
docker compose exec -T php bash -c "cd /var/www/test-module && vendor/bin/phpunit -c tests/phpunit.xml tests/Unit/Stripe/Service/ModuleConfigurationServiceWebhookUrlTest.php"

# Expected output:
# OK (5 tests, 5 assertions)

# Integration tests
docker compose exec -T php vendor/bin/phpunit \
  -c /var/www/test-module/tests/phpunit.xml \
  --bootstrap=/var/www/source/bootstrap.php \
  /var/www/test-module/tests/Integration/Stripe/Webhook/WebhookEndpointReachabilityTest.php

# Expected output:
# OK (4 tests, 4 assertions)
```

---

## Rollback Plan

If fix causes issues:

1. Revert constructor change
2. Use simple Registry call as temporary fix:

```php
public function getWebhookUrl(): string
{
    $shopUrl = Registry::getConfig()->getShopUrl();
    return rtrim($shopUrl, '/') . '/index.php?cl=stripe_webhook';
}
```
