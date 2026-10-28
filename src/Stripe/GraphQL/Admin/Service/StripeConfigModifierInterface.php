<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\GraphQL\Admin\Service;

interface StripeConfigModifierInterface
{
    public function modify(StripeConfigChange $change): StripeConfigResult;
}
