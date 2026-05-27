<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Core;

/**
 * Currency-aware major↔minor unit converter for Stripe amounts.
 *
 * Stripe expects amounts in the smallest currency unit (minor units):
 * - 2-decimal currencies (EUR, USD, GBP, …): amount in cents     → 19.99 EUR = 1999
 * - 0-decimal currencies (JPY, KRW, …):       amount is the unit  → ¥1000    = 1000
 * - 3-decimal currencies (BHD, KWD, …):       amount in 1/1000s  → 1.234 BHD = 1234
 *
 * Static API: AmountConverter is a pure function with no side-effects and
 * no swappable dependency. An interface would add a test-double layer with
 * no benefit (per R-9.1 / YAGNI). Test it by calling the static methods directly.
 *
 * Rounding: toMinorUnits uses (int) round() to avoid IEEE-754 truncation drift
 * (e.g. 19.99 * 100 = 1998.9999… in float → naive (int) gives 1998, not 1999).
 *
 * Unknown or empty currency defaults to 2 decimals (safe fallback; do NOT
 * hardcode 'EUR' — currency-neutral fallback keeps the converter shop-agnostic).
 *
 * Sprint 114.7: centralises the ~22 hand-coded `* 100` / `/ 100` sites.
 *
 * @since 2.0.0
 */
final class AmountConverter
{
    /**
     * Zero-decimal currencies per Stripe's published list.
     * https://stripe.com/docs/currencies#zero-decimal
     *
     * Pinned by AmountConverterTest::testZeroDecimalSetIsPinned — edit deliberately.
     *
     * @var array<string>
     */
    private const ZERO_DECIMAL_CURRENCIES = [
        'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW',
        'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV',
        'XAF', 'XOF', 'XPF',
    ];

    /**
     * Three-decimal currencies per Stripe's published list.
     * https://stripe.com/docs/currencies#three-decimal
     *
     * Note (YAGNI): Stripe additionally requires that the amount is a multiple of 10
     * for these currencies (e.g. BHD 1.234 → 1234, but 1.235 must be rounded to 1240).
     * This rule is NOT enforced here — the module is EUR-centric and this edge case
     * has no confirmed consumer. Add validation in the adapter if BHD/KWD support is
     * introduced in a future sprint.
     *
     * @var array<string>
     */
    private const THREE_DECIMAL_CURRENCIES = ['BHD', 'JOD', 'KWD', 'OMR', 'TND'];

    /**
     * Number of decimal places (exponent) for a given ISO-4217 currency code.
     *
     * Returns 0 for zero-decimal currencies (JPY, KRW, …),
     * returns 3 for three-decimal currencies (BHD, KWD, …),
     * returns 2 for everything else (the default, not hard-wired to 'EUR').
     *
     * Matching is case-insensitive.
     */
    public static function decimalsFor(string $currency): int
    {
        $upper = strtoupper($currency);

        if (in_array($upper, self::ZERO_DECIMAL_CURRENCIES, true)) {
            return 0;
        }

        if (in_array($upper, self::THREE_DECIMAL_CURRENCIES, true)) {
            return 3;
        }

        return 2;
    }

    /**
     * Convert a major-unit amount to Stripe minor units (integer).
     *
     * Uses (int) round() — not truncation — to avoid IEEE-754 drift:
     *   19.99 * 100 = 1998.9999…  → (int) gives 1998 (WRONG)
     *   (int) round(19.99 * 100)  → 1999 (CORRECT)
     *
     * For zero-decimal currencies the multiplier is 10**0 = 1, so the
     * input is returned as-is (¥1000 → 1000 minor units, not 100000).
     */
    public static function toMinorUnits(float $major, string $currency): int
    {
        $multiplier = 10 ** self::decimalsFor($currency);

        return (int) round($major * $multiplier);
    }

    /**
     * Convert Stripe minor units (integer) to a major-unit float.
     *
     * For zero-decimal currencies the divisor is 10**0 = 1, so the
     * integer is returned as a float unchanged (1000 minor JPY → 1000.0).
     */
    public static function toMajorUnits(int $minor, string $currency): float
    {
        $divisor = 10 ** self::decimalsFor($currency);

        return $minor / $divisor;
    }
}
