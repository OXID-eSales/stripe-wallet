<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\GraphQL\Admin\Service;

/**
 * What an audited admin operation came to: done, or refused with the reason that will be shown as its error.
 */
interface StripeAdminOutcomeInterface
{
    public function refusal(): ?StripeAdminRefusal;
}
