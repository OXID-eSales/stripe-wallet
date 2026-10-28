<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\GraphQL\Admin\Service;

use Closure;

/**
 * Runs an admin operation on the Stripe settings so that exactly one audit row records it, whatever the outcome.
 */
interface StripeAdminAuditInterface
{
    /**
     * @template T of StripeAdminOutcomeInterface
     * @param list<string>   $changedFields names only; values are never recorded
     * @param Closure(): T   $action
     * @return T
     */
    public function audited(string $operationName, array $changedFields, Closure $action): StripeAdminOutcomeInterface;
}
