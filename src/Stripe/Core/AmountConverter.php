<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Core;

use OxidEsales\PaymentBase\Math\Money\MinorUnitConverter;

/**
 * Currency-aware major↔minor unit converter for Stripe amounts.
 *
 * Stripe expects amounts in the smallest currency unit (minor units):
 * - 2-decimal currencies (EUR, USD, GBP, …): amount in cents     → 19.99 EUR = 1999
 * - 0-decimal currencies (JPY, KRW, …):       amount is the unit  → ¥1000    = 1000
 * - 3-decimal currencies (BHD, KWD, …):       amount in 1/1000s  → 1.234 BHD = 1234
 *
 * Thin Stripe-facing facade over {@see MinorUnitConverter} (payment-base),
 * which owns the canonical currency lists and the rounding-safe arithmetic.
 * Kept as the public API the Stripe module already depends on; behaviour is
 * identical (the AmountConverterTest characterization suite is the parity net).
 *
 * Static API: pure function, no side-effects, no swappable dependency (YAGNI).
 *
 * Note (YAGNI): Stripe additionally requires three-decimal amounts to be a
 * multiple of 10 (e.g. BHD 1.235 → 1240). Not enforced — the module is
 * EUR-centric and the edge case has no confirmed consumer.
 *
 * Sprint 114.7: centralises the ~22 hand-coded `* 100` / `/ 100` sites.
 * §5.2 (2026-06-22): delegates to MinorUnitConverter to drop the duplicated
 * currency lists shared with payment-base's MCP formatters.
 *
 * @since 2.0.0
 */
class AmountConverter
{
    /**
     * Number of decimal places (exponent) for a given ISO-4217 currency code.
     * Case-insensitive; 0 for zero-decimal, 3 for three-decimal, 2 otherwise.
     */
    public static function decimalsFor(string $currency): int
    {
        return MinorUnitConverter::decimalsFor($currency);
    }

    /**
     * Convert a major-unit amount to Stripe minor units (integer).
     *
     * Uses (int) round() internally — not truncation — to avoid IEEE-754 drift:
     *   19.99 * 100 = 1998.9999…  → (int) gives 1998 (WRONG)
     *   (int) round(19.99 * 100)  → 1999 (CORRECT)
     */
    public static function toMinorUnits(float $major, string $currency): int
    {
        return MinorUnitConverter::toMinorUnits($major, $currency);
    }

    /**
     * Convert Stripe minor units (integer) to a major-unit float.
     */
    public static function toMajorUnits(int $minor, string $currency): float
    {
        return MinorUnitConverter::toMajorUnits($minor, $currency);
    }
}
