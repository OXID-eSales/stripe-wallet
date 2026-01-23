<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

use OxidEsales\Eshop\Application\Model\Order;

/**
 * Service for updating order fields after refund.
 *
 * Sprint 10: Extracted from StripeRefundRequestHandler.
 *
 * @since 2.0.0
 */
interface OrderRefundUpdateServiceInterface
{
    /**
     * Update order fields after a full refund.
     *
     * Sets all cost fields as refunded and marks order articles as fully refunded.
     *
     * @param Order $order The order to update
     */
    public function updateOrderAfterFullRefund(Order $order): void;
}
