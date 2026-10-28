<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\GraphQL\Admin\Service;

/**
 * The Stripe module settings as the admin API reads them (infrastructure seam over the module settings).
 */
interface StripeAdminSettingsInterface
{
    public function snapshot(): StripeConfigSnapshot;
}
