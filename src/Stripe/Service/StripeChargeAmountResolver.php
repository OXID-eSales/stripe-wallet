<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

use OxidEsales\Payments\Stripe\Core\AmountConverter;
use Stripe\Charge;

/**
 * Resolves customer-refund and available-for-refund amounts from a Stripe Charge.
 *
 * Owns the partial-capture formula (all inputs are Stripe minor units from the Charge object):
 *   R_release  = max(0, amount − amount_captured)   (auth-released uncaptured remainder)
 *   R_customer = max(0, amount_refunded − R_release) (real customer refunds only)
 *   available  = max(0, toMajorUnits(amount_captured − toMinorUnits(toMajorUnits(R_customer))))
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
    public function customerRefundedAmount(Charge $charge): float
    {
        $currency       = strtoupper($charge->currency ?? '');
        $amountCents    = (int) ($charge->amount ?? 0);
        $capturedCents  = (int) ($charge->amount_captured ?? 0);
        $refundedCents  = (int) ($charge->amount_refunded ?? 0);

        $releaseCents   = max(0, $amountCents - $capturedCents);
        $customerCents  = max(0, $refundedCents - $releaseCents);

        return AmountConverter::toMajorUnits($customerCents, $currency);
    }

    public function availableForRefund(Charge $charge): float
    {
        $currency      = strtoupper($charge->currency ?? '');
        $capturedCents = (int) ($charge->amount_captured ?? 0);
        $customerCents = AmountConverter::toMinorUnits($this->customerRefundedAmount($charge), $currency);

        return max(0.0, AmountConverter::toMajorUnits($capturedCents - $customerCents, $currency));
    }

    public function hasCustomerRefund(Charge $charge): bool
    {
        return $this->customerRefundedAmount($charge) > 0.0;
    }
}
