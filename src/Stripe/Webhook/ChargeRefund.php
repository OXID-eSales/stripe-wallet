<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Webhook;

/**
 * One refund Stripe lists on a charge (`re_...`), amount in currency units.
 */
final class ChargeRefund
{
    public function __construct(
        public readonly string $id,
        public readonly float $amount
    ) {
    }
}
