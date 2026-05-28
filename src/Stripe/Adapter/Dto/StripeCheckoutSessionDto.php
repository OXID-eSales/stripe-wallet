<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Adapter\Dto;

/**
 * Neutral value object representing a Stripe Checkout Session at the adapter boundary.
 *
 * The `paymentIntentId` and `paymentIntentStatus` fields are extracted from the
 * `payment_intent` field of the raw Session (which can be a string ID or an
 * expanded PaymentIntent object). The mapper resolves both forms.
 *
 * Sprint 114.10b: seals the \Stripe\Checkout\Session type inside src/Stripe/Adapter/.
 *
 * @since 2.0.0
 */
final readonly class StripeCheckoutSessionDto
{
    /**
     * @param string              $id                   Session ID (cs_...)
     * @param string              $paymentStatus        Stripe payment_status (paid, unpaid, no_payment_required)
     * @param string              $paymentIntentId      PI ID extracted from payment_intent field; '' if absent
     * @param string              $paymentIntentStatus  PI status when expanded; 'unknown' if not expanded
     * @param array<string,mixed> $metadata             Session metadata key-value pairs
     * @param int                 $amountTotal          Total session amount in Stripe minor units
     * @param string              $currency             ISO-4217 currency code, lowercase
     */
    public function __construct(
        public string $id,
        public string $paymentStatus,
        public string $paymentIntentId,
        public string $paymentIntentStatus,
        public array $metadata,
        public int $amountTotal,
        public string $currency,
    ) {
    }
}
