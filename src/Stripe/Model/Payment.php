<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Model;

use OxidEsales\Eshop\Application\Model\Payment as CorePayment;
use OxidEsales\Payments\Stripe\Core\StripeDefinitions;

/**
 * Extended OXID Payment Model with Stripe-specific methods.
 *
 * Adds a single predicate `isStripePaymentMethod()` that checks whether
 * the loaded payment belongs to the Stripe module.
 *
 * @since 1.0.0
 */
class Payment extends CorePayment
{
    /**
     * Check if this payment method is Stripe-powered.
     *
     * Delegates to StripeDefinitions::isStripePaymentMethod() — single source of
     * truth for the `oe_payments_stripe_` prefix check (D7).
     *
     * @return bool True if this is a Stripe payment method, false otherwise
     */
    public function isStripePaymentMethod(): bool
    {
        $paymentId = $this->getId();

        return StripeDefinitions::isStripePaymentMethod($paymentId);
    }
}
