<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\GraphQL\Admin\Service;

interface StripePaymentDetailsReaderInterface
{
    /**
     * The Stripe part of a payment contract; null when the contract is not Stripe's (or does not exist).
     */
    public function find(string $contractId): ?StripePaymentDetails;
}
