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
use OxidEsales\Payments\Stripe\Service\ModuleDescriptionProvider;
use OxidEsales\Payments\Stripe\Service\StripeUrlBuilder;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * VR-26 S1: the webhook signing secret is the per-mode, per-endpoint value only. The old mode-agnostic module setting
 * (`sStripeWebhookEndpointSecret`) is not read any more - activation moves a still-set value into the per-mode key once.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(ModuleConfigurationService::class)]
final class ModuleConfigurationServiceWebhookSecretTest extends TestCase
{
    /**
     * @param array<string, string> $oxConfigVars
     * @param array<string, mixed> $moduleSettings
     */
    private function service(
        array $oxConfigVars,
        array $moduleSettings,
        ?LoggerInterface $logger = null
    ): TestableModuleConfigurationService {
        return new TestableModuleConfigurationService(
            $this->createMock(ContextInterface::class),
            $this->createMock(ModuleConfigurationDaoInterface::class),
            $this->createMock(StripeUrlBuilder::class),
            $this->createMock(ModuleDescriptionProvider::class),
            $logger ?? $this->createMock(LoggerInterface::class),
            $oxConfigVars,
            $moduleSettings
        );
    }

    public function testUsesTheModeSpecificSecretWhenPresent(): void
    {
        $service = $this->service(
            ['sStripeWebhookEndpointSecretLive' => 'whsec_live'],
            ['sStripeMode' => 'live', 'sStripeWebhookEndpointSecret' => 'whsec_legacy']
        );

        $this->assertSame('whsec_live', $service->getWebhookSecret());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function legacySecretIsNotRead(): void
    {
        $service = $this->service(
            [],
            ['sStripeMode' => 'live', 'sStripeWebhookEndpointSecret' => 'whsec_legacy']
        );

        $this->assertSame('', $service->getWebhookSecret());
    }

    public function testTheOtherModesSecretIsNeverUsed(): void
    {
        $service = $this->service(
            ['sStripeWebhookEndpointSecretTest' => 'whsec_test'],
            ['sStripeMode' => 'live']
        );

        $this->assertSame('', $service->getWebhookSecret());
    }

    public function testReturnsEmptyWhenNothingIsConfigured(): void
    {
        $service = $this->service([], ['sStripeMode' => 'test']);

        $this->assertSame('', $service->getWebhookSecret());
    }
}

/**
 * readOxConfigVar() is documented as "Overridable in test subclasses for unit
 * testing without touching Registry"; get() reads module settings.
 */
class TestableModuleConfigurationService extends ModuleConfigurationService
{
    /**
     * @param array<string, string> $oxConfigVars
     * @param array<string, mixed> $moduleSettings
     */
    public function __construct(
        ContextInterface $context,
        ModuleConfigurationDaoInterface $dao,
        StripeUrlBuilder $urlBuilder,
        ModuleDescriptionProvider $descriptionProvider,
        LoggerInterface $logger,
        private readonly array $oxConfigVars,
        private readonly array $moduleSettings
    ) {
        parent::__construct($context, $dao, $urlBuilder, $descriptionProvider, $logger);
    }

    public function get(string $name): mixed
    {
        return $this->moduleSettings[$name] ?? '';
    }

    protected function readOxConfigVar(string $key): string
    {
        return $this->oxConfigVars[$key] ?? '';
    }
}
