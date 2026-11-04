<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Adapter;

use OxidEsales\Payments\Stripe\Adapter\Dto\StripeCustomerDto;

/**
 * Stripe Customer and connectivity operations.
 *
 * @since 2.0.0
 */
interface StripeCustomerAdapterInterface
{
    /**
     * @param array<string, mixed> $params
     */
    public function createStripeCustomer(array $params): StripeCustomerDto;

    public function retrieveStripeCustomer(string $customerId): StripeCustomerDto;
}
