<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

use OxidEsales\PaymentComponent\Service\AbstractPaymentRefundService;
use OxidEsales\PaymentComponent\Service\Exception\RefundFailedException;

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
     * Override to reject partial refunds.
     *
     * Stripe module only supports full refunds.
     * For partial refunds, use Stripe Dashboard directly.
     *
     * @throws RefundFailedException If partial refund requested
     */
    protected function validateRefundAmount(string $contractId, float $refundAmount, float $availableForRefund): void
    {
        // Validate finite before partial refund check (parent has is_finite guard)
        if (!is_finite($refundAmount)) {
            parent::validateRefundAmount($contractId, $refundAmount, $availableForRefund);
            return;
        }

        // Stripe module only supports full refund - reject partial amounts
        if (abs($refundAmount - $availableForRefund) > 0.01) {
            throw new RefundFailedException(
                $contractId,
                sprintf(
                    'Stripe module only supports full refunds. Requested: %.2f, Available: %.2f. Use Stripe Dashboard for partial refunds.',
                    $refundAmount,
                    $availableForRefund
                )
            );
        }

        parent::validateRefundAmount($contractId, $refundAmount, $availableForRefund);
    }
}
