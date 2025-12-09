<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Stripe\Service;

use OxidSolutionCatalysts\Payments\Stripe\DTO\RefundResult;

/**
 * Service interface for processing Stripe refunds.
 *
 * Sprint 21: Extract business logic from StripeRefundRequestHandler.
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
    ): RefundResult;

    /**
     * Process a partial refund for an order.
     *
     * @param string $orderId OXID order ID
     * @param int $amountCents Amount to refund in cents
     * @param string|null $paymentIntentId Optional PaymentIntent ID (if known)
     * @param string|null $reason Optional refund reason
     * @param string|null $description Optional description for metadata
     * @param string $initiator Who triggered the refund
     */
    public function processPartialRefund(
        string $orderId,
        int $amountCents,
        ?string $paymentIntentId = null,
        ?string $reason = null,
        ?string $description = null,
        string $initiator = 'admin'
    ): RefundResult;

    /**
     * Process refund directly by charge ID (when charge is already known).
     *
     * @param string $chargeId Stripe Charge ID (ch_xxx)
     * @param int|null $amountCents Amount in cents (null for full refund)
     * @param string|null $reason Optional refund reason
     * @param array<string, string>|null $metadata Optional metadata
     */
    public function processRefundByCharge(
        string $chargeId,
        ?int $amountCents = null,
        ?string $reason = null,
        ?array $metadata = null
    ): RefundResult;
}
