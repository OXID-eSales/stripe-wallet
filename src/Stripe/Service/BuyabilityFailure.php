<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

/**
 * Immutable value object representing a single cart item that is no longer
 * buyable at checkout time.
 *
 * Carries the article id, the product title as shown in the basket, and the
 * reason (an OXID translation key) so the controller can emit a structured
 * per-product JSON error. Mirrors the FieldValidationFailure shape used by the
 * user-data validation path.
 *
 * Story 1 (unbuyable-article-checkout).
 */
class BuyabilityFailure
{
    public function __construct(
        public readonly string $articleId,
        public readonly string $productTitle,
        public readonly string $reason,
    ) {
    }
}
