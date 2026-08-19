<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Core;

use RuntimeException;

/**
 * Resolves an OXID shop id to a positive integer, or fails.
 *
 * Sprint 133 · Story 14 (F14): shop id was coerced two different silent ways.
 * `is_numeric($shopId) ? (int) $shopId : 1` (checkout session creation, payment
 * handler) attributed a checkout — and the metadata that later resolves the
 * contract and its order — to shop 1 on an EE multishop install. A bare
 * `(int) $shopAdapter->getShopId()` (transaction audit rows) yielded 0, which is
 * not a shop at all.
 *
 * Shop id is not optional context for a payment, so an unresolvable one throws.
 * Static pure function, same idiom as {@see ShopCurrency} and AmountConverter.
 *
 * @since 2.0.0
 */
final class ShopId
{
    /**
     * @throws RuntimeException when the shop id is missing or not a positive integer
     */
    public static function of(string|int|null $shopId, string $context): int
    {
        if (is_int($shopId) && $shopId >= 1) {
            return $shopId;
        }

        if (is_string($shopId) && $shopId !== '' && ctype_digit($shopId) && (int) $shopId >= 1) {
            return (int) $shopId;
        }

        throw new RuntimeException(sprintf(
            'Unable to resolve the active shop id (%s). Refusing to assume a shop for a payment.',
            $context
        ));
    }
}
