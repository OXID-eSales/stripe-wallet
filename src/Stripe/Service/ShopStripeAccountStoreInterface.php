<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

/**
 * Keeps the shop's Stripe account id per mode (`test`, `live`) in the module's oxconfig variables.
 */
interface ShopStripeAccountStoreInterface
{
    /**
     * @return string the stored account id, '' when none
     */
    public function get(string $mode): string;

    public function save(string $mode, string $accountId): void;
}
