<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

/**
 * Narrow testability seam around OXID's language / translation primitive.
 *
 * Strips the knowledge of Registry::getLang() out of business services,
 * making them independently unit-testable without a full OXID bootstrap.
 *
 * Sprint 119 Phase E (STRP-129).
 */
interface LanguageTranslatorInterface
{
    /**
     * Return the translated string for the given OXID language constant.
     *
     * When the constant has no translation in the active language, OXID
     * returns the raw key. Callers that need fallback behaviour must detect
     * this case (key === returned value) themselves.
     */
    public function translateString(string $key): string;
}
