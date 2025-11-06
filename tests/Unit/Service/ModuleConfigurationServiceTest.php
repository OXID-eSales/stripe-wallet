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
                    'sStripeMode' => 'test',
                    'sStripeTestKey' => $testSecretKey,
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
        // Given: Live mode enabled, live key configured
        $liveSecretKey = 'sk_live_51XYZ789';

        $this->configMock
            ->expects($this->exactly(2))
            ->method('getConfigParam')
            ->willReturnCallback(function ($param) use ($liveSecretKey) {
                return match ($param) {
                    'sStripeMode' => 'live',
                    'sStripeLiveKey' => $liveSecretKey,
                    default => null
                };
            });

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

        $this->configMock
            ->expects($this->once())
            ->method('getConfigParam')
            ->with('sStripeWebhookEndpointSecret')
            ->willReturn($webhookSecret);

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

        $this->configMock
            ->expects($this->once())
            ->method('getConfigParam')
            ->with('sStripeWebhookEndpoint')
            ->willReturn($webhookEndpoint);

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
        $this->configMock
            ->expects($this->once())
            ->method('getConfigParam')
            ->with('sStripeMode')
            ->willReturn('test');

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
        $this->configMock
            ->expects($this->once())
            ->method('getConfigParam')
            ->with('sStripeMode')
            ->willReturn('live');

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
        $this->configMock
            ->expects($this->once())
            ->method('getConfigParam')
            ->with('blStripeLogTransactionInfo')
            ->willReturn(true);

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
        $this->configMock
            ->expects($this->once())
            ->method('getConfigParam')
            ->with('sStripeStatusPending')
            ->willReturn('NOT_FINISHED');

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
        $this->configMock
            ->expects($this->once())
            ->method('getConfigParam')
            ->with('sStripeStatusProcessing')
            ->willReturn('OK');

        // When: getStatusProcessing() called
        $result = $this->service->getStatusProcessing();

        // Then: Returns 'OK'
        $this->assertEquals('OK', $result);
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
                    'sStripeMode' => 'test',
                    'sStripeTestPk' => $testPublishableKey,
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
        // Given: Live mode enabled, live publishable key configured
        $livePublishableKey = 'pk_live_XYZ789';

        $this->configMock
            ->expects($this->exactly(2))
            ->method('getConfigParam')
            ->willReturnCallback(function ($param) use ($livePublishableKey) {
                return match ($param) {
                    'sStripeMode' => 'live',
                    'sStripeLivePk' => $livePublishableKey,
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
            ->expects($this->exactly(3))
            ->method('getConfigParam')
            ->willReturnCallback(function ($param) {
                return match ($param) {
                    'sStripeMode' => 'test',
                    'sStripeTestKey' => 'sk_test_ABC123',
                    'sStripeWebhookEndpointSecret' => 'whsec_ABC123',
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
                    'sStripeMode' => 'test',
                    'sStripeTestKey' => '',
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
            ->expects($this->exactly(3))
            ->method('getConfigParam')
            ->willReturnCallback(function ($param) {
                return match ($param) {
                    'sStripeMode' => 'test',
                    'sStripeTestKey' => 'sk_test_ABC123',
                    'sStripeWebhookEndpointSecret' => '',
                    default => null
                };
            });

        // When: isConfigured() called
        $result = $this->service->isConfigured();

        // Then: Returns false
        $this->assertFalse($result);
    }

    /**
     * Test 17: Get token in test mode
     */
    public function testGetsTestToken(): void
    {
        // Given: Test mode enabled, test token configured
        $testToken = 'token_test_ABC123';

        $this->configMock
            ->expects($this->exactly(2))
            ->method('getConfigParam')
            ->willReturnCallback(function ($param) use ($testToken) {
                return match ($param) {
                    'sStripeMode' => 'test',
                    'sStripeTestToken' => $testToken,
                    default => null
                };
            });

        // When: getToken() called
        $result = $this->service->getToken();

        // Then: Returns test token
        $this->assertEquals($testToken, $result);
    }

    /**
     * Test 18: Check if payment method should be removed by billing country
     */
    public function testIsRemoveByBillingCountry(): void
    {
        // Given: Setting enabled
        $this->configMock
            ->expects($this->once())
            ->method('getConfigParam')
            ->with('blStripeRemoveByBillingCountry')
            ->willReturn(true);

        // When: isRemoveByBillingCountry() called
        $result = $this->service->isRemoveByBillingCountry();

        // Then: Returns true
        $this->assertTrue($result);
    }

    /**
     * Test 19: Check if payment method should be removed by basket currency
     */
    public function testIsRemoveByBasketCurrency(): void
    {
        // Given: Setting disabled
        $this->configMock
            ->expects($this->once())
            ->method('getConfigParam')
            ->with('blStripeRemoveByBasketCurrency')
            ->willReturn(false);

        // When: isRemoveByBasketCurrency() called
        $result = $this->service->isRemoveByBasketCurrency();

        // Then: Returns false
        $this->assertFalse($result);
    }

    /**
     * Test 20: Check if customer email should be provided to Stripe
     */
    public function testShouldProvideCustomerEmail(): void
    {
        // Given: Setting enabled
        $this->configMock
            ->expects($this->once())
            ->method('getConfigParam')
            ->with('blStripeProvideCustomerEmailAddress')
            ->willReturn(true);

        // When: shouldProvideCustomerEmail() called
        $result = $this->service->shouldProvideCustomerEmail();

        // Then: Returns true
        $this->assertTrue($result);
    }

    /**
     * Test 21: Check if cron finish orders is active
     */
    public function testIsCronFinishOrdersActive(): void
    {
        // Given: Cron job active
        $this->configMock
            ->expects($this->once())
            ->method('getConfigParam')
            ->with('sStripeCronFinishOrdersActive')
            ->willReturn(true);

        // When: isCronFinishOrdersActive() called
        $result = $this->service->isCronFinishOrdersActive();

        // Then: Returns true
        $this->assertTrue($result);
    }

    /**
     * Test 22: Check if cron second chance is active
     */
    public function testIsCronSecondChanceActive(): void
    {
        // Given: Cron job active
        $this->configMock
            ->expects($this->once())
            ->method('getConfigParam')
            ->with('sStripeCronSecondChanceActive')
            ->willReturn(true);

        // When: isCronSecondChanceActive() called
        $result = $this->service->isCronSecondChanceActive();

        // Then: Returns true
        $this->assertTrue($result);
    }

    /**
     * Test 23: Get cron second chance time diff
     */
    public function testGetsCronSecondChanceTimeDiff(): void
    {
        // Given: Time diff = 3 days
        $this->configMock
            ->expects($this->once())
            ->method('getConfigParam')
            ->with('iStripeCronSecondChanceTimeDiff')
            ->willReturn('3');

        // When: getCronSecondChanceTimeDiff() called
        $result = $this->service->getCronSecondChanceTimeDiff();

        // Then: Returns 3
        $this->assertEquals(3, $result);
    }

    /**
     * Test 24: Get cron second chance time diff with default
     */
    public function testGetsCronSecondChanceTimeDiffDefault(): void
    {
        // Given: No value configured
        $this->configMock
            ->expects($this->once())
            ->method('getConfigParam')
            ->with('iStripeCronSecondChanceTimeDiff')
            ->willReturn(null);

        // When: getCronSecondChanceTimeDiff() called
        $result = $this->service->getCronSecondChanceTimeDiff();

        // Then: Returns default value of 1
        $this->assertEquals(1, $result);
    }

    /**
     * Test 25: Check if cron order shipment is active
     */
    public function testIsCronOrderShipmentActive(): void
    {
        // Given: Cron job active
        $this->configMock
            ->expects($this->once())
            ->method('getConfigParam')
            ->with('sStripeCronOrderShipmentActive')
            ->willReturn(true);

        // When: isCronOrderShipmentActive() called
        $result = $this->service->isCronOrderShipmentActive();

        // Then: Returns true
        $this->assertTrue($result);
    }

    /**
     * Test 26: Get cron secure key
     */
    public function testGetsCronSecureKey(): void
    {
        // Given: Secure key configured
        $secureKey = 'my_secure_key_123';

        $this->configMock
            ->expects($this->once())
            ->method('getConfigParam')
            ->with('sStripeCronSecureKey')
            ->willReturn($secureKey);

        // When: getCronSecureKey() called
        $result = $this->service->getCronSecureKey();

        // Then: Returns secure key
        $this->assertEquals($secureKey, $result);
    }
}
