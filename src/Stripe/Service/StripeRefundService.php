<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

use OxidEsales\PaymentBase\Service\AbstractPaymentRefundService;
use OxidEsales\PaymentBase\Service\Exception\RefundFailedException;

/**
 * Stripe-specific implementation of payment refund service.
 *
 * Sprint 3: Extends AbstractPaymentRefundService with Stripe-specific behavior.
 * Sprint 22: Rejects partial refunds - Stripe module only supports full refunds.
 * Sprint 26: Uses LazyStripeAdapter for lazy adapter creation (module activation fix).
 *
 * Uses contract-based refund approach (per Q&A decision Q6).
 */
class StripeRefundService extends AbstractPaymentRefundService
{
    /**
     * Validate refund amount is within limits.
     *
     * Sprint 87: Allows partial refunds (amount <= available).
     * Parent validates: amount > 0, amount is finite, amount <= available.
     *
     * @throws RefundFailedException If amount exceeds available
     */
    protected function validateRefundAmount(string $contractId, float $refundAmount, float $availableForRefund): void
    {
        parent::validateRefundAmount($contractId, $refundAmount, $availableForRefund);
    }
}
