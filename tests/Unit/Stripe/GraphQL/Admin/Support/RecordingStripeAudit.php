<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\GraphQL\Admin\Support;

use Closure;
use OxidEsales\Payments\Stripe\GraphQL\Admin\Service\StripeAdminAuditInterface;
use OxidEsales\Payments\Stripe\GraphQL\Admin\Service\StripeAdminOutcomeInterface;
use Throwable;

final class RecordingStripeAudit implements StripeAdminAuditInterface
{
    /** @var list<array{operation: string, fields: list<string>, outcome: string}> */
    public array $rows = [];

    public function audited(string $operationName, array $changedFields, Closure $action): StripeAdminOutcomeInterface
    {
        try {
            $result = $action();
        } catch (Throwable $thrown) {
            $this->rows[] = ['operation' => $operationName, 'fields' => $changedFields, 'outcome' => $thrown::class];
            throw $thrown;
        }
        $this->rows[] = ['operation' => $operationName, 'fields' => $changedFields, 'outcome' => $result->refusal()?->code ?? 'ok'];

        return $result;
    }
}
