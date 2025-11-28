<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Stripe\Service\Factory;

use OxidSolutionCatalysts\Payments\Component\Service\Factory\PaymentAdapterFactoryInterface;
use Stripe\StripeClient;

/**
 * Stripe-specific adapter factory interface.
 *
 * Extends the provider-agnostic PaymentAdapterFactoryInterface with
 * Stripe-specific methods. Use this interface in Stripe handlers
 * for proper TDD and dependency injection.
 *
 * @since 1.0.0
 */
interface StripeAdapterFactoryInterface extends PaymentAdapterFactoryInterface
{
    /**
     * Get Stripe SDK client for direct API access.
     *
     * Useful for operations not covered by the adapter (e.g., Checkout Sessions).
     *
     * @return StripeClient
     * @throws \RuntimeException If Stripe API key is not configured
     */
    public function getStripeClient(): StripeClient;

    /**
     * Check if Stripe is in test mode.
     *
     * @return bool
     */
    public function isTestMode(): bool;
}
