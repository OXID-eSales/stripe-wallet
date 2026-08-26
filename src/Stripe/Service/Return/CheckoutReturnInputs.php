<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service\Return;

/**
 * The three identifiers a Stripe return must carry, once they have been
 * checked. Holding them in one value means the controller cannot accidentally
 * proceed with a half-validated set.
 */
final class CheckoutReturnInputs
{
    public function __construct(
        public readonly string $sessionId,
        public readonly string $contractId,
        public readonly string $contractToken,
    ) {
    }
}
