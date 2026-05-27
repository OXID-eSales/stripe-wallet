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
     * List of Stripe payment method IDs.
     *
     * @var array<string>
     */
    private const STRIPE_PAYMENT_METHODS = [
        StripeDefinitions::STRIPE_WALLET_PAYMENT_ID
    ];

    /**
     * Check if this payment method is Stripe-powered.
     *
     * Returns true if the payment method ID is in the list of known Stripe
     * payment methods or starts with the `oe_payments_stripe_` prefix.
     *
     * @return bool True if this is a Stripe payment method, false otherwise
     */
    public function isStripePaymentMethod(): bool
    {
        $paymentId = $this->getId();

        if (empty($paymentId)) {
            return false;
        }

        if (in_array($paymentId, self::STRIPE_PAYMENT_METHODS, true)) {
            return true;
        }

        return str_starts_with($paymentId, 'oe_payments_stripe_');
    }
}
