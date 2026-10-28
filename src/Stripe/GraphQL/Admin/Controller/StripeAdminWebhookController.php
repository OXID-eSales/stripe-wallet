<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\GraphQL\Admin\Controller;

use OxidEsales\PaymentBase\GraphQL\Admin\Service\PaymentRightsProvider;
use OxidEsales\Payments\Stripe\GraphQL\Admin\DataType\AdminStripeWebhookPayload;
use OxidEsales\Payments\Stripe\GraphQL\Admin\DataType\AdminStripeWebhookStatus;
use OxidEsales\Payments\Stripe\GraphQL\Admin\Service\StripeWebhookAdministratorInterface;
use TheCodingMachine\GraphQLite\Annotations\Mutation;
use TheCodingMachine\GraphQLite\Annotations\Query;
use TheCodingMachine\GraphQLite\Annotations\Right;

/**
 * The webhook part of the Stripe settings: `adminStripeWebhookStatus`, `adminStripeWebhookRegister`,
 * `adminStripeWebhookClearAll`. GraphQL mapping only; step-up and audit are in the service. All three need
 * ADMIN_PAYMENT_CONFIG; register and clear-all also need a step-up, because they change what Stripe delivers where.
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

    /**
     * Registers (or updates) the shop's endpoint at Stripe and stores its id and signing secret. The secret is
     * not part of the answer.
     */
    #[Mutation]
    #[Right(PaymentRightsProvider::CONFIG)]
    public function adminStripeWebhookRegister(): AdminStripeWebhookPayload
    {
        return AdminStripeWebhookPayload::of($this->webhooks->register());
    }

    /**
     * Deletes the endpoints at Stripe that point at this shop's webhook URL and forgets the stored endpoint.
     */
    #[Mutation]
    #[Right(PaymentRightsProvider::CONFIG)]
    public function adminStripeWebhookClearAll(): AdminStripeWebhookPayload
    {
        return AdminStripeWebhookPayload::of($this->webhooks->clearAll());
    }
}
