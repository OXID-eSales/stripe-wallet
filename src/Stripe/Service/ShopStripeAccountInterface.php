<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

/**
 * The Stripe account (`acct_...`) the shop's API key of the current mode belongs to.
 */
interface ShopStripeAccountInterface
{
    public function id(): string;

    /**
     * Asks Stripe which account the shop's key belongs to and keeps the answer (called when the webhook is registered).
     */
    public function remember(): void;
}
