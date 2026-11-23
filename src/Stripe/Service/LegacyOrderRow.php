<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

/**
 * Reads a column of an order row as text or number, whatever type the database driver hands back.
 *
 * STRP-181.
 */
final class LegacyOrderRow
{
    /**
     * @param array<string, mixed> $row
     */
    public static function text(array $row, string $column): string
    {
        $value = $row[$column] ?? '';

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function number(array $row, string $column): float
    {
        $value = $row[$column] ?? 0.0;

        return is_numeric($value) ? (float) $value : 0.0;
    }
}
