<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service\Factory;

use OxidEsales\PaymentBase\Service\Factory\PaymentAdapterFactoryInterface;
use OxidEsales\Payments\Stripe\Adapter\StripeAdapterInterface;

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
     * Get Stripe adapter with Stripe-specific methods.
     *
     * Sprint 19: Use this instead of getStripeClient() to route SDK calls through adapter.
     *
     * @return StripeAdapterInterface
     * @throws \RuntimeException If Stripe API key is not configured
     */
    public function getStripeAdapter(): StripeAdapterInterface;

    /**
     * Check if Stripe is in test mode.
     *
     * @return bool
     */
    public function isTestMode(): bool;
}
