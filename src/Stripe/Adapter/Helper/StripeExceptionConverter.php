<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Adapter\Helper;

use OxidEsales\PaymentComponent\Adapter\Exception\PaymentAdapterException;
use Stripe\Exception\ApiErrorException;

/**
 * Converts Stripe SDK exceptions to PaymentAdapterException.
 *
 * Sprint 46: Shared utility for all adapter helpers.
 *
 * @since 2.0.0
 */
final class StripeExceptionConverter
{
    public static function convert(ApiErrorException $e): PaymentAdapterException
    {
        $errorCode = $e->getError()->code ?? 'unknown_error';
        $message = $e->getError()->message ?? $e->getMessage();

        return new PaymentAdapterException(
            providerName: 'stripe',
            errorCode: $errorCode,
            message: $message,
            code: $e->getCode(),
            previous: $e,
            context: [
                'type' => $e->getError()->type,
                'param' => $e->getError()->param,
            ]
        );
    }
}
