<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Adapter\Dto;

/**
 * Neutral value object representing a Stripe Charge at the adapter boundary.
 *
 * All amount fields carry Stripe minor units (int) — callers convert via AmountConverter.
 * This keeps the DTO currency-agnostic (JPY 1000 = ¥1000; EUR 10000 = €100.00).
 *
 * Sprint 114.10b: seals the \Stripe\Charge type inside src/Stripe/Adapter/.
 *
 * @since 2.0.0
 */
final readonly class StripeChargeDto
{
    /**
     * @param string              $id              Charge ID (ch_...)
     * @param int                 $amount          Authorized amount in Stripe minor units
     * @param int                 $amountCaptured  Captured amount in Stripe minor units
     * @param int                 $amountRefunded  Refunded amount in Stripe minor units
     *                                             (includes auth-release on partial capture)
     * @param string              $currency        ISO-4217 currency code, lowercase
     * @param bool                $captured        Whether the charge has been captured
     * @param int                 $created         Unix timestamp of charge creation
     * @param array<StripeRefundDto> $refunds      Refund sub-objects (populated when expanded)
     */
    public function __construct(
        public string $id,
        public int $amount,
        public int $amountCaptured,
        public int $amountRefunded,
        public string $currency,
        public bool $captured,
        public int $created,
        public array $refunds = [],
    ) {
    }
}
