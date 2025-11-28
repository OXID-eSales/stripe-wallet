<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Unit\Service;

use OxidEsales\EshopCommunity\Internal\Framework\Module\Configuration\Dao\ModuleConfigurationDaoInterface;
use OxidEsales\EshopCommunity\Internal\Framework\Module\Configuration\DataObject\ModuleConfiguration;
use OxidEsales\EshopCommunity\Internal\Framework\Module\Setting\Setting as ModuleSetting;
use OxidEsales\EshopCommunity\Internal\Transition\Utility\ContextInterface;
use OxidSolutionCatalysts\Payments\Stripe\Module;
use OxidSolutionCatalysts\Payments\Stripe\Service\ModuleConfigurationService;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Unit tests for ModuleConfigurationService
 * Tests configuration retrieval and mode switching logic
 */
class ModuleConfigurationServiceTest extends TestCase
{
    private ModuleConfigurationService $service;
    private ContextInterface&MockObject $context;
    private ModuleConfigurationDaoInterface&MockObject $moduleConfigDao;
    private ModuleConfiguration&MockObject $moduleConfig;

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

        // Create service under test
        $this->service = new ModuleConfigurationService($this->context, $this->moduleConfigDao);
    }

    /**
     * Helper to create a ModuleSetting mock that returns a value
     */
    private function createSettingMock(mixed $value): ModuleSetting&MockObject
    {
        $setting = $this->createMock(ModuleSetting::class);
        $setting->method('getValue')->willReturn($value);
        return $setting;
    }

    /**
     * Configure moduleConfig to return settings via callback
     */
    private function configureSettings(array $settings): void
    {
        $this->moduleConfig
            ->method('getModuleSetting')
            ->willReturnCallback(function (string $name) use ($settings): ModuleSetting {
                $value = $settings[$name] ?? '';
                return $this->createSettingMock($value);
            });
    }

    /**
     * Test 1: Get Stripe secret key in test mode
     */
    public function testGetsTestSecretKey(): void
    {
        // Given: Test mode enabled, test key configured
        $testSecretKey = 'sk_test_51ABC123';

        $this->configureSettings([
            'sStripeMode' => 'test',
            'sStripeTestToken' => $testSecretKey,
        ]);

        // When: getSecretKey() called
        $result = $this->service->getSecretKey();

        // Then: Returns test secret key
        $this->assertEquals($testSecretKey, $result);
    }

    /**
     * Test 2: Get Stripe secret key in live mode
     */
    public function testGetsLiveSecretKey(): void
    {
        // Given: Live mode enabled, live key configured
        $liveSecretKey = 'sk_live_51XYZ789';

        $this->configureSettings([
            'sStripeMode' => 'live',
            'sStripeLiveToken' => $liveSecretKey,
        ]);

        // When: getSecretKey() called
        $result = $this->service->getSecretKey();

        // Then: Returns live secret key
        $this->assertEquals($liveSecretKey, $result);
    }

    /**
     * Test 3: Get webhook secret
     */
    public function testGetsWebhookSecret(): void
    {
        // Given: Webhook secret configured
        $webhookSecret = 'whsec_ABC123';

        $this->configureSettings([
            'sStripeWebhookEndpointSecret' => $webhookSecret,
        ]);

        // When: getWebhookSecret() called
        $result = $this->service->getWebhookSecret();

        // Then: Returns webhook secret
        $this->assertEquals($webhookSecret, $result);
    }

    /**
     * Test 4: Get webhook endpoint
     */
    public function testGetsWebhookEndpoint(): void
    {
        // Given: Webhook endpoint configured
        $webhookEndpoint = 'https://example.com/webhook';

        $this->configureSettings([
            'sStripeWebhookEndpoint' => $webhookEndpoint,
        ]);

        // When: getWebhookEndpoint() called
        $result = $this->service->getWebhookEndpoint();

        // Then: Returns webhook endpoint
        $this->assertEquals($webhookEndpoint, $result);
    }

    /**
     * Test 5: Is test mode enabled
     */
    public function testIsTestModeEnabled(): void
    {
        // Given: Mode setting = 'test'
        $this->configureSettings([
            'sStripeMode' => 'test',
        ]);

        // When: isTestMode() called
        $result = $this->service->isTestMode();

        // Then: Returns true
        $this->assertTrue($result);
    }

    /**
     * Test 6: Is test mode disabled
     */
    public function testIsTestModeDisabled(): void
    {
        // Given: Mode setting = 'live'
        $this->configureSettings([
            'sStripeMode' => 'live',
        ]);

        // When: isTestMode() called
        $result = $this->service->isTestMode();

        // Then: Returns false
        $this->assertFalse($result);
    }

    /**
     * Test 7: Check if transaction logging is enabled
     */
    public function testIsTransactionLoggingEnabled(): void
    {
        // Given: Transaction logging enabled
        $this->configureSettings([
            'blStripeLogTransactionInfo' => true,
        ]);

        // When: isTransactionLoggingEnabled() called
        $result = $this->service->isTransactionLoggingEnabled();

        // Then: Returns true
        $this->assertTrue($result);
    }

    /**
     * Test 8: Get status mapping for pending orders
     */
    public function testGetsStatusPending(): void
    {
        // Given: Status pending = 'NOT_FINISHED'
        $this->configureSettings([
            'sStripeStatusPending' => 'NOT_FINISHED',
        ]);

        // When: getStatusPending() called
        $result = $this->service->getStatusPending();

        // Then: Returns 'NOT_FINISHED'
        $this->assertEquals('NOT_FINISHED', $result);
    }

    /**
     * Test 9: Get status mapping for processing orders
     */
    public function testGetsStatusProcessing(): void
    {
        // Given: Status processing = 'OK'
        $this->configureSettings([
            'sStripeStatusProcessing' => 'OK',
        ]);

        // When: getStatusProcessing() called
        $result = $this->service->getStatusProcessing();

        // Then: Returns 'OK'
        $this->assertEquals('OK', $result);
    }

    /**
     * Test 10: Get publishable key in test mode
     */
    public function testGetsTestPublishableKey(): void
    {
        // Given: Test mode enabled, test publishable key configured
        $testPublishableKey = 'pk_test_ABC123';

        $this->configureSettings([
            'sStripeMode' => 'test',
            'sStripeTestPk' => $testPublishableKey,
        ]);

        // When: getPublishableKey() called
        $result = $this->service->getPublishableKey();

        // Then: Returns test publishable key
        $this->assertEquals($testPublishableKey, $result);
    }

    /**
     * Test 11: Get publishable key in live mode
     */
    public function testGetsLivePublishableKey(): void
    {
        // Given: Live mode enabled, live publishable key configured
        $livePublishableKey = 'pk_live_XYZ789';

        $this->configureSettings([
            'sStripeMode' => 'live',
            'sStripeLivePk' => $livePublishableKey,
        ]);

        // When: getPublishableKey() called
        $result = $this->service->getPublishableKey();

        // Then: Returns live publishable key
        $this->assertEquals($livePublishableKey, $result);
    }

    /**
     * Test 12: Is configured returns true when keys are set
     */
    public function testIsConfiguredReturnsTrueWhenKeysSet(): void
    {
        // Given: Token configured (isConfigured only checks token now)
        $this->configureSettings([
            'sStripeMode' => 'test',
            'sStripeTestToken' => 'sk_test_ABC123',
        ]);

        // When: isConfigured() called
        $result = $this->service->isConfigured();

        // Then: Returns true
        $this->assertTrue($result);
    }

    /**
     * Test 13: Is configured returns false when token is missing
     */
    public function testIsConfiguredReturnsFalseWhenSecretKeyMissing(): void
    {
        // Given: Token not configured
        $this->configureSettings([
            'sStripeMode' => 'test',
            'sStripeTestToken' => '',
        ]);

        // When: isConfigured() called
        $result = $this->service->isConfigured();

        // Then: Returns false
        $this->assertFalse($result);
    }

    /**
     * Test 14: Get token in test mode
     */
    public function testGetsTestToken(): void
    {
        // Given: Test mode enabled, test token configured
        $testToken = 'token_test_ABC123';

        $this->configureSettings([
            'sStripeMode' => 'test',
            'sStripeTestToken' => $testToken,
        ]);

        // When: getToken() called
        $result = $this->service->getToken();

        // Then: Returns test token
        $this->assertEquals($testToken, $result);
    }

    /**
     * Test 15: Check if payment method should be removed by billing country
     */
    public function testIsRemoveByBillingCountry(): void
    {
        // Given: Setting enabled
        $this->configureSettings([
            'blStripeRemoveByBillingCountry' => true,
        ]);

        // When: isRemoveByBillingCountry() called
        $result = $this->service->isRemoveByBillingCountry();

        // Then: Returns true
        $this->assertTrue($result);
    }

    /**
     * Test 16: Check if payment method should be removed by basket currency
     */
    public function testIsRemoveByBasketCurrency(): void
    {
        // Given: Setting disabled
        $this->configureSettings([
            'blStripeRemoveByBasketCurrency' => false,
        ]);

        // When: isRemoveByBasketCurrency() called
        $result = $this->service->isRemoveByBasketCurrency();

        // Then: Returns false
        $this->assertFalse($result);
    }

    /**
     * Test 17: Check if customer email should be provided to Stripe
     */
    public function testShouldProvideCustomerEmail(): void
    {
        // Given: Setting enabled
        $this->configureSettings([
            'blStripeProvideCustomerEmailAddress' => true,
        ]);

        // When: shouldProvideCustomerEmail() called
        $result = $this->service->shouldProvideCustomerEmail();

        // Then: Returns true
        $this->assertTrue($result);
    }

    /**
     * Test 18: Check if cron finish orders is active
     */
    public function testIsCronFinishOrdersActive(): void
    {
        // Given: Cron job active
        $this->configureSettings([
            'sStripeCronFinishOrdersActive' => true,
        ]);

        // When: isCronFinishOrdersActive() called
        $result = $this->service->isCronFinishOrdersActive();

        // Then: Returns true
        $this->assertTrue($result);
    }

    /**
     * Test 19: Check if cron second chance is active
     */
    public function testIsCronSecondChanceActive(): void
    {
        // Given: Cron job active
        $this->configureSettings([
            'sStripeCronSecondChanceActive' => true,
        ]);

        // When: isCronSecondChanceActive() called
        $result = $this->service->isCronSecondChanceActive();

        // Then: Returns true
        $this->assertTrue($result);
    }

    /**
     * Test 20: Get cron second chance time diff
     */
    public function testGetsCronSecondChanceTimeDiff(): void
    {
        // Given: Time diff = 3 days
        $this->configureSettings([
            'iStripeCronSecondChanceTimeDiff' => '3',
        ]);

        // When: getCronSecondChanceTimeDiff() called
        $result = $this->service->getCronSecondChanceTimeDiff();

        // Then: Returns 3
        $this->assertEquals(3, $result);
    }

    /**
     * Test 21: Get cron second chance time diff with default
     */
    public function testGetsCronSecondChanceTimeDiffDefault(): void
    {
        // Given: No value configured
        $this->configureSettings([
            'iStripeCronSecondChanceTimeDiff' => null,
        ]);

        // When: getCronSecondChanceTimeDiff() called
        $result = $this->service->getCronSecondChanceTimeDiff();

        // Then: Returns default value of 1
        $this->assertEquals(1, $result);
    }

    /**
     * Test 22: Check if cron order shipment is active
     */
    public function testIsCronOrderShipmentActive(): void
    {
        // Given: Cron job active
        $this->configureSettings([
            'sStripeCronOrderShipmentActive' => true,
        ]);

        // When: isCronOrderShipmentActive() called
        $result = $this->service->isCronOrderShipmentActive();

        // Then: Returns true
        $this->assertTrue($result);
    }

    /**
     * Test 23: Get cron secure key
     */
    public function testGetsCronSecureKey(): void
    {
        // Given: Secure key configured
        $secureKey = 'my_secure_key_123';

        $this->configureSettings([
            'sStripeCronSecureKey' => $secureKey,
        ]);

        // When: getCronSecureKey() called
        $result = $this->service->getCronSecureKey();

        // Then: Returns secure key
        $this->assertEquals($secureKey, $result);
    }

    /**
     * Test 24: Get capture mode returns default 'automatic'
     */
    public function testGetsCaptureMode(): void
    {
        // Given: Capture mode configured
        $this->configureSettings([
            'sStripeCapture' => 'manual',
        ]);

        // When: getCaptureMode() called
        $result = $this->service->getCaptureMode();

        // Then: Returns 'manual'
        $this->assertEquals('manual', $result);
    }

    /**
     * Test 25: Get capture mode returns default when empty
     */
    public function testGetsCaptureModeDefault(): void
    {
        // Given: No capture mode configured
        $this->configureSettings([
            'sStripeCapture' => '',
        ]);

        // When: getCaptureMode() called
        $result = $this->service->getCaptureMode();

        // Then: Returns 'automatic' as default
        $this->assertEquals('automatic', $result);
    }

    /**
     * Test 26: Get method returns empty string on exception
     */
    public function testGetReturnsEmptyStringOnException(): void
    {
        // Given: Module setting throws exception
        $this->moduleConfig
            ->method('getModuleSetting')
            ->willThrowException(new \Exception('Setting not found'));

        // When: any config method is called
        $result = $this->service->getSecretKey();

        // Then: Returns empty string (not throwing)
        $this->assertEquals('', $result);
    }
}
