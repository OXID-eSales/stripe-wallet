# Sprint 8: Fix ModuleConfigurationServiceTest

**Status:** COMPLETE
**Estimated Hours:** 0.5h (actual)
**Priority:** HIGH (26 test errors → 0)

## Problem Analysis

The `ModuleConfigurationServiceTest` is outdated and uses wrong constructor signature.

### Current Test (Wrong)
```php
protected function setUp(): void
{
    $this->configMock = $this->createMock(Config::class);
    $this->service = new ModuleConfigurationService($this->configMock);
}
```

### Actual Constructor (Service)
```php
public function __construct(
    private ContextInterface $context,
    private ModuleConfigurationDaoInterface $moduleConfigurationDao,
) {
    $this->config = $this->moduleConfigurationDao->get(Module::MODULE_ID, $this->context->getCurrentShopId());
}
```

### Error
```
TypeError: ModuleConfigurationService::__construct(): Argument #1 ($context) must be of type
OxidEsales\EshopCommunity\Internal\Transition\Utility\ContextInterface,
MockObject_Config_e7762393 given
```

## Tasks

### Task 8.1: Update Test Mock Setup

**File:** `tests/Unit/Component/Service/ModuleConfigurationServiceTest.php`

- [ ] Mock `ContextInterface` instead of `Config`
- [ ] Mock `ModuleConfigurationDaoInterface`
- [ ] Mock `ModuleConfiguration` returned by DAO
- [ ] Update constructor call

### Task 8.2: Update Test Methods

Each test method needs to:
- [ ] Configure `ModuleConfiguration` mock to return expected settings
- [ ] Use `ModuleSetting` mock with `getValue()` method

### Task 8.3: Implementation Plan

```php
protected function setUp(): void
{
    parent::setUp();

    // Mock ContextInterface
    $this->context = $this->createMock(ContextInterface::class);
    $this->context->method('getCurrentShopId')->willReturn(1);

    // Mock ModuleConfiguration
    $this->moduleConfig = $this->createMock(ModuleConfiguration::class);

    // Mock ModuleConfigurationDaoInterface
    $this->moduleConfigDao = $this->createMock(ModuleConfigurationDaoInterface::class);
    $this->moduleConfigDao
        ->method('get')
        ->with(Module::MODULE_ID, 1)
        ->willReturn($this->moduleConfig);

    // Create service
    $this->service = new ModuleConfigurationService($this->context, $this->moduleConfigDao);
}

// Example test method update:
public function testGetsTestSecretKey(): void
{
    $testSecretKey = 'sk_test_51ABC123';

    $setting = $this->createMock(ModuleSetting::class);
    $setting->method('getValue')->willReturn($testSecretKey);

    $this->moduleConfig
        ->method('getModuleSetting')
        ->with('sStripeTestKey')
        ->willReturn($setting);

    // Also need to mock sStripeMode setting
    // ...

    $result = $this->service->getSecretKey();
    $this->assertEquals($testSecretKey, $result);
}
```

### Task 8.4: Required Imports

```php
use OxidEsales\EshopCommunity\Internal\Transition\Utility\ContextInterface;
use OxidEsales\EshopCommunity\Internal\Framework\Module\Configuration\Dao\ModuleConfigurationDaoInterface;
use OxidEsales\EshopCommunity\Internal\Framework\Module\Configuration\DataObject\ModuleConfiguration;
use OxidEsales\EshopCommunity\Internal\Framework\Module\Setting\Setting as ModuleSetting;
```

## Tests to Fix (26 total)

1. testGetsTestSecretKey
2. testGetsLiveSecretKey
3. testGetsWebhookSecret
4. testGetsWebhookEndpoint
5. testIsTestModeEnabled
6. testIsTestModeDisabled
7. testIsTransactionLoggingEnabled
8. testGetsStatusPending
9. testGetsStatusProcessing
10. testGetsWebhookUrl
11. testGetsWebhookUrlRemovesTrailingSlash
12. testGetsTestPublishableKey
13. testGetsLivePublishableKey
14. testIsConfiguredReturnsTrueWhenKeysSet
15. testIsConfiguredReturnsFalseWhenSecretKeyMissing
16. testIsConfiguredReturnsFalseWhenWebhookSecretMissing
17. testGetsTestToken
18. testIsRemoveByBillingCountry
19. testIsRemoveByBasketCurrency
20. testShouldProvideCustomerEmail
21. testIsCronFinishOrdersActive
22. testIsCronSecondChanceActive
23. testGetsCronSecondChanceTimeDiff
24. testGetsCronSecondChanceTimeDiffDefault
25. testIsCronOrderShipmentActive
26. testGetsCronSecureKey

## Acceptance Criteria

- [ ] All 26 tests pass
- [ ] Tests use proper interface mocking (ContextInterface, ModuleConfigurationDaoInterface)
- [ ] No direct dependency on OXID's Config class
- [ ] Test coverage maintained for all configuration methods
