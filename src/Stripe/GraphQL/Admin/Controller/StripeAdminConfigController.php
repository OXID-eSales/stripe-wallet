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
 * The Stripe settings as the admin reads them: `adminStripeConfig`. Read-only: the settings change in the classic
 * admin only (CFG-1), there is no mutation. GraphQL mapping only. #[Right] without #[Logged], like every admin operation, so
 * that a refusal carries an errorCode. The query needs ADMIN_PAYMENT_CONFIG (payment-base's right for
 * provider settings).
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
