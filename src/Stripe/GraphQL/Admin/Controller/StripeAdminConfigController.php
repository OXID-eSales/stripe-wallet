<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\GraphQL\Admin\Controller;

use OxidEsales\PaymentBase\GraphQL\Admin\Service\PaymentRightsProvider;
use OxidEsales\Payments\Stripe\GraphQL\Admin\DataType\AdminStripeConfig;
use OxidEsales\Payments\Stripe\GraphQL\Admin\Service\StripeAdminSettingsInterface;
use TheCodingMachine\GraphQLite\Annotations\Query;
use TheCodingMachine\GraphQLite\Annotations\Right;

/**
 * The Stripe settings screen of the admin: `adminStripeConfig`. GraphQL mapping only; the rules, step-up and audit
 * are in the services. #[Right] without #[Logged], like every admin operation, so that a refusal carries an
 * errorCode. Every operation needs ADMIN_PAYMENT_CONFIG (payment-base's right for provider settings).
 */
final class StripeAdminConfigController
{
    public function __construct(private readonly StripeAdminSettingsInterface $settings)
    {
    }

    /**
     * The Stripe settings; secrets only as the `*Set` flags.
     */
    #[Query]
    #[Right(PaymentRightsProvider::CONFIG)]
    public function adminStripeConfig(): AdminStripeConfig
    {
        return new AdminStripeConfig($this->settings->snapshot());
    }
}
