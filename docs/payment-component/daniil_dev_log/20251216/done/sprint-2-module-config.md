# Sprint 2: Add Module Configuration Setting

**Status:** PENDING
**Priority:** HIGH
**Estimated Effort:** 1 hour
**Depends On:** None

---

## Objective

Add a configuration setting to the module that allows merchants to choose between automatic and manual capture modes.

---

## Tasks

### 1. Update metadata.php

**File:** `metadata.php`

Add new setting to the STRIPE_GENERAL group:

```php
['group' => 'STRIPE_GENERAL', 'name' => 'sStripeCaptureMode', 'type' => 'select', 'value' => 'automatic', 'position' => 40, 'constraints' => 'automatic|manual'],
```

### 2. Add Translations

**File:** `translations/de/osc_stripe_wallet_lang.php`

```php
'SHOP_MODULE_sStripeCaptureMode' => 'Erfassungsmodus',
'SHOP_MODULE_sStripeCaptureMode_automatic' => 'Automatisch (sofortige Erfassung)',
'SHOP_MODULE_sStripeCaptureMode_manual' => 'Manuell (verzögerte Erfassung)',
'HELP_SHOP_MODULE_sStripeCaptureMode' => 'Automatisch: Zahlung wird sofort erfasst. Manuell: Zahlung wird nur autorisiert und muss später manuell erfasst werden (z.B. beim Versand).',
```

**File:** `translations/en/osc_stripe_wallet_lang.php`

```php
'SHOP_MODULE_sStripeCaptureMode' => 'Capture Mode',
'SHOP_MODULE_sStripeCaptureMode_automatic' => 'Automatic (instant capture)',
'SHOP_MODULE_sStripeCaptureMode_manual' => 'Manual (delayed capture)',
'HELP_SHOP_MODULE_sStripeCaptureMode' => 'Automatic: Payment is captured immediately. Manual: Payment is only authorized and must be captured later manually (e.g., when shipping).',
```

### 3. Create/Update CaptureConfigurationService

**File:** `src/Stripe/Service/CaptureConfigurationService.php` (NEW)

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Stripe\Service;

use OxidSolutionCatalysts\Payments\Stripe\Module;

class CaptureConfigurationService
{
    public const CAPTURE_MODE_AUTOMATIC = 'automatic';
    public const CAPTURE_MODE_MANUAL = 'manual';

    public function __construct(
        private readonly ModuleConfigurationService $configService
    ) {
    }

    public function getCaptureMode(): string
    {
        $mode = $this->configService->getModuleSettingString('sStripeCaptureMode');

        if (!in_array($mode, [self::CAPTURE_MODE_AUTOMATIC, self::CAPTURE_MODE_MANUAL], true)) {
            return self::CAPTURE_MODE_AUTOMATIC; // Default
        }

        return $mode;
    }

    public function isAutomaticCapture(): bool
    {
        return $this->getCaptureMode() === self::CAPTURE_MODE_AUTOMATIC;
    }

    public function isManualCapture(): bool
    {
        return $this->getCaptureMode() === self::CAPTURE_MODE_MANUAL;
    }

    /**
     * Get Stripe capture_method value based on configuration.
     */
    public function getStripeCaptureMethod(): string
    {
        return $this->isAutomaticCapture() ? 'automatic' : 'manual';
    }
}
```

### 4. Write Unit Tests (TDD)

**File:** `tests/Unit/Stripe/Service/CaptureConfigurationServiceTest.php` (NEW)

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Unit\Stripe\Service;

use OxidSolutionCatalysts\Payments\Stripe\Service\CaptureConfigurationService;
use OxidSolutionCatalysts\Payments\Stripe\Service\ModuleConfigurationService;
use PHPUnit\Framework\TestCase;

class CaptureConfigurationServiceTest extends TestCase
{
    public function testGetCaptureModeReturnsAutomaticByDefault(): void
    {
        $configService = $this->createMock(ModuleConfigurationService::class);
        $configService->method('getModuleSettingString')
            ->with('sStripeCaptureMode')
            ->willReturn('');

        $service = new CaptureConfigurationService($configService);

        $this->assertEquals('automatic', $service->getCaptureMode());
    }

    public function testGetCaptureModeReturnsConfiguredValue(): void
    {
        $configService = $this->createMock(ModuleConfigurationService::class);
        $configService->method('getModuleSettingString')
            ->with('sStripeCaptureMode')
            ->willReturn('manual');

        $service = new CaptureConfigurationService($configService);

        $this->assertEquals('manual', $service->getCaptureMode());
    }

    public function testIsAutomaticCaptureReturnsTrueForAutomatic(): void
    {
        $configService = $this->createMock(ModuleConfigurationService::class);
        $configService->method('getModuleSettingString')
            ->willReturn('automatic');

        $service = new CaptureConfigurationService($configService);

        $this->assertTrue($service->isAutomaticCapture());
        $this->assertFalse($service->isManualCapture());
    }

    public function testIsManualCaptureReturnsTrueForManual(): void
    {
        $configService = $this->createMock(ModuleConfigurationService::class);
        $configService->method('getModuleSettingString')
            ->willReturn('manual');

        $service = new CaptureConfigurationService($configService);

        $this->assertTrue($service->isManualCapture());
        $this->assertFalse($service->isAutomaticCapture());
    }

    public function testGetStripeCaptureMethodReturnsCorrectStripeValue(): void
    {
        $configService = $this->createMock(ModuleConfigurationService::class);
        $configService->method('getModuleSettingString')
            ->willReturn('manual');

        $service = new CaptureConfigurationService($configService);

        $this->assertEquals('manual', $service->getStripeCaptureMethod());
    }

    public function testInvalidConfigValueDefaultsToAutomatic(): void
    {
        $configService = $this->createMock(ModuleConfigurationService::class);
        $configService->method('getModuleSettingString')
            ->willReturn('invalid_value');

        $service = new CaptureConfigurationService($configService);

        $this->assertEquals('automatic', $service->getCaptureMode());
        $this->assertTrue($service->isAutomaticCapture());
    }
}
```

### 5. Register Service in DI Container

**File:** `services.yaml`

```yaml
OxidSolutionCatalysts\Payments\Stripe\Service\CaptureConfigurationService:
    arguments:
        - '@OxidSolutionCatalysts\Payments\Stripe\Service\ModuleConfigurationService'
```

---

## Acceptance Criteria

- [ ] `sStripeCaptureMode` setting visible in admin backend
- [ ] German and English translations work
- [ ] Default value is "automatic"
- [ ] `CaptureConfigurationService` correctly reads configuration
- [ ] Invalid config values default to "automatic"
- [ ] Unit tests pass
- [ ] PHPStan level 6 passes
- [ ] PSR-12 code style passes

---

## Test Commands

```bash
# Run CaptureConfigurationService tests
docker compose exec php php vendor/bin/phpunit -c extensions/stripe/tests/phpunit.xml \
  extensions/stripe/tests/Unit/Stripe/Service/CaptureConfigurationServiceTest.php

# Verify module settings load
bin/oe-console oe:module:deactivate osc_stripe_wallet
bin/oe-console oe:module:activate osc_stripe_wallet
```

---

## Admin UI Preview

After implementation, the setting will appear in:
**Extensions → Modules → Stripe Payment Gateway → Settings**

```
┌─────────────────────────────────────────────┐
│ General Settings                            │
├─────────────────────────────────────────────┤
│ Development Mode: [ ] Enable                │
│ Mode: [Test ▼]                              │
│ Test Secret Key: [__________]               │
│ Test Public Key: [__________]               │
│                                             │
│ Capture Mode: [Automatic ▼]                 │
│   • Automatic (instant capture)             │
│   • Manual (delayed capture)                │
│                                             │
│ [?] Help: Automatic captures payment        │
│     immediately. Manual requires later      │
│     action to capture the funds.            │
└─────────────────────────────────────────────┘
```

---

## Notes

- This is a global setting, not per-payment-method
- Changing the setting only affects new payments, not existing ones
- Consider storing capture mode in contract metadata for historical accuracy
