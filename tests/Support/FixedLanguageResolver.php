<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Support;

use OxidEsales\Payments\Stripe\Service\LanguageResolverInterface;

final class FixedLanguageResolver implements LanguageResolverInterface
{
    public function __construct(private readonly int $languageId = 0, private readonly string $abbreviation = 'en')
    {
    }

    public function getActiveLanguageId(): int
    {
        return $this->languageId;
    }

    public function getActiveLanguageAbbreviation(): string
    {
        return $this->abbreviation;
    }
}
