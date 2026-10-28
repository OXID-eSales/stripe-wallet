<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\GraphQL\Admin\Service;

/**
 * Stripe's own error messages may quote (a masked form of) the key that was used. A message that leaves the shop
 * through the admin API carries no key and no signing secret.
 */
final class StripeSecretRedactor
{
    private const SECRET_PATTERN = '/\b(?:[sr]k|pk)_(?:test|live)_[A-Za-z0-9*]+|\bwhsec_[A-Za-z0-9*]+/';

    public static function redact(string $message): string
    {
        return (string) preg_replace(self::SECRET_PATTERN, '[redacted]', $message);
    }
}
