<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Service;

use OxidEsales\EshopCommunity\Internal\Framework\Module\Configuration\Dao\ModuleConfigurationDaoInterface;
use OxidEsales\EshopCommunity\Internal\Transition\Utility\ContextInterface;
use OxidEsales\Payments\Stripe\Service\ModuleConfigurationService;
use PHPUnit\Framework\TestCase;

/**
 * S2: isConfigured() must check webhook secret.
 *
 * A shop without a webhook secret cannot verify Stripe webhook signatures,
 * so isConfigured() must return false — preventing Stripe from being offered
 * as a payment method.
 *
 * @covers \OxidEsales\Payments\Stripe\Service\ModuleConfigurationService
 */
class ModuleConfigurationServiceIsConfiguredTest extends TestCase
{
    /**
     * S2: isConfigured() must return false when webhook secret is empty.
     */
    public function testIsConfiguredReturnsFalseWhenWebhookSecretEmpty(): void
    {
        $service = $this->createServiceWithSettings([
            'sStripeMode' => 'test',
            'sStripeTestToken' => 'sk_test_123',
            'sStripeWebhookEndpointSecret' => '',
        ]);

        $this->assertFalse($service->isConfigured());
    }

    public function testIsConfiguredReturnsTrueWhenAllKeysPresent(): void
    {
        $service = $this->createServiceWithSettings([
            'sStripeMode' => 'test',
            'sStripeTestToken' => 'sk_test_123',
            'sStripeWebhookEndpointSecret' => 'whsec_abc',
        ]);

        $this->assertTrue($service->isConfigured());
    }

    public function testIsConfiguredReturnsFalseWhenTokenEmpty(): void
    {
        $service = $this->createServiceWithSettings([
            'sStripeMode' => 'test',
            'sStripeTestToken' => '',
            'sStripeWebhookEndpointSecret' => 'whsec_abc',
        ]);

        $this->assertFalse($service->isConfigured());
    }

    public function testIsConfiguredReturnsFalseWhenBothEmpty(): void
    {
        $service = $this->createServiceWithSettings([
            'sStripeMode' => 'test',
            'sStripeTestToken' => '',
            'sStripeWebhookEndpointSecret' => '',
        ]);

        $this->assertFalse($service->isConfigured());
    }

    /**
     * Sprint 109/Sprint 111 fix: per-mode secret is stored in oxconfig (not module settings)
     * so it does NOT surface in the module_config form as an editable field.
     * The per-mode oxconfig value is preferred; legacy single-valued module setting is the fallback.
     */
    public function testGetWebhookSecretPrefersPerModeOxConfigValueOverLegacy(): void
    {
        $service = $this->createServiceWithSettingsAndOxConfig(
            ['sStripeMode' => 'test', 'sStripeWebhookEndpointSecret' => 'whsec_legacy'],
            ['sStripeWebhookEndpointSecretTest' => 'whsec_mode_specific']
        );

        $this->assertSame('whsec_mode_specific', $service->getWebhookSecret());
    }

    public function testGetWebhookSecretFallsBackToLegacyWhenOxConfigEmpty(): void
    {
        $service = $this->createServiceWithSettingsAndOxConfig(
            ['sStripeMode' => 'test', 'sStripeWebhookEndpointSecret' => 'whsec_legacy_pasted'],
            [] // no per-mode oxconfig entry
        );

        $this->assertSame('whsec_legacy_pasted', $service->getWebhookSecret());
    }

    public function testGetWebhookSecretPicksLiveOxConfigValueInLiveMode(): void
    {
        $service = $this->createServiceWithSettingsAndOxConfig(
            ['sStripeMode' => 'live', 'sStripeWebhookEndpointSecret' => 'whsec_legacy'],
            [
                'sStripeWebhookEndpointSecretLive' => 'whsec_live_specific',
                'sStripeWebhookEndpointSecretTest' => 'whsec_test_specific',
            ]
        );

        $this->assertSame('whsec_live_specific', $service->getWebhookSecret());
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function createServiceWithSettings(array $settings): ModuleConfigurationService
    {
        return $this->createServiceWithSettingsAndOxConfig($settings, []);
    }

    /**
     * @param array<string, mixed>  $settings  Module settings (read by get())
     * @param array<string, string> $oxConfig  Oxconfig values (read by readOxConfigVar())
     */
    private function createServiceWithSettingsAndOxConfig(
        array $settings,
        array $oxConfig
    ): ModuleConfigurationService {
        return new class ($settings, $oxConfig) extends ModuleConfigurationService {
            /**
             * @param array<string, mixed>  $testSettings
             * @param array<string, string> $testOxConfig
             */
            public function __construct(
                private readonly array $testSettings,
                private readonly array $testOxConfig,
            ) {
                // Skip parent constructor — avoids OXID framework dependencies
            }

            public function get(string $name): mixed
            {
                return $this->testSettings[$name] ?? '';
            }

            protected function readOxConfigVar(string $key): string
            {
                return $this->testOxConfig[$key] ?? '';
            }
        };
    }
}
