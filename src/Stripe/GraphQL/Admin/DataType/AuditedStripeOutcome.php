<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\GraphQL\Admin\DataType;

use OxidEsales\GraphQL\Admin\Shared\DataType\Error\AdminError;
use OxidEsales\GraphQL\Admin\Shared\DataType\Payload\PayloadWithErrors;
use OxidEsales\Payments\Stripe\GraphQL\Admin\Service\StripeAdminOutcomeInterface;

/**
 * Tells the audit recorder whether the operation succeeded: a refusal is reported as the error it will be shown as.
 * Not a service (the container never reflects it), so it may implement the admin API's interface; it only
 * exists inside an audited call.
 */
final class AuditedStripeOutcome implements PayloadWithErrors
{
    public function __construct(private readonly StripeAdminOutcomeInterface $outcome)
    {
    }

    /**
     * @return list<AdminError>
     */
    public function errors(): array
    {
        $refusal = $this->outcome->refusal();

        return $refusal === null ? [] : [StripeAdminErrors::from($refusal)];
    }
}
