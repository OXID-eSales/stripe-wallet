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
     * @param array<string, mixed> $settings
     */
    private function createServiceWithSettings(array $settings): ModuleConfigurationService
    {
        return new class ($settings) extends ModuleConfigurationService {
            /** @param array<string, mixed> $testSettings */
            public function __construct(private readonly array $testSettings)
            {
                // Skip parent constructor — avoids OXID framework dependencies
            }

            public function get(string $name): mixed
            {
                return $this->testSettings[$name] ?? '';
            }
        };
    }
}
