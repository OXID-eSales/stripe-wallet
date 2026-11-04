<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service\Factory;

use OxidEsales\PaymentBase\Repository\IdempotencyRepositoryInterface;
use OxidEsales\Payments\Stripe\Adapter\Helper\CheckoutSessionHelper;
use OxidEsales\Payments\Stripe\Adapter\Helper\PaymentIntentHelper;
use OxidEsales\Payments\Stripe\Adapter\Helper\PaymentMethodHelper;
use OxidEsales\Payments\Stripe\Adapter\Helper\RefundHelper;
use OxidEsales\Payments\Stripe\Adapter\StripeAdapter;
use OxidEsales\Payments\Stripe\Adapter\StripeAdapterInterface;
use OxidEsales\Payments\Stripe\Adapter\StripeClientFactory;
use OxidEsales\Payments\Stripe\Service\ModuleConfigurationServiceInterface;

/**
 * Stripe-specific factory for creating payment adapter instances.
 *
 * Implements StripeAdapterFactoryInterface (payment-base's generic factory is gone, VR A4).
 *
 * Sprint 133 (F8): the repository is required — a null one silently produced
 * helpers with no duplicate-charge protection.
 *
 * @since 1.0.0
 */
class StripeAdapterFactory implements StripeAdapterFactoryInterface
{
    public function __construct(
        private readonly ModuleConfigurationServiceInterface $configurationService,
        private readonly StripeClientFactory $clientFactory,
        private readonly IdempotencyRepositoryInterface $idempotencyRepository
    ) {
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

        return new StripeAdapter(
            $stripeClient,
            $paymentIntentHelper,
            $refundHelper,
            new CheckoutSessionHelper(),
            new PaymentMethodHelper()
        );
    }

    /**
     * Get Stripe adapter with Stripe-specific methods.
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
