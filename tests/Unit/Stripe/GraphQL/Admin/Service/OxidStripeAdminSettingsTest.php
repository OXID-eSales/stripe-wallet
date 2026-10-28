<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\GraphQL\Admin\Service;

use OxidEsales\Payments\Stripe\GraphQL\Admin\Service\OxidStripeAdminSettings;
use OxidEsales\Payments\Stripe\Service\ConfigurationValidator;
use OxidEsales\Payments\Stripe\Service\ModuleConfigurationServiceInterface;
use OxidEsales\Payments\Stripe\Tests\Unit\Stripe\GraphQL\Admin\Support\InMemoryModuleSettings;
use OxidEsales\Payments\Stripe\Tests\Unit\Stripe\GraphQL\Admin\Support\InMemoryWebhookState;
use PHPUnit\Framework\TestCase;

final class OxidStripeAdminSettingsTest extends TestCase
{
    private const TEST_TOKEN = 'sk_test_AAAAAAAAAAsecret1';
    private const LIVE_TOKEN = 'sk_live_BBBBBBBBBBsecret2';
    private const TEST_PLATFORM_KEY = 'sk_test_PLATFORMplatform3';
    private const LIVE_PLATFORM_KEY = 'sk_live_PLATFORMplatform4';

    private InMemoryModuleSettings $module;
    private InMemoryWebhookState $webhooks;

    protected function setUp(): void
    {
        $this->module = new InMemoryModuleSettings([
            'sStripeMode' => 'test',
            'sStripeCaptureMode' => 'manual',
            'blStripeRemoveByBillingCountry' => true,
            'blStripeRemoveByBasketCurrency' => false,
            'blStripeProvideCustomerEmailAddress' => true,
            'sStripeLogLevel' => 'debug',
            'blStripeLogWebhooks' => false,
            'sStripeTestPk' => 'pk_test_AAAAAAAAAAbbbb',
            'sStripeLivePk' => 'pk_live_BBBBBBBBBBbbbb',
            'sStripeTestToken' => self::TEST_TOKEN,
            'sStripeLiveToken' => '',
            'sStripeTestKey' => self::TEST_PLATFORM_KEY,
            'sStripeLiveKey' => '',
        ]);
        $this->webhooks = new InMemoryWebhookState(['test' => 'we_123'], ['test' => 'whsec_stored']);
    }

    public function testSnapshot_ReadsTheSettings(): void
    {
        $snapshot = $this->settings()->snapshot();

        self::assertSame('test', $snapshot->mode);
        self::assertSame('manual', $snapshot->captureMode);
        self::assertTrue($snapshot->removeByBillingCountry);
        self::assertFalse($snapshot->removeByBasketCurrency);
        self::assertTrue($snapshot->provideCustomerEmail);
        self::assertSame('debug', $snapshot->logLevel);
        self::assertFalse($snapshot->logWebhooks);
        self::assertSame('pk_test_AAAAAAAAAAbbbb', $snapshot->testPublishableKey);
        self::assertSame('pk_live_BBBBBBBBBBbbbb', $snapshot->livePublishableKey);
        self::assertSame('https://shop.example/webhook', $snapshot->webhookEndpointUrl);
    }

    public function testSnapshot_SecretsAreOnlyFlags(): void
    {
        $snapshot = $this->settings()->snapshot();

        self::assertTrue($snapshot->testTokenSet);
        self::assertFalse($snapshot->liveTokenSet);
        self::assertTrue($snapshot->testPlatformKeySet);
        self::assertFalse($snapshot->livePlatformKeySet);
        self::assertTrue($snapshot->webhookSecretSet);
        $everything = (string) json_encode(get_object_vars($snapshot));
        foreach ([self::TEST_TOKEN, self::TEST_PLATFORM_KEY, 'whsec_stored'] as $secret) {
            self::assertStringNotContainsString($secret, $everything);
        }
    }

    public function testSnapshot_ConnectedMeansTokenAndPublishableKeyOfTheMode(): void
    {
        $snapshot = $this->settings()->snapshot();

        self::assertTrue($snapshot->testConnected);
        self::assertFalse($snapshot->liveConnected, 'a live publishable key without a live token is not a connection');
    }

    public function testSnapshot_WebhookSecretSetIsAboutTheModeInUse(): void
    {
        $this->module->values['sStripeMode'] = 'live';

        self::assertFalse($this->settings()->snapshot()->webhookSecretSet);
    }

    public function testSnapshot_KeyValidationErrorIsNullForAMatchingPairOfTheMode(): void
    {
        self::assertNull($this->settings()->snapshot()->keyValidationError);
    }

    public function testSnapshot_AMismatchIsReportedWithoutTheKeys(): void
    {
        $this->module->values['sStripeTestToken'] = 'sk_test_ZZZZZZZZZZsecret9';

        $error = $this->settings()->snapshot()->keyValidationError;

        self::assertNotNull($error);
        self::assertStringContainsString('different Stripe accounts', $error);
        self::assertStringNotContainsString('ZZZZZZZZZZ', $error, 'not even a part of a secret key');
    }

    public function testSnapshot_AnUnsetLogLevel_FallsBackToTheLegacyToggle(): void
    {
        $this->module->values['sStripeLogLevel'] = '';
        $this->module->values['blStripeLogTransactionInfo'] = false;
        self::assertSame('off', $this->settings()->snapshot()->logLevel);

        unset($this->module->values['blStripeLogTransactionInfo']);
        self::assertSame('normal', $this->settings()->snapshot()->logLevel);
    }

    public function testSnapshot_AnUnknownModeOrCaptureMode_IsTheDefault(): void
    {
        $this->module->values['sStripeMode'] = 'whatever';
        $this->module->values['sStripeCaptureMode'] = '';

        $snapshot = $this->settings()->snapshot();

        self::assertSame('test', $snapshot->mode);
        self::assertSame('automatic', $snapshot->captureMode);
    }

    private function settings(): OxidStripeAdminSettings
    {
        $config = $this->createStub(ModuleConfigurationServiceInterface::class);
        $config->method('getWebhookUrl')->willReturn('https://shop.example/webhook');

        return new OxidStripeAdminSettings($this->module, $this->webhooks, new ConfigurationValidator($config), $config);
    }
}
