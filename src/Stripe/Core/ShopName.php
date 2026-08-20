<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Core;

/**
 * Reads the configured shop name, without inventing one.
 *
 * Sprint 133 · Story 20 (F20): `$shop->oxshops__oxname->value ?? 'OXID eShop'`
 * substituted a generic brand for the merchant's own. The value reaches Stripe as
 * session branding, so guessing it puts a name the merchant never chose in front
 * of their customers. An empty string is honest — callers can tell it apart and
 * fall back deliberately.
 *
 * Static pure function, same idiom as {@see ShopCurrency} and {@see ShopId}.
 *
 * @since 2.0.0
 */
final class ShopName
{
    public static function of(?object $shop): string
    {
        /** @var mixed $field */
        $field = $shop->oxshops__oxname ?? null;

        /** @var mixed $value */
        $value = is_object($field) ? ($field->value ?? null) : null;

        return is_string($value) ? trim($value) : '';
    }
}
