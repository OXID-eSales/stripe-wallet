<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Admin;

use OxidEsales\Payments\Stripe\Core\AmountConverter;

/**
 * Semantic validation of admin capture/refund amount inputs.
 *
 * The contract that kills the silent full-capture footgun (Sprint 121,
 * STRP-129): an ABSENT amount means "full capture/refund" and stays
 * legitimate; a PRESENT-but-malformed amount is a failure — it must never
 * degrade to null/full-action.
 *
 * Parsing is strict and locale-tolerant: `12.50` and `12,50` are accepted;
 * thousands separators, signs other than a leading minus, exponents, and
 * embedded whitespace are rejected. The bound comparison happens in minor
 * units (via AmountConverter) to avoid IEEE-754 drift at the boundary.
 *
 * Stateless and deterministic — use the real instance in tests.
 */
final class AdminAmountValidator
{
    /** One integer part, optionally one `.` or `,` separator with digits. Leading minus allowed (caught as non-positive). */
    private const AMOUNT_PATTERN = '/^-?\d+([.,]\d+)?$/';

    public function validate(mixed $raw, float $bound, string $currency): AmountValidationResult
    {
        if ($raw === null || $raw === '') {
            return AmountValidationResult::ok(null);
        }

        $text = $this->toText($raw);
        if ($text === null || preg_match(self::AMOUNT_PATTERN, $text) !== 1) {
            return AmountValidationResult::failure(AmountValidationResult::CODE_MALFORMED);
        }

        $amount = (float) str_replace(',', '.', $text);
        if ($amount <= 0.0) {
            return AmountValidationResult::failure(AmountValidationResult::CODE_NOT_POSITIVE);
        }

        if ($this->decimalCount($text) > AmountConverter::decimalsFor($currency)) {
            return AmountValidationResult::failure(AmountValidationResult::CODE_PRECISION);
        }

        if (AmountConverter::toMinorUnits($amount, $currency) > AmountConverter::toMinorUnits($bound, $currency)) {
            return AmountValidationResult::failure(AmountValidationResult::CODE_EXCEEDS_BOUND);
        }

        return AmountValidationResult::ok($amount);
    }

    /**
     * String form of the raw input, or null for non-scalar / bool junk.
     * Request parameters arrive as strings; native int/float are tolerated
     * for programmatic callers.
     */
    private function toText(mixed $raw): ?string
    {
        if (is_string($raw)) {
            return $raw;
        }

        if (is_int($raw) || is_float($raw)) {
            return (string) $raw;
        }

        return null;
    }

    /** Digits after the single decimal separator (0 when none). */
    private function decimalCount(string $text): int
    {
        $separatorPos = strcspn($text, '.,');
        if ($separatorPos === strlen($text)) {
            return 0;
        }

        return strlen($text) - $separatorPos - 1;
    }
}
