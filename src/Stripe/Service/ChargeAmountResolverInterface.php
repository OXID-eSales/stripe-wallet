<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

use OxidEsales\Payments\Stripe\Adapter\Dto\StripeChargeDto;

/**
 * ISP-narrow contract for deriving customer-refund and refundable amounts
 * from a StripeChargeDto value-object.
 *
 * Precondition on $charge:
 *   - amount, amountCaptured, amountRefunded are non-negative integers (minor units)
 *   - amountCaptured ≤ amount
 *   - amountRefunded ≤ amount
 *
 * Sprint 103: Separates auth-release (partial-capture remainder auto-refunded
 * by Stripe) from real customer refunds. On a partial capture, Stripe encodes
 * the released amount in amountRefunded — naive subtraction produces a
 * negative "available for refund" value. This interface exposes the corrected
 * customer-refund view without callers needing to know the formula.
 *
 * Sprint 114.10b: parameter type changed from \Stripe\Charge to StripeChargeDto
 * to seal the Stripe SDK type inside src/Stripe/Adapter/ (A1 boundary fix).
 *
 * @since 2.0.0
 */
interface ChargeAmountResolverInterface
{
    /**
     * Amount actually refunded to the customer, in shop currency major units.
     *
     * Computed as AmountConverter::toMajorUnits(max(0, amountRefunded − max(0, amount − amountCaptured)), currency).
     * Never negative. Never exceeds AmountConverter::toMajorUnits(amountCaptured, currency).
     */
    public function customerRefundedAmount(StripeChargeDto $charge): float;

    /**
     * Amount still available to refund through the admin form, in shop currency major units.
     *
     * Computed via AmountConverter: max(0, toMajorUnits(amountCaptured − toMinorUnits(customerRefundedAmount))).
     * Never negative. customerRefundedAmount + availableForRefund == toMajorUnits(amountCaptured, currency).
     */
    public function availableForRefund(StripeChargeDto $charge): float;

    /**
     * True iff the customer has been refunded any non-zero amount.
     *
     * Equivalent to customerRefundedAmount($charge) > 0.
     */
    public function hasCustomerRefund(StripeChargeDto $charge): bool;
}
