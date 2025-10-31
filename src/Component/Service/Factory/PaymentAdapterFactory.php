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

/**
 * Factory for creating payment adapter instances.
 *
 * @since 1.0.0
 */
class PaymentAdapterFactory
{
    public function __construct(
        private readonly string $secretKey,
        private readonly bool $testMode = true
    ) {
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

    private function createStripeAdapter(): StripeAdapter
    {
        $clientFactory = new StripeClientFactory(
            secretKey: $this->secretKey,
            testMode: $this->testMode
        );

        return new StripeAdapter($clientFactory->create());
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
