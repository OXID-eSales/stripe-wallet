<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\PaymentHandler;

/**
 * The checkout a shopper already has in flight, reduced to the facts that decide
 * whether it can be handed to them again: the contract's state and provider, and
 * what Stripe says about the session itself.
 */
readonly class ExistingCheckout
{
    public function __construct(
        public string $contractState,
        public string $providerName,
        public string $sessionId,
        public int $sessionAmountMinorUnits,
        public string $sessionCurrency,
        public string $sessionPaymentStatus,
    ) {
    }
}
