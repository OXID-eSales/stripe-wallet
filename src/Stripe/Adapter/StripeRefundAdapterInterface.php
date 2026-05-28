<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Adapter;

use OxidEsales\Payments\Stripe\Adapter\Dto\StripeChargeDto;
use OxidEsales\Payments\Stripe\Adapter\Dto\StripeRefundDto;

/**
 * Stripe refund and charge operations.
 *
 * Sprint 46: ISP split from StripeAdapterInterface.
 * Sprint 114.10b: return types flipped to DTOs (A1 boundary fix).
 *
 * @since 2.0.0
 */
interface StripeRefundAdapterInterface
{
    /**
     * @param array<string, string>|null $metadata
     */
    public function createRefundByCharge(
        string $chargeId,
        ?int $amount = null,
        ?string $reason = null,
        ?array $metadata = null
    ): StripeRefundDto;

    public function retrieveCharge(string $chargeId): StripeChargeDto;
}
