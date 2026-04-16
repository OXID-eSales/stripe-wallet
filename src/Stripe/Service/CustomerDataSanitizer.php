<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

/**
 * Sanitizes customer data before sending to Stripe API.
 *
 * Sprint 90 (STRP-125): Prevents payment failures from special characters.
 *
 * Rules:
 * - Ensures valid UTF-8 (replaces invalid sequences)
 * - Strips control characters (U+0000–U+001F) except tab and newline
 * - Collapses multiple whitespace + trims
 * - Truncates to max length at character boundary
 *
 * Preserves: Unicode letters (umlauts, accents, Cyrillic, CJK), emoji,
 * apostrophes, hyphens, punctuation.
 *
 * @since 2.0.0
 */
final class CustomerDataSanitizer
{
    public function sanitize(string $value, int $maxLength = 255): string
    {
        if ($value === '') {
            return '';
        }

        // 1. Ensure valid UTF-8
        $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');

        // 2. Strip control characters except tab (\x09) and newline (\x0A)
        $value = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value);

        // 3. Collapse whitespace + trim
        $value = (string) preg_replace('/\s+/u', ' ', $value);
        $value = trim($value);

        // 4. Truncate at character boundary
        if (mb_strlen($value, 'UTF-8') > $maxLength) {
            $value = mb_substr($value, 0, $maxLength, 'UTF-8');
        }

        return $value;
    }
}
