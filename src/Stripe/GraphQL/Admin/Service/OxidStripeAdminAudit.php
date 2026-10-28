<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\GraphQL\Admin\Service;

use Closure;
use LogicException;
use OxidEsales\GraphQL\Admin\Audit\DataType\AuditTrail;
use OxidEsales\GraphQL\Admin\Audit\Service\AuditRecorder;
use OxidEsales\GraphQL\Admin\Shared\DataType\Payload\PayloadWithErrors;
use OxidEsales\Payments\Stripe\GraphQL\Admin\DataType\AuditedStripeOutcome;

/**
 * The admin module's audit recorder (G05) for the Stripe settings: one row per call, entity `paymentProvider` /
 * `stripe`, the names of the fields the call carried and no value at all. The module is optional: wired with `@?`,
 * and without it the operation fails closed.
 */
final class OxidStripeAdminAudit implements StripeAdminAuditInterface
{
    private const ENTITY = 'paymentProvider';
    private const PROVIDER = 'stripe';

    public function __construct(private readonly ?AuditRecorder $recorder)
    {
    }

    public function audited(string $operationName, array $changedFields, Closure $action): StripeAdminOutcomeInterface
    {
        if ($this->recorder === null) {
            throw new LogicException('The admin API is not installed: there is no audit log to write');
        }
        $outcome = null;
        $this->recorder->auditedAdmin(
            $operationName,
            static function (AuditTrail $trail) use ($changedFields, $action, &$outcome): PayloadWithErrors {
                $trail->on(self::ENTITY, self::PROVIDER)->field(...$changedFields);
                $outcome = $action();

                return new AuditedStripeOutcome($outcome);
            },
        );

        return $outcome ?? throw new LogicException('The audited operation did not run');
    }
}
