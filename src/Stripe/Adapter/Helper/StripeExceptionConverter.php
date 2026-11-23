<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Adapter\Helper;

use OxidEsales\PaymentBase\Adapter\Exception\PaymentAdapterException;
use OxidEsales\Payments\Stripe\Core\StripeDefinitions;
use Stripe\Exception\ApiErrorException;

/**
 * Converts Stripe SDK exceptions to PaymentAdapterException.
 *
 * Shared utility for all adapter helpers.
 *
 * @since 2.0.0
 */
class StripeExceptionConverter
{
    public static function convert(ApiErrorException $e): PaymentAdapterException
    {
        // A connection error carries no error object: every read is null-safe.
        $error = $e->getError();

        return new PaymentAdapterException(
            providerName: StripeDefinitions::PROVIDER,
            errorCode: $error->code ?? 'unknown_error',
            message: $error->message ?? $e->getMessage(),
            code: $e->getCode(),
            previous: $e,
            context: [
                'type' => $error?->type,
                'param' => $error?->param,
            ]
        );
    }
}
