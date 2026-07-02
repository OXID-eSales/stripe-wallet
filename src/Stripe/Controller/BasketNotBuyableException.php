<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Controller;

use OxidEsales\Payments\Stripe\Service\BuyabilityFailure;
use RuntimeException;

/**
 * Thrown by StripeOrderController::buildCheckoutEventContext() when one or more
 * cart items are no longer buyable at checkout time.
 *
 * Carries the structured failure list so the catching code in
 * createCheckoutSession() can emit the JSON error response without re-running
 * the buyability check. Mirrors the UserDataValidationException pattern.
 *
 * Story 1 (unbuyable-article-checkout).
 */
class BasketNotBuyableException extends RuntimeException
{
    /** @var BuyabilityFailure[] */
    private readonly array $failures;

    /**
     * @param BuyabilityFailure[] $failures Non-empty list of unbuyable items.
     */
    public function __construct(array $failures)
    {
        parent::__construct('Basket contains items that are no longer buyable');
        $this->failures = $failures;
    }

    /**
     * @return BuyabilityFailure[]
     */
    public function getFailures(): array
    {
        return $this->failures;
    }
}
