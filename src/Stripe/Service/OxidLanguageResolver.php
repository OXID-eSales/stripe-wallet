<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

use OxidEsales\Eshop\Core\Registry;

class OxidLanguageResolver implements LanguageResolverInterface
{
    public function getActiveLanguageId(): int
    {
        return (int) Registry::getLang()->getBaseLanguage();
    }
}
