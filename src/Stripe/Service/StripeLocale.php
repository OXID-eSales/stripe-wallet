<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

/**
 * The `locale` of a Stripe Checkout Session for a shop language.
 *
 * Stripe renders its page / embedded form in the session's locale; without one it uses the BROWSER's
 * language ('auto'), so a German shop would show an English visitor an English payment form.
 */
final class StripeLocale
{
    public const AUTO = 'auto';

    /** Locales Stripe Checkout supports, keyed lower-case. */
    private const SUPPORTED = [
        'bg', 'cs', 'da', 'de', 'el', 'en', 'en-gb', 'es', 'es-419', 'et', 'fi', 'fil', 'fr', 'fr-ca',
        'hr', 'hu', 'id', 'it', 'ja', 'ko', 'lt', 'lv', 'ms', 'mt', 'nb', 'nl', 'pl', 'pt', 'pt-br',
        'ro', 'ru', 'sk', 'sl', 'sv', 'th', 'tr', 'vi', 'zh', 'zh-hk', 'zh-tw',
    ];

    private function __construct()
    {
    }

    /**
     * @param string $abbreviation the shop language's abbreviation (`de`, `en`, …)
     */
    public static function forLanguage(string $abbreviation): string
    {
        $key = strtolower(trim($abbreviation));
        if (!in_array($key, self::SUPPORTED, true)) {
            return self::AUTO;
        }

        return self::canonical($key);
    }

    /** Stripe spells regions in upper case: `pt-br` -> `pt-BR`. */
    private static function canonical(string $key): string
    {
        $parts = explode('-', $key, 2);
        if (count($parts) === 2 && strlen($parts[1]) === 2) {
            return $parts[0] . '-' . strtoupper($parts[1]);
        }

        return $key;
    }
}
