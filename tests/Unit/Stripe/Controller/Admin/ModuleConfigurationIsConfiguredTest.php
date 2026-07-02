<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Controller\Admin;

use OxidEsales\Payments\Stripe\Controller\Admin\ModuleConfiguration;
use OxidEsales\Payments\Stripe\Service\ModuleConfigurationServiceInterface;
use OxidEsales\Payments\Stripe\Service\WebhookEndpointRegistrarInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * D9: ModuleConfiguration::stripeHasApiKeys() must delegate to the service's
 * isConfigured() — the single canonical definition (token + webhook secret).
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\OxidEsales\Payments\Stripe\Controller\Admin\ModuleConfiguration::class)]
final class ModuleConfigurationIsConfiguredTest extends TestCase
{
    /**
     * stripeHasApiKeys() returns true when the service says isConfigured().
     */
    public function testStripeHasApiKeysReturnsTrueWhenServiceIsConfigured(): void
    {
        $service = $this->createMock(ModuleConfigurationServiceInterface::class);
        $service->method('isConfigured')->willReturn(true);

        $controller = $this->makeController($service);

        $this->assertTrue($controller->stripeHasApiKeys());
    }

    /**
     * stripeHasApiKeys() returns false when the service says NOT isConfigured().
     */
    public function testStripeHasApiKeysReturnsFalseWhenServiceNotConfigured(): void
    {
        $service = $this->createMock(ModuleConfigurationServiceInterface::class);
        $service->method('isConfigured')->willReturn(false);

        $controller = $this->makeController($service);

        $this->assertFalse($controller->stripeHasApiKeys());
    }

    /**
     * D9: getToken() must NOT be called directly by the controller to determine
     * configured state — it must delegate entirely to the service.
     */
    public function testStripeHasApiKeysDoesNotCallGetTokenDirectly(): void
    {
        $service = $this->createMock(ModuleConfigurationServiceInterface::class);
        $service->expects($this->never())->method('getToken');
        $service->method('isConfigured')->willReturn(true);

        $controller = $this->makeController($service);
        $controller->stripeHasApiKeys();
    }

    private function makeController(
        ModuleConfigurationServiceInterface $service,
    ): ModuleConfiguration {
        $registrar = $this->createMock(WebhookEndpointRegistrarInterface::class);

        return new class ($registrar, $service) extends ModuleConfiguration {
            public function __construct(
                WebhookEndpointRegistrarInterface $registrar,
                ModuleConfigurationServiceInterface $moduleConfig,
            ) {
                $this->initializeWebhookCollaborators($registrar, $moduleConfig, new NullLogger());
            }
        };
    }
}
