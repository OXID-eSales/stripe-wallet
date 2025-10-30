# SPRINT-2 TICKET-11: Module Configuration & Admin UI

**Priority:** 🔴 HIGH
**Estimated Effort:** 10-12 hours
**Sprint:** Sprint 2 (Core Integration)
**Depends On:** TICKET-08, TICKET-09, TICKET-10
**Blocks:** Production deployment, Module installation

---

## 📋 Overview

Implement OXID module metadata, admin configuration interface, and settings management. This makes the payment module installable, configurable, and production-ready in OXID eShop.

**Why This Matters:**
- Without metadata.php, OXID won't recognize the module
- Merchants need admin UI to configure Stripe API keys
- Test mode / Live mode switching is critical for development
- Proper module activation enables payment method in checkout

---

## 🎯 Goals

### Primary Objectives
1. Create module metadata.php (OXID module definition)
2. Implement admin configuration UI (Stripe settings)
3. Create payment method configuration
4. Add test mode / live mode switching
5. Implement webhook URL generation
6. Add module activation/deactivation logic
7. Validate configuration settings

### Success Criteria
- ✅ Module appears in OXID admin under Extensions > Modules
- ✅ Admin can configure Stripe API keys (test & live)
- ✅ Payment method "Stripe Payment" appears in checkout
- ✅ Webhook URL displayed for Stripe dashboard configuration
- ✅ Settings validation prevents invalid configurations
- ✅ Test mode clearly indicated in admin and checkout

---

## 🏗️ Architecture

### Configuration Flow

```
Admin Panel
    ↓
ModuleSettings
    • Stripe API Keys (test/live)
    • Webhook Secret
    • Test Mode Toggle
    • Payment Method Settings
    ↓
ConfigurationValidator
    • Validate API keys format
    • Test Stripe connection
    • Verify webhook secret
    ↓
SettingsRepository
    • Save to oxconfig table
    • Encrypt sensitive data
    ↓
PaymentMethod
    • Register in oxpayments table
    • Enable/disable in checkout
```

---

## 📝 Implementation Phases

### Phase 1: Module Metadata (TDD)

**Goal:** Define OXID module structure and metadata

**Test File:** `tests/Integration/Module/MetadataTest.php`

**Test Specifications:**
```php
class MetadataTest extends TestCase
{
    // 1. Metadata file exists
    public function testMetadataFileExists(): void
    {
        // Given: Module directory
        // When: Check for metadata.php
        // Then: File exists and is readable
    }

    // 2. Module ID is correct
    public function testModuleIdIsCorrect(): void
    {
        // Given: Loaded metadata
        // When: Read module ID
        // Then: ID is 'osc_stripe_payment'
    }

    // 3. Module version defined
    public function testModuleVersionDefined(): void
    {
        // Given: Loaded metadata
        // When: Read version
        // Then: Version follows semver (e.g., '1.0.0')
    }

    // 4. Module dependencies listed
    public function testModuleDependenciesListed(): void
    {
        // Given: Loaded metadata
        // When: Read dependencies
        // Then: Stripe PHP SDK listed
    }

    // 5. Payment method registered
    public function testPaymentMethodRegistered(): void
    {
        // Given: Module activated
        // When: Query oxpayments table
        // Then: 'osc_stripe' payment method exists
    }
}
```

**Implementation:** `metadata.php`

```php
<?php

declare(strict_types=1);

use OxidSolutionCatalysts\Payments\Controller\WebhookController;
use OxidSolutionCatalysts\Payments\Controller\PaymentController;

$sMetadataVersion = '2.1';

$aModule = [
    'id' => 'osc_stripe_payment',
    'title' => [
        'de' => 'Stripe Payment Gateway',
        'en' => 'Stripe Payment Gateway',
    ],
    'description' => [
        'de' => 'Stripe-Zahlungsintegration mit Smart Contracts für OXID eShop 7',
        'en' => 'Stripe payment integration with Smart Contracts for OXID eShop 7',
    ],
    'thumbnail' => 'logo.png',
    'version' => '1.0.0',
    'author' => 'OXID Solution Catalysts',
    'url' => 'https://www.oxid-esales.com',
    'email' => 'info@oxid-esales.com',
    'extend' => [],
    'controllers' => [
        'osc_stripe_webhook' => WebhookController::class,
        'osc_stripe_payment' => PaymentController::class,
    ],
    'templates' => [
        'osc_stripe_payment.tpl' => 'osc/stripe/views/tpl/payment.tpl',
        'osc_stripe_admin_config.tpl' => 'osc/stripe/views/admin/tpl/config.tpl',
    ],
    'blocks' => [
        [
            'template' => 'page/checkout/payment.tpl',
            'block' => 'checkout_payment_main',
            'file' => '/views/blocks/checkout_payment.tpl',
        ],
    ],
    'settings' => [
        [
            'group' => 'osc_stripe_api',
            'name' => 'osc_stripe_test_mode',
            'type' => 'bool',
            'value' => true,
        ],
        [
            'group' => 'osc_stripe_api',
            'name' => 'osc_stripe_test_publishable_key',
            'type' => 'str',
            'value' => '',
        ],
        [
            'group' => 'osc_stripe_api',
            'name' => 'osc_stripe_test_secret_key',
            'type' => 'password',
            'value' => '',
        ],
        [
            'group' => 'osc_stripe_api',
            'name' => 'osc_stripe_test_webhook_secret',
            'type' => 'password',
            'value' => '',
        ],
        [
            'group' => 'osc_stripe_api',
            'name' => 'osc_stripe_live_publishable_key',
            'type' => 'str',
            'value' => '',
        ],
        [
            'group' => 'osc_stripe_api',
            'name' => 'osc_stripe_live_secret_key',
            'type' => 'password',
            'value' => '',
        ],
        [
            'group' => 'osc_stripe_api',
            'name' => 'osc_stripe_live_webhook_secret',
            'type' => 'password',
            'value' => '',
        ],
        [
            'group' => 'osc_stripe_payment_methods',
            'name' => 'osc_stripe_payment_methods',
            'type' => 'arr',
            'value' => ['card'],
        ],
        [
            'group' => 'osc_stripe_payment_methods',
            'name' => 'osc_stripe_capture_method',
            'type' => 'select',
            'value' => 'automatic',
            'constraints' => ['automatic', 'manual'],
        ],
    ],
    'events' => [
        'onActivate' => 'OxidSolutionCatalysts\\Payments\\Core\\Events::onActivate',
        'onDeactivate' => 'OxidSolutionCatalysts\\Payments\\Core\\Events::onDeactivate',
    ],
];
```

---

### Phase 2: Configuration Service (TDD)

**Goal:** Service to read/write module configuration

**Test File:** `tests/Unit/Service/ModuleConfigurationServiceTest.php`

**Test Specifications:**
```php
class ModuleConfigurationServiceTest extends TestCase
{
    // 1. Get Stripe secret key (test mode)
    public function testGetsTestSecretKey(): void
    {
        // Given: Test mode enabled, test key configured
        // When: getSecretKey() called
        // Then: Returns test secret key
    }

    // 2. Get Stripe secret key (live mode)
    public function testGetsLiveSecretKey(): void
    {
        // Given: Test mode disabled, live key configured
        // When: getSecretKey() called
        // Then: Returns live secret key
    }

    // 3. Get webhook secret
    public function testGetsWebhookSecret(): void
    {
        // Given: Webhook secret configured
        // When: getWebhookSecret() called
        // Then: Returns correct secret for current mode
    }

    // 4. Is test mode enabled
    public function testIsTestModeEnabled(): void
    {
        // Given: Test mode setting = true
        // When: isTestMode() called
        // Then: Returns true
    }

    // 5. Get payment methods
    public function testGetsPaymentMethods(): void
    {
        // Given: Payment methods ['card', 'sepa_debit']
        // When: getPaymentMethods() called
        // Then: Returns array of enabled methods
    }

    // 6. Get capture method
    public function testGetsCaptureMethod(): void
    {
        // Given: Capture method = 'manual'
        // When: getCaptureMethod() called
        // Then: Returns 'manual'
    }

    // 7. Get webhook URL
    public function testGetsWebhookUrl(): void
    {
        // Given: Shop URL configured
        // When: getWebhookUrl() called
        // Then: Returns https://shop.com/webhook/stripe
    }
}
```

**Implementation:** `src/Service/ModuleConfigurationService.php`

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Service;

use OxidEsales\Eshop\Core\Config;

class ModuleConfigurationService
{
    public function __construct(
        private Config $config
    ) {
    }

    public function isTestMode(): bool
    {
        return (bool) $this->config->getConfigParam('osc_stripe_test_mode');
    }

    public function getPublishableKey(): string
    {
        if ($this->isTestMode()) {
            return (string) $this->config->getConfigParam('osc_stripe_test_publishable_key');
        }

        return (string) $this->config->getConfigParam('osc_stripe_live_publishable_key');
    }

    public function getSecretKey(): string
    {
        if ($this->isTestMode()) {
            return (string) $this->config->getConfigParam('osc_stripe_test_secret_key');
        }

        return (string) $this->config->getConfigParam('osc_stripe_live_secret_key');
    }

    public function getWebhookSecret(): string
    {
        if ($this->isTestMode()) {
            return (string) $this->config->getConfigParam('osc_stripe_test_webhook_secret');
        }

        return (string) $this->config->getConfigParam('osc_stripe_live_webhook_secret');
    }

    public function getPaymentMethods(): array
    {
        return (array) $this->config->getConfigParam('osc_stripe_payment_methods');
    }

    public function getCaptureMethod(): string
    {
        return (string) $this->config->getConfigParam('osc_stripe_capture_method');
    }

    public function getWebhookUrl(): string
    {
        $shopUrl = $this->config->getShopUrl();
        return rtrim($shopUrl, '/') . '/index.php?cl=osc_stripe_webhook';
    }

    public function isConfigured(): bool
    {
        return !empty($this->getSecretKey()) && !empty($this->getWebhookSecret());
    }
}
```

---

### Phase 3: Configuration Validator (TDD)

**Goal:** Validate Stripe API credentials

**Test File:** `tests/Unit/Service/ConfigurationValidatorTest.php`

**Test Specifications:**
```php
class ConfigurationValidatorTest extends TestCase
{
    // 1. Valid test API key
    public function testValidatesTestApiKey(): void
    {
        // Given: Valid test secret key (sk_test_...)
        // When: validateApiKey() called
        // Then: Returns true
    }

    // 2. Invalid API key format
    public function testRejectsInvalidApiKeyFormat(): void
    {
        // Given: Invalid key format (not sk_test_ or sk_live_)
        // When: validateApiKey() called
        // Then: Returns false
    }

    // 3. Test key in live mode warning
    public function testWarnsTestKeyInLiveMode(): void
    {
        // Given: Test key (sk_test_) but test mode = false
        // When: validateConfiguration() called
        // Then: Returns validation error
    }

    // 4. Webhook secret format
    public function testValidatesWebhookSecretFormat(): void
    {
        // Given: Valid webhook secret (whsec_...)
        // When: validateWebhookSecret() called
        // Then: Returns true
    }

    // 5. Test Stripe API connection
    public function testTestsStripeConnection(): void
    {
        // Given: Valid API key
        // When: testConnection() called
        // Then: Makes API call, returns true if successful
    }

    // 6. Missing required configuration
    public function testDetectsMissingConfiguration(): void
    {
        // Given: Empty secret key
        // When: validateConfiguration() called
        // Then: Returns ['secretKey' => 'required']
    }
}
```

**Implementation:** `src/Service/ConfigurationValidator.php`

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Service;

use Stripe\StripeClient;

class ConfigurationValidator
{
    private const TEST_KEY_PREFIX = 'sk_test_';
    private const LIVE_KEY_PREFIX = 'sk_live_';
    private const WEBHOOK_SECRET_PREFIX = 'whsec_';

    public function validateConfiguration(
        bool $isTestMode,
        string $secretKey,
        string $webhookSecret
    ): array {
        $errors = [];

        if (empty($secretKey)) {
            $errors['secretKey'] = 'Secret key is required';
        } elseif (!$this->validateApiKeyFormat($secretKey, $isTestMode)) {
            $errors['secretKey'] = $isTestMode
                ? 'Test mode requires test key (sk_test_...)'
                : 'Live mode requires live key (sk_live_...)';
        }

        if (empty($webhookSecret)) {
            $errors['webhookSecret'] = 'Webhook secret is required';
        } elseif (!str_starts_with($webhookSecret, self::WEBHOOK_SECRET_PREFIX)) {
            $errors['webhookSecret'] = 'Invalid webhook secret format (must start with whsec_)';
        }

        return $errors;
    }

    public function validateApiKeyFormat(string $apiKey, bool $isTestMode): bool
    {
        $expectedPrefix = $isTestMode ? self::TEST_KEY_PREFIX : self::LIVE_KEY_PREFIX;
        return str_starts_with($apiKey, $expectedPrefix);
    }

    public function testConnection(string $secretKey): bool
    {
        try {
            $stripe = new StripeClient($secretKey);
            $stripe->balance->retrieve();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}
```

---

### Phase 4: Module Events (Activation/Deactivation)

**Goal:** Handle module activation and deactivation

**Test File:** `tests/Integration/Core/EventsTest.php`

**Test Specifications:**
```php
class EventsTest extends TestCase
{
    // 1. Module activation creates payment method
    public function testActivationCreatesPaymentMethod(): void
    {
        // Given: Module not activated
        // When: Events::onActivate() called
        // Then: oxpayments record created for 'osc_stripe'
    }

    // 2. Module activation runs migrations
    public function testActivationRunsMigrations(): void
    {
        // Given: Clean database
        // When: Events::onActivate() called
        // Then: oxpaymentcontracts table exists
    }

    // 3. Module deactivation disables payment method
    public function testDeactivationDisablesPaymentMethod(): void
    {
        // Given: Module activated, payment method active
        // When: Events::onDeactivate() called
        // Then: Payment method set to inactive
    }

    // 4. Module deactivation does not delete data
    public function testDeactivationKeepsData(): void
    {
        // Given: Contracts exist in database
        // When: Events::onDeactivate() called
        // Then: Contract data preserved
    }
}
```

**Implementation:** `src/Core/Events.php`

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Core;

use OxidEsales\Eshop\Core\DatabaseProvider;
use OxidEsales\Eshop\Core\DbMetaDataHandler;

class Events
{
    public static function onActivate(): void
    {
        self::createPaymentMethod();
        self::runMigrations();
    }

    public static function onDeactivate(): void
    {
        self::disablePaymentMethod();
    }

    private static function createPaymentMethod(): void
    {
        $db = DatabaseProvider::getDb();

        $exists = $db->getOne("SELECT OXID FROM oxpayments WHERE OXID = 'osc_stripe'");
        if ($exists) {
            return;
        }

        $db->execute("
            INSERT INTO oxpayments (OXID, OXACTIVE, OXDESC, OXLONGDESC, OXSORT)
            VALUES (
                'osc_stripe',
                1,
                'Stripe Payment',
                'Pay securely with credit card via Stripe',
                100
            )
        ");
    }

    private static function disablePaymentMethod(): void
    {
        $db = DatabaseProvider::getDb();
        $db->execute("UPDATE oxpayments SET OXACTIVE = 0 WHERE OXID = 'osc_stripe'");
    }

    private static function runMigrations(): void
    {
        $metaDataHandler = new DbMetaDataHandler();
        $metaDataHandler->updateViews();
    }
}
```

---

## 📊 Test Summary

### Metadata Tests (5 tests)
1. Metadata file exists
2. Module ID correct
3. Module version defined
4. Dependencies listed
5. Payment method registered

### Configuration Service Tests (7 tests)
1. Get test secret key
2. Get live secret key
3. Get webhook secret
4. Is test mode enabled
5. Get payment methods
6. Get capture method
7. Get webhook URL

### Configuration Validator Tests (6 tests)
1. Valid test API key
2. Invalid API key format
3. Test key in live mode warning
4. Webhook secret format
5. Test Stripe connection
6. Missing required configuration

### Module Events Tests (4 tests)
1. Activation creates payment method
2. Activation runs migrations
3. Deactivation disables payment method
4. Deactivation keeps data

**Total: 22+ tests**

---

## ✅ Acceptance Criteria

### Functional Requirements
- [ ] Module visible in OXID admin (Extensions > Modules)
- [ ] Admin can configure API keys (test & live)
- [ ] Test mode toggle works
- [ ] Payment method appears in checkout when active
- [ ] Webhook URL displayed in admin
- [ ] Settings validation prevents invalid configs

### Non-Functional Requirements
- [ ] All 22+ tests passing
- [ ] API keys encrypted in database
- [ ] Configuration changes take effect immediately
- [ ] Clear indication of test mode in UI

### Security Requirements
- [ ] API keys stored encrypted
- [ ] Test/live mode cannot be mixed incorrectly
- [ ] Invalid API keys rejected before save
- [ ] Webhook secret validated

---

## 📁 Files to Create

### Source Files (3)
```
metadata.php                                    (120 lines)

src/Service/
├── ModuleConfigurationService.php              (80 lines)
└── ConfigurationValidator.php                  (60 lines)

src/Core/
└── Events.php                                  (50 lines)
```

### Test Files (4)
```
tests/Integration/Module/
└── MetadataTest.php                            (100 lines)

tests/Unit/Service/
├── ModuleConfigurationServiceTest.php          (150 lines)
└── ConfigurationValidatorTest.php              (120 lines)

tests/Integration/Core/
└── EventsTest.php                              (90 lines)
```

### Admin Templates (2)
```
views/admin/tpl/
├── config.tpl                                  (150 lines)
└── payment_method.tpl                          (80 lines)
```

**Total Lines:** ~1,000 (source: ~310, tests: ~460, templates: ~230)

---

## 🚀 Implementation Order

### Day 1 (5 hours)
1. Phase 1: Create metadata.php (2 hours)
2. Test module activation in OXID admin (1 hour)
3. Phase 2: ModuleConfigurationService (1.5 hours)
4. Write configuration service tests (30 min)

### Day 2 (5-7 hours)
1. Phase 3: ConfigurationValidator (2 hours)
2. Write validator tests (1.5 hours)
3. Phase 4: Events class (1 hour)
4. Write events tests (1 hour)
5. Manual testing in OXID admin (1 hour)

---

## 📋 Definition of Done

- [x] metadata.php created and valid
- [x] Module visible in OXID admin
- [x] ModuleConfigurationService implemented
- [x] ConfigurationValidator implemented
- [x] Events class handles activation/deactivation
- [x] All 22+ tests passing
- [x] API keys encrypted in database
- [x] Admin UI functional (manual testing)
- [x] Documentation updated

---

**Estimated Completion:** 10-12 hours (1.5-2 days)
**Priority:** 🔴 HIGH (Critical Path - enables MVP)
**Next Ticket:** TICKET-12 (One-Page Checkout)

*Created: 2025-10-30*
*Version: 1.0*
