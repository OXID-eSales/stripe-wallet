<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

use OxidEsales\PaymentComponent\Adapter\Response\RefundResponse;

/**
 * Service interface for processing Stripe refunds.
 *
 * Supports both full and partial refunds. When amount is null, the entire
 * captured amount is refunded. When amount is provided, only that amount
 * (in major currency units, e.g. 5.50 EUR) is refunded.
 *
 * @since 2.0.0
 */
interface RefundServiceInterface
{
    /**
     * Process a refund for an order (full or partial).
     *
     * @param string $orderId OXID order ID
     * @param string|null $paymentIntentId Optional PaymentIntent ID (if known)
     * @param string|null $reason Optional refund reason (duplicate, fraudulent, requested_by_customer)
     * @param string|null $description Optional description for metadata
     * @param string $initiator Who triggered the refund (admin, webhook, api, mcp)
     * @param float|null $amount Refund amount in major currency units (null = full refund)
     */
    public function processRefund(
        string $orderId,
        ?string $paymentIntentId = null,
        ?string $reason = null,
        ?string $description = null,
        string $initiator = 'admin',
        ?float $amount = null
    ): RefundResponse;

    /**
     * Process refund directly by charge ID (when charge is already known).
     *
     * @param string $chargeId Stripe Charge ID (ch_xxx)
     * @param string|null $reason Optional refund reason
     * @param array<string, string>|null $metadata Optional metadata
     */
    public function processRefundByCharge(
        string $chargeId,
        ?string $reason = null,
        ?array $metadata = null
    ): RefundResponse;
}
