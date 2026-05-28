<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

use OxidEsales\Payments\Stripe\Adapter\Dto\StripeChargeDto;
use OxidEsales\Payments\Stripe\Core\AmountConverter;

/**
 * Resolves customer-refund and available-for-refund amounts from a StripeChargeDto.
 *
 * Owns the partial-capture formula (all inputs are Stripe minor units from the DTO):
 *   R_release  = max(0, amount − amountCaptured)   (auth-released uncaptured remainder)
 *   R_customer = max(0, amountRefunded − R_release) (real customer refunds only)
 *   available  = max(0, toMajorUnits(amountCaptured − toMinorUnits(toMajorUnits(R_customer))))
 *
 * On a full capture (amountCaptured == amount), R_release = 0 and the formula
 * collapses to the pre-fix values, so the regression path is preserved (G4).
 *
 * The two max(0, …) clamps are not redundant: float arithmetic can produce
 * −0.000…001 on fixtures where amountCaptured == amountRefunded, which would
 * leak as "max=-0.00" into the HTML5 input attribute and trigger browser errors.
 *
 * Sprint 114.10b: parameter type changed from \Stripe\Charge to StripeChargeDto
 * to seal the Stripe SDK type inside src/Stripe/Adapter/ (A1 boundary fix).
 *
 * @since 2.0.0
 */
final class StripeChargeAmountResolver implements ChargeAmountResolverInterface
{
    public function customerRefundedAmount(StripeChargeDto $charge): float
    {
        $currency      = strtoupper($charge->currency);
        $releaseCents  = max(0, $charge->amount - $charge->amountCaptured);
        $customerCents = max(0, $charge->amountRefunded - $releaseCents);

        return AmountConverter::toMajorUnits($customerCents, $currency);
    }

    public function availableForRefund(StripeChargeDto $charge): float
    {
        $currency      = strtoupper($charge->currency);
        $customerCents = AmountConverter::toMinorUnits($this->customerRefundedAmount($charge), $currency);

        return max(0.0, AmountConverter::toMajorUnits($charge->amountCaptured - $customerCents, $currency));
    }

    public function hasCustomerRefund(StripeChargeDto $charge): bool
    {
        return $this->customerRefundedAmount($charge) > 0.0;
    }
}
