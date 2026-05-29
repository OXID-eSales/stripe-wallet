<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

use OxidEsales\Eshop\Core\Registry;

/**
 * OXID-backed implementation of LanguageTranslatorInterface.
 *
 * Thin wrapper around `Registry::getLang()->translateString()` so that
 * callers depending on LanguageTranslatorInterface can be unit-tested
 * without booting the OXID framework.
 *
 * Sprint 119 Phase E (STRP-129).
 */
class OxidLanguageTranslator implements LanguageTranslatorInterface
{
    public function translateString(string $key): string
    {
        // @phpstan-ignore-next-line — Registry seam; OXID virtual parent pattern
        $result = Registry::getLang()->translateString($key);

        if (is_array($result)) {
            $first = reset($result);
            return is_string($first) ? $first : '';
        }

        return $result;
    }
}
