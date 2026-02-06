<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Adapter;

use Stripe\Customer;

/**
 * Stripe Customer and connectivity operations.
 *
 * Sprint 46: ISP split from StripeAdapterInterface.
 *
 * @since 2.0.0
 */
interface StripeCustomerAdapterInterface
{
    /**
     * @param array<string, mixed> $params
     */
    public function createStripeCustomer(array $params): Customer;

    public function retrieveStripeCustomer(string $customerId): Customer;

    public function testConnection(): bool;
}
