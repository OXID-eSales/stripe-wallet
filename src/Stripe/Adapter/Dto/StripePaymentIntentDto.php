<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Adapter\Dto;

/**
 * Neutral value object representing a Stripe PaymentIntent at the adapter boundary.
 *
 * The `charge` field is non-null only when the PaymentIntent was retrieved with
 * the `latest_charge` expansion (expand: ['latest_charge'] or
 * expand: ['latest_charge.refunds']).
 *
 * Sprint 114.10b: seals the \Stripe\PaymentIntent type inside src/Stripe/Adapter/.
 *
 * @since 2.0.0
 */
final readonly class StripePaymentIntentDto
{
    /**
     * @param string               $id            PaymentIntent ID (pi_...)
     * @param string               $status        Stripe PI status string
     * @param int                  $amount        Authorized amount in Stripe minor units
     * @param string               $currency      ISO-4217 currency code, lowercase
     * @param int                  $created       Unix timestamp of PI creation
     * @param string|null          $latestChargeId Charge ID string when not expanded, null if absent
     * @param StripeChargeDto|null $charge        Populated when PI was retrieved with charge expansion
     */
    public function __construct(
        public string $id,
        public string $status,
        public int $amount,
        public string $currency,
        public int $created,
        public ?string $latestChargeId,
        public ?StripeChargeDto $charge = null,
    ) {
    }
}
