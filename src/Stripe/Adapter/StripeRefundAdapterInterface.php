<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Adapter;

use Stripe\Charge;
use Stripe\Refund;

/**
 * Stripe refund and charge operations.
 *
 * Sprint 46: ISP split from StripeAdapterInterface.
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
    ): Refund;

    public function retrieveCharge(string $chargeId): Charge;
}
