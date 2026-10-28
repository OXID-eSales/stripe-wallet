<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\GraphQL\Admin\Service;

/**
 * The text the admin API shows for a pair of keys that do not work together. The classic message quotes the first
 * characters of both keys' account part; the API says only that the accounts differ.
 */
final class StripeKeyPairMessage
{
    public const DIFFERENT_ACCOUNTS = 'The publishable key and the secret key belong to different Stripe accounts';

    public static function from(?string $validatorError): ?string
    {
        if ($validatorError !== null && str_contains($validatorError, 'different Stripe accounts')) {
            return self::DIFFERENT_ACCOUNTS;
        }

        return $validatorError;
    }
}
