<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\GraphQL\Admin\Controller;

use OxidEsales\PaymentBase\GraphQL\Admin\Service\PaymentRightsProvider;
use OxidEsales\Payments\Stripe\GraphQL\Admin\DataType\AdminStripeWebhookStatus;
use OxidEsales\Payments\Stripe\GraphQL\Admin\Service\StripeWebhookAdministratorInterface;
use TheCodingMachine\GraphQLite\Annotations\Query;
use TheCodingMachine\GraphQLite\Annotations\Right;

/**
 * The webhook part of the Stripe settings, read-only: `adminStripeWebhookStatus`. Registering and clearing the
 * endpoints happen in the classic admin only (CFG-1). GraphQL mapping only; the query needs ADMIN_PAYMENT_CONFIG.
 */
final class StripeAdminWebhookController
{
    public function __construct(private readonly StripeWebhookAdministratorInterface $webhooks)
    {
    }

    #[Query]
    #[Right(PaymentRightsProvider::CONFIG)]
    public function adminStripeWebhookStatus(): AdminStripeWebhookStatus
    {
        return new AdminStripeWebhookStatus($this->webhooks->status());
    }
}
