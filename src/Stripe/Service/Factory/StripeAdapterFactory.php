<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Stripe\Service\Factory;

use OxidSolutionCatalysts\Payments\Component\Adapter\PaymentAdapterInterface;
use OxidSolutionCatalysts\Payments\Component\Service\Factory\PaymentAdapterFactory;
use OxidSolutionCatalysts\Payments\Stripe\Adapter\StripeAdapter;
use OxidSolutionCatalysts\Payments\Stripe\Adapter\StripeClientFactory;
use OxidSolutionCatalysts\Payments\Stripe\Service\ModuleConfigurationService;
use Stripe\StripeClient;

/**
 * Stripe-specific factory for creating payment adapter instances.
 *
 * Extends the provider-agnostic PaymentAdapterFactory base class
 * and implements StripeAdapterFactoryInterface for Stripe-specific methods.
 *
 * @since 1.0.0
 */
class StripeAdapterFactory extends PaymentAdapterFactory implements StripeAdapterFactoryInterface
{
    private const PROVIDER_NAME = 'stripe';

    public function __construct(
        private readonly ModuleConfigurationService $configurationService,
        private readonly StripeClientFactory $clientFactory
    ) {
    }

    /**
     * @throws \InvalidArgumentException
     */
    public function createAdapter(string $providerName): PaymentAdapterInterface
    {
        if ($providerName !== self::PROVIDER_NAME) {
            throw new \InvalidArgumentException(
                "Unsupported payment provider: {$providerName}. " .
                "This factory only supports: " . self::PROVIDER_NAME
            );
        }

        return $this->createStripeAdapter();
    }

    public function createDefaultAdapter(): PaymentAdapterInterface
    {
        return $this->createStripeAdapter();
    }

    public function isProviderSupported(string $providerName): bool
    {
        return $providerName === self::PROVIDER_NAME;
    }

    /**
     * @return array<string>
     */
    public function getSupportedProviders(): array
    {
        return [self::PROVIDER_NAME];
    }

    /**
     * Creates the Stripe adapter instance.
     *
     * @return StripeAdapter
     * @throws \RuntimeException If Stripe API key is not configured
     */
    private function createStripeAdapter(): StripeAdapter
    {
        $stripeClient = $this->clientFactory->create();

        if ($stripeClient === null) {
            throw new \RuntimeException(
                'Stripe API key is not configured. Please configure the Stripe secret key in module settings. ' .
                'Expected format: sk_test_... (test mode) or sk_live_... (live mode)'
            );
        }

        return new StripeAdapter($stripeClient);
    }

    /**
     * Get Stripe SDK client for direct API access.
     *
     * Useful for operations not covered by the adapter (e.g., Checkout Sessions).
     *
     * @return StripeClient
     * @throws \RuntimeException If Stripe API key is not configured
     */
    public function getStripeClient(): StripeClient
    {
        $client = $this->clientFactory->create();

        if ($client === null) {
            throw new \RuntimeException(
                'Stripe API key is not configured. Please configure the Stripe secret key in module settings.'
            );
        }

        return $client;
    }

    /**
     * Check if Stripe is in test mode.
     *
     * @return bool
     */
    public function isTestMode(): bool
    {
        return $this->configurationService->isTestMode();
    }
}
