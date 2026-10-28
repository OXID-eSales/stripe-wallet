<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\GraphQL\Admin\DataType;

use OxidEsales\GraphQL\Admin\Shared\DataType\Error\AdminError;
use OxidEsales\GraphQL\Admin\Shared\DataType\Error\ConflictError;
use OxidEsales\GraphQL\Admin\Shared\DataType\Error\ValidationError;
use OxidEsales\Payments\Stripe\GraphQL\Admin\Service\StripeAdminRefusal;

/**
 * Turns a refusal of the service layer into the admin API's error types.
 */
final class StripeAdminErrors
{
    public static function from(StripeAdminRefusal $refusal): AdminError
    {
        if ($refusal->kind === StripeAdminRefusal::CONFLICT) {
            return new ConflictError($refusal->expectedVersion, $refusal->actualVersion, $refusal->message);
        }

        return new ValidationError($refusal->field, $refusal->code, $refusal->message);
    }
}
