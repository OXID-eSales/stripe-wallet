<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

interface LanguageResolverInterface
{
    public function getActiveLanguageId(): int;

    /**
     * The active language's abbreviation (`de`, `en`, …).
     */
    public function getActiveLanguageAbbreviation(): string;
}
