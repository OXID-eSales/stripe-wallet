<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service\Factory;

use OxidEsales\PaymentBase\Adapter\PaymentAdapterInterface;
use OxidEsales\PaymentBase\Repository\IdempotencyRepositoryInterface;
use OxidEsales\PaymentBase\Service\Factory\PaymentAdapterFactory;
use OxidEsales\Payments\Stripe\Adapter\Helper\PaymentIntentHelper;
use OxidEsales\Payments\Stripe\Adapter\Helper\RefundHelper;
use OxidEsales\Payments\Stripe\Adapter\StripeAdapter;
use OxidEsales\Payments\Stripe\Adapter\StripeAdapterInterface;
use OxidEsales\Payments\Stripe\Adapter\StripeClientFactory;
use OxidEsales\Payments\Stripe\Core\StripeDefinitions;
use OxidEsales\Payments\Stripe\Service\ModuleConfigurationServiceInterface;
use Stripe\StripeClient;

/**
 * Stripe-specific factory for creating payment adapter instances.
 *
 * Extends the provider-agnostic PaymentAdapterFactory base class
 * and implements StripeAdapterFactoryInterface for Stripe-specific methods.
 *
 * Sprint 46: Idempotency moved into helpers; factory injects repository directly.
 *
 * @since 1.0.0
 */
class StripeAdapterFactory extends PaymentAdapterFactory implements StripeAdapterFactoryInterface
{
    public function __construct(
        private readonly ModuleConfigurationServiceInterface $configurationService,
        private readonly StripeClientFactory $clientFactory,
        private readonly ?IdempotencyRepositoryInterface $idempotencyRepository = null
    ) {
    }

    /**
     * @throws \InvalidArgumentException
     */
    public function createAdapter(string $providerName): PaymentAdapterInterface
    {
        if ($providerName !== StripeDefinitions::PROVIDER) {
            throw new \InvalidArgumentException(
                "Unsupported payment provider: {$providerName}. " .
                "This factory only supports: " . StripeDefinitions::PROVIDER
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
        return $providerName === StripeDefinitions::PROVIDER;
    }

    /**
     * @return array<string>
     */
    public function getSupportedProviders(): array
    {
        return [StripeDefinitions::PROVIDER];
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

        $paymentIntentHelper = new PaymentIntentHelper($this->idempotencyRepository);
        $refundHelper = new RefundHelper($this->idempotencyRepository);

        return new StripeAdapter($stripeClient, $paymentIntentHelper, $refundHelper);
    }

    /**
     * Get Stripe adapter with Stripe-specific methods.
     *
     * Sprint 19: Use this instead of getStripeClient() to route SDK calls through adapter.
     *
     * @return StripeAdapterInterface
     * @throws \RuntimeException If Stripe API key is not configured
     */
    public function getStripeAdapter(): StripeAdapterInterface
    {
        return $this->createStripeAdapter();
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
