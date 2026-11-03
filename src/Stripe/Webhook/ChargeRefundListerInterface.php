<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Webhook;

/**
 * The refunds Stripe holds for a charge, asked of Stripe: for `charge.refunded` events that do not carry
 * their `refunds.data` list (newer API versions) or carry it cut short.
 *
 * @since 2.0.0
 */
interface ChargeRefundListerInterface
{
    /**
     * Every refund of the charge that moved money (not failed, not canceled).
     *
     * @return list<ChargeRefund>
     *
     * @throws \OxidEsales\PaymentBase\Adapter\Exception\PaymentAdapterException when Stripe cannot be asked
     */
    public function listFor(string $chargeId): array;
}
