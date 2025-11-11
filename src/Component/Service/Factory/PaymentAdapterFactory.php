<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Component\Service\Factory;

use OxidSolutionCatalysts\Payments\Component\Adapter\PaymentAdapterInterface;
use OxidSolutionCatalysts\Payments\Stripe\Adapter\StripeAdapter;
use OxidSolutionCatalysts\Payments\Stripe\Adapter\StripeClientFactory;
use OxidSolutionCatalysts\Payments\Stripe\Service\ModuleConfigurationService;

/**
 * Factory for creating payment adapter instances.
 *
 * @since 1.0.0
 */
class PaymentAdapterFactory
{
    private string $secretKey;
    private bool $testMode;

    /**
     * @param string $secretKey Stripe secret key (test or live)
     * @param bool $testMode Whether to use test mode
     */
    public function __construct(
        private readonly ModuleConfigurationService $configurationService,
        private readonly StripeClientFactory $clientFactory
    ) {
        $this->secretKey = $this->configurationService->getSecretKey();
        $this->testMode = $this->configurationService->isTestMode();
    }

    /**
     * @throws \InvalidArgumentException
     */
    public function createAdapter(string $providerName): PaymentAdapterInterface
    {
        return match ($providerName) {
            'stripe' => $this->createStripeAdapter(),
            default => throw new \InvalidArgumentException(
                "Unsupported payment provider: {$providerName}. " .
                "Currently supported providers: stripe"
            ),
        };
    }

    public function createDefaultAdapter(): PaymentAdapterInterface
    {
        return $this->createStripeAdapter();
    }

    private function createStripeAdapter(): ?StripeAdapter
    {
        $stripeClient = $this->clientFactory->create();

        $adapter = new StripeAdapter();
        $adapter->setStripeClient($stripeClient);

        return $adapter;
    }

    public function isProviderSupported(string $providerName): bool
    {
        return in_array($providerName, ['stripe'], true);
    }

    /**
     * @return array<string>
     */
    public function getSupportedProviders(): array
    {
        return ['stripe'];
    }
}
