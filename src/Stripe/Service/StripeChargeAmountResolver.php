<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

use Stripe\Charge;

/**
 * Resolves customer-refund and available-for-refund amounts from a Stripe Charge.
 *
 * Owns the partial-capture formula:
 *   R_release  = max(0, amount − amount_captured)   (auth-released uncaptured remainder)
 *   R_customer = max(0, amount_refunded − R_release) (real customer refunds only)
 *   available  = max(0, amount_captured − R_customer * CENTS_PER_UNIT) / CENTS_PER_UNIT
 *
 * On a full capture (amount_captured == amount), R_release = 0 and the formula
 * collapses to the pre-fix values, so the regression path is preserved (G4).
 *
 * The two max(0, …) clamps are not redundant: float arithmetic can produce
 * −0.000…001 on fixtures where amount_captured == amount_refunded, which would
 * leak as "max=-0.00" into the HTML5 input attribute and trigger browser errors.
 *
 * @since 2.0.0
 */
final class StripeChargeAmountResolver implements ChargeAmountResolverInterface
{
    private const CENTS_PER_UNIT = 100;

    public function customerRefundedAmount(Charge $charge): float
    {
        $amountCents    = (int) ($charge->amount ?? 0);
        $capturedCents  = (int) ($charge->amount_captured ?? 0);
        $refundedCents  = (int) ($charge->amount_refunded ?? 0);

        $releaseCents   = max(0, $amountCents - $capturedCents);
        $customerCents  = max(0, $refundedCents - $releaseCents);

        return $customerCents / self::CENTS_PER_UNIT;
    }

    public function availableForRefund(Charge $charge): float
    {
        $capturedCents  = (int) ($charge->amount_captured ?? 0);
        $customerCents  = $this->customerRefundedAmount($charge) * self::CENTS_PER_UNIT;

        return max(0.0, ($capturedCents - $customerCents)) / self::CENTS_PER_UNIT;
    }

    public function hasCustomerRefund(Charge $charge): bool
    {
        return $this->customerRefundedAmount($charge) > 0.0;
    }
}
