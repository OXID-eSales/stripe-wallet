<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Environment;

interface DevelopmentEnvironmentCheckerInterface
{
    public function isDevelopmentMode(): bool;
}
