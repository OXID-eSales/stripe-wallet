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
 * Sprint 21: Extract business logic from StripeRefundRequestHandler.
 * Sprint 22: Removed partial refund - Stripe module only supports full refunds.
 *
 * SOLID Principles:
 * - SRP: Handles refund processing only
 * - OCP: Can be extended for different refund strategies
 * - DIP: Handlers depend on this abstraction
 * - ISP: Focused interface for refund operations only
 *
 * @since 2.0.0
 */
interface RefundServiceInterface
{
    /**
     * Process a full refund for an order.
     *
     * @param string $orderId OXID order ID
     * @param string|null $paymentIntentId Optional PaymentIntent ID (if known)
     * @param string|null $reason Optional refund reason (duplicate, fraudulent, requested_by_customer)
     * @param string|null $description Optional description for metadata
     * @param string $initiator Who triggered the refund (admin, webhook, api, mcp)
     */
    public function processFullRefund(
        string $orderId,
        ?string $paymentIntentId = null,
        ?string $reason = null,
        ?string $description = null,
        string $initiator = 'admin'
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
