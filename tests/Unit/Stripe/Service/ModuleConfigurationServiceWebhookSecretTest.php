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
 * Sprint 133 · Story 13 (F13).
 *
 * getWebhookSecret() preferred the per-mode secret and fell back to the legacy
 * mode-agnostic module setting. The per-mode key is chosen by isTestMode(); the
 * legacy fallback was not. A shop that pasted a TEST signing secret into the
 * legacy setting before auto-registration existed and then switched to live
 * verified live webhooks with the test secret: every webhook 400s, Stripe
 * retries and gives up, and because verification is (correctly) fail-closed the
 * symptom is not an error in the shop but orders that silently never become
 * paid.
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

    public function testFallsBackToTheLegacySecretWhenNoModeSpecificSecretWasEverConfigured(): void
    {
        // Existing installs that pasted a secret manually keep working.
        $service = $this->service(
            [],
            ['sStripeMode' => 'live', 'sStripeWebhookEndpointSecret' => 'whsec_legacy']
        );

        $this->assertSame('whsec_legacy', $service->getWebhookSecret());
    }

    public function testRefusesTheLegacySecretWhenTheOtherModeHasItsOwnSecret(): void
    {
        // The shop has auto-registered for test, so the mode-agnostic legacy
        // value belongs to that other era: using it for live would verify live
        // webhooks with a test secret.
        $service = $this->service(
            ['sStripeWebhookEndpointSecretTest' => 'whsec_test'],
            ['sStripeMode' => 'live', 'sStripeWebhookEndpointSecret' => 'whsec_legacy']
        );

        $this->assertSame('', $service->getWebhookSecret());
    }

    public function testLogsAnErrorNamingTheMissingSettingWhenItRefuses(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with(
                $this->stringContains('webhook'),
                $this->callback(static fn (array $c): bool =>
                    ($c['mode'] ?? null) === 'live'
                    && ($c['expected_setting'] ?? null) === 'sStripeWebhookEndpointSecretLive')
            );

        $service = $this->service(
            ['sStripeWebhookEndpointSecretTest' => 'whsec_test'],
            ['sStripeMode' => 'live', 'sStripeWebhookEndpointSecret' => 'whsec_legacy'],
            $logger
        );

        $service->getWebhookSecret();
    }

    public function testTestModeIsSymmetricallyProtected(): void
    {
        $service = $this->service(
            ['sStripeWebhookEndpointSecretLive' => 'whsec_live'],
            ['sStripeMode' => 'test', 'sStripeWebhookEndpointSecret' => 'whsec_legacy']
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
