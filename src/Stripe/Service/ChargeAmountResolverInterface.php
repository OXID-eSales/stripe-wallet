<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

use Stripe\Charge;

/**
 * ISP-narrow contract for deriving customer-refund and refundable amounts
 * from a Stripe Charge value-object.
 *
 * Precondition on $charge:
 *   - amount, amount_captured, amount_refunded are non-negative integers (cents)
 *   - amount_captured ≤ amount
 *   - amount_refunded ≤ amount
 *
 * Sprint 103: Separates auth-release (partial-capture remainder auto-refunded
 * by Stripe) from real customer refunds. On a partial capture, Stripe encodes
 * the released amount in amount_refunded — naive subtraction produces a
 * negative "available for refund" value. This interface exposes the corrected
 * customer-refund view without callers needing to know the formula.
 *
 * @since 2.0.0
 */
interface ChargeAmountResolverInterface
{
    /**
     * Amount actually refunded to the customer, in shop currency units (not cents).
     *
     * Computed as max(0, amount_refunded − max(0, amount − amount_captured)).
     * Never negative. Never exceeds amount_captured / 100.
     */
    public function customerRefundedAmount(Charge $charge): float;

    /**
     * Amount still available to refund through the admin form, in shop currency units.
     *
     * Computed as max(0, amount_captured − customerRefundedAmount * 100) / 100.
     * Never negative. customerRefundedAmount + availableForRefund == amount_captured / 100.
     */
    public function availableForRefund(Charge $charge): float;

    /**
     * True iff the customer has been refunded any non-zero amount.
     *
     * Equivalent to customerRefundedAmount($charge) > 0.
     */
    public function hasCustomerRefund(Charge $charge): bool;
}
