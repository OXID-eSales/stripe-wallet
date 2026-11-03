<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service\Return;

/**
 * What a Stripe return carries once it has been checked: the Checkout Session, the contract and the return token
 * ('' when the return carried none). Holding them in one value means the controller cannot accidentally
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
