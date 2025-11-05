<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Unit\Service;

use OxidEsales\Eshop\Core\Config;
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
    private Config|MockObject $configMock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configMock = $this->createMock(Config::class);
        $this->service = new ModuleConfigurationService($this->configMock);
    }

    /**
     * Test 1: Get Stripe secret key in test mode
     */
    public function testGetsTestSecretKey(): void
    {
        // Given: Test mode enabled, test key configured
        $testSecretKey = 'sk_test_51ABC123';

        $this->configMock
            ->expects($this->exactly(2))
            ->method('getConfigParam')
            ->willReturnCallback(function ($param) use ($testSecretKey) {
                return match ($param) {
                    'osc_stripe_test_mode' => true,
                    'osc_stripe_test_secret_key' => $testSecretKey,
                    default => null
                };
            });

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
        // Given: Test mode disabled, live key configured
        $liveSecretKey = 'sk_live_51XYZ789';

        $this->configMock
            ->expects($this->exactly(2))
            ->method('getConfigParam')
            ->willReturnCallback(function ($param) use ($liveSecretKey) {
                return match ($param) {
                    'osc_stripe_test_mode' => false,
                    'osc_stripe_live_secret_key' => $liveSecretKey,
                    default => null
                };
            });

        // When: getSecretKey() called
        $result = $this->service->getSecretKey();

        // Then: Returns live secret key
        $this->assertEquals($liveSecretKey, $result);
    }

    /**
     * Test 3: Get webhook secret in test mode
     */
    public function testGetsTestWebhookSecret(): void
    {
        // Given: Test mode enabled, webhook secret configured
        $testWebhookSecret = 'whsec_test_ABC123';

        $this->configMock
            ->expects($this->exactly(2))
            ->method('getConfigParam')
            ->willReturnCallback(function ($param) use ($testWebhookSecret) {
                return match ($param) {
                    'osc_stripe_test_mode' => true,
                    'osc_stripe_test_webhook_secret' => $testWebhookSecret,
                    default => null
                };
            });

        // When: getWebhookSecret() called
        $result = $this->service->getWebhookSecret();

        // Then: Returns test webhook secret
        $this->assertEquals($testWebhookSecret, $result);
    }

    /**
     * Test 4: Get webhook secret in live mode
     */
    public function testGetsLiveWebhookSecret(): void
    {
        // Given: Test mode disabled, live webhook secret configured
        $liveWebhookSecret = 'whsec_live_XYZ789';

        $this->configMock
            ->expects($this->exactly(2))
            ->method('getConfigParam')
            ->willReturnCallback(function ($param) use ($liveWebhookSecret) {
                return match ($param) {
                    'osc_stripe_test_mode' => false,
                    'osc_stripe_live_webhook_secret' => $liveWebhookSecret,
                    default => null
                };
            });

        // When: getWebhookSecret() called
        $result = $this->service->getWebhookSecret();

        // Then: Returns live webhook secret
        $this->assertEquals($liveWebhookSecret, $result);
    }

    /**
     * Test 5: Is test mode enabled
     */
    public function testIsTestModeEnabled(): void
    {
        // Given: Test mode setting = true
        $this->configMock
            ->expects($this->once())
            ->method('getConfigParam')
            ->with('osc_stripe_test_mode')
            ->willReturn(true);

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
        // Given: Test mode setting = false
        $this->configMock
            ->expects($this->once())
            ->method('getConfigParam')
            ->with('osc_stripe_test_mode')
            ->willReturn(false);

        // When: isTestMode() called
        $result = $this->service->isTestMode();

        // Then: Returns false
        $this->assertFalse($result);
    }

    /**
     * Test 7: Get payment methods
     */
    public function testGetsPaymentMethods(): void
    {
        // Given: Payment methods ['card', 'sepa_debit']
        $paymentMethods = ['card', 'sepa_debit'];

        $this->configMock
            ->expects($this->once())
            ->method('getConfigParam')
            ->with('osc_stripe_payment_methods')
            ->willReturn($paymentMethods);

        // When: getPaymentMethods() called
        $result = $this->service->getPaymentMethods();

        // Then: Returns array of enabled methods
        $this->assertEquals($paymentMethods, $result);
        $this->assertIsArray($result);
        $this->assertContains('card', $result);
        $this->assertContains('sepa_debit', $result);
    }

    /**
     * Test 8: Get capture method - automatic
     */
    public function testGetsCaptureMethodAutomatic(): void
    {
        // Given: Capture method = 'automatic'
        $this->configMock
            ->expects($this->once())
            ->method('getConfigParam')
            ->with('osc_stripe_capture_method')
            ->willReturn('automatic');

        // When: getCaptureMethod() called
        $result = $this->service->getCaptureMethod();

        // Then: Returns 'automatic'
        $this->assertEquals('automatic', $result);
    }

    /**
     * Test 9: Get capture method - manual
     */
    public function testGetsCaptureMethodManual(): void
    {
        // Given: Capture method = 'manual'
        $this->configMock
            ->expects($this->once())
            ->method('getConfigParam')
            ->with('osc_stripe_capture_method')
            ->willReturn('manual');

        // When: getCaptureMethod() called
        $result = $this->service->getCaptureMethod();

        // Then: Returns 'manual'
        $this->assertEquals('manual', $result);
    }

    /**
     * Test 10: Get webhook URL
     */
    public function testGetsWebhookUrl(): void
    {
        // Given: Shop URL configured
        $shopUrl = 'https://example.com/';

        $this->configMock
            ->expects($this->once())
            ->method('getShopUrl')
            ->willReturn($shopUrl);

        // When: getWebhookUrl() called
        $result = $this->service->getWebhookUrl();

        // Then: Returns properly formatted webhook URL
        $this->assertEquals(
            'https://example.com/index.php?cl=osc_stripe_webhook',
            $result
        );
    }

    /**
     * Test 11: Get webhook URL removes trailing slash
     */
    public function testGetsWebhookUrlRemovesTrailingSlash(): void
    {
        // Given: Shop URL with trailing slash
        $shopUrl = 'https://example.com/shop/';

        $this->configMock
            ->expects($this->once())
            ->method('getShopUrl')
            ->willReturn($shopUrl);

        // When: getWebhookUrl() called
        $result = $this->service->getWebhookUrl();

        // Then: Trailing slash is removed
        $this->assertEquals(
            'https://example.com/shop/index.php?cl=osc_stripe_webhook',
            $result
        );
        // Check no double slashes after protocol
        $withoutProtocol = str_replace('https://', '', $result);
        $this->assertStringNotContainsString('//', $withoutProtocol, 'URL should not contain double slashes');
    }

    /**
     * Test 12: Get publishable key in test mode
     */
    public function testGetsTestPublishableKey(): void
    {
        // Given: Test mode enabled, test publishable key configured
        $testPublishableKey = 'pk_test_ABC123';

        $this->configMock
            ->expects($this->exactly(2))
            ->method('getConfigParam')
            ->willReturnCallback(function ($param) use ($testPublishableKey) {
                return match ($param) {
                    'osc_stripe_test_mode' => true,
                    'osc_stripe_test_publishable_key' => $testPublishableKey,
                    default => null
                };
            });

        // When: getPublishableKey() called
        $result = $this->service->getPublishableKey();

        // Then: Returns test publishable key
        $this->assertEquals($testPublishableKey, $result);
    }

    /**
     * Test 13: Get publishable key in live mode
     */
    public function testGetsLivePublishableKey(): void
    {
        // Given: Test mode disabled, live publishable key configured
        $livePublishableKey = 'pk_live_XYZ789';

        $this->configMock
            ->expects($this->exactly(2))
            ->method('getConfigParam')
            ->willReturnCallback(function ($param) use ($livePublishableKey) {
                return match ($param) {
                    'osc_stripe_test_mode' => false,
                    'osc_stripe_live_publishable_key' => $livePublishableKey,
                    default => null
                };
            });

        // When: getPublishableKey() called
        $result = $this->service->getPublishableKey();

        // Then: Returns live publishable key
        $this->assertEquals($livePublishableKey, $result);
    }

    /**
     * Test 14: Is configured returns true when keys are set
     */
    public function testIsConfiguredReturnsTrueWhenKeysSet(): void
    {
        // Given: Secret key and webhook secret configured
        $this->configMock
            ->expects($this->exactly(4))
            ->method('getConfigParam')
            ->willReturnCallback(function ($param) {
                return match ($param) {
                    'osc_stripe_test_mode' => true,
                    'osc_stripe_test_secret_key' => 'sk_test_ABC123',
                    'osc_stripe_test_webhook_secret' => 'whsec_test_ABC123',
                    default => null
                };
            });

        // When: isConfigured() called
        $result = $this->service->isConfigured();

        // Then: Returns true
        $this->assertTrue($result);
    }

    /**
     * Test 15: Is configured returns false when secret key is missing
     */
    public function testIsConfiguredReturnsFalseWhenSecretKeyMissing(): void
    {
        // Given: Secret key not configured, webhook secret is set
        $this->configMock
            ->expects($this->exactly(2))
            ->method('getConfigParam')
            ->willReturnCallback(function ($param) {
                return match ($param) {
                    'osc_stripe_test_mode' => true,
                    'osc_stripe_test_secret_key' => '',
                    default => null
                };
            });

        // When: isConfigured() called
        $result = $this->service->isConfigured();

        // Then: Returns false
        $this->assertFalse($result);
    }

    /**
     * Test 16: Is configured returns false when webhook secret is missing
     */
    public function testIsConfiguredReturnsFalseWhenWebhookSecretMissing(): void
    {
        // Given: Secret key configured, webhook secret not set
        $this->configMock
            ->expects($this->exactly(4))
            ->method('getConfigParam')
            ->willReturnCallback(function ($param) {
                return match ($param) {
                    'osc_stripe_test_mode' => true,
                    'osc_stripe_test_secret_key' => 'sk_test_ABC123',
                    'osc_stripe_test_webhook_secret' => '',
                    default => null
                };
            });

        // When: isConfigured() called
        $result = $this->service->isConfigured();

        // Then: Returns false
        $this->assertFalse($result);
    }

    /**
     * Test 17: Empty payment methods returns empty array
     */
    public function testEmptyPaymentMethodsReturnsEmptyArray(): void
    {
        // Given: Payment methods not configured
        $this->configMock
            ->expects($this->once())
            ->method('getConfigParam')
            ->with('osc_stripe_payment_methods')
            ->willReturn(null);

        // When: getPaymentMethods() called
        $result = $this->service->getPaymentMethods();

        // Then: Returns empty array
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }
}
