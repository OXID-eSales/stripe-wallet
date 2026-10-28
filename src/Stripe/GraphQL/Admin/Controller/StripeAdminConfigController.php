<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\GraphQL\Admin\Controller;

use OxidEsales\PaymentBase\GraphQL\Admin\Service\AdminSuperadminCheckInterface;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\PaymentRightsProvider;
use OxidEsales\Payments\Stripe\GraphQL\Admin\DataType\AdminStripeConfig;
use OxidEsales\Payments\Stripe\GraphQL\Admin\DataType\AdminStripeConfigInput;
use OxidEsales\Payments\Stripe\GraphQL\Admin\DataType\AdminStripeConfigPayload;
use OxidEsales\Payments\Stripe\GraphQL\Admin\Service\StripeAdminSettingsInterface;
use OxidEsales\Payments\Stripe\GraphQL\Admin\Service\StripeConfigModifierInterface;
use TheCodingMachine\GraphQLite\Annotations\Mutation;
use TheCodingMachine\GraphQLite\Annotations\Query;
use TheCodingMachine\GraphQLite\Annotations\Right;

/**
 * The Stripe settings screen of the admin: `adminStripeConfig`, `adminStripeConfigModify`. GraphQL mapping only;
 * the rules, step-up and audit are in the services. #[Right] without #[Logged], like every admin operation, so
 * that a refusal carries an errorCode. Every operation needs ADMIN_PAYMENT_CONFIG (payment-base's right for
 * provider settings).
 */
final class StripeAdminConfigController
{
    public function __construct(
        private readonly StripeAdminSettingsInterface $settings,
        private readonly StripeConfigModifierInterface $modifier,
        private readonly AdminSuperadminCheckInterface $superadmin,
    ) {
    }

    /**
     * The Stripe settings; secrets only as the `*Set` flags.
     */
    #[Query]
    #[Right(PaymentRightsProvider::CONFIG)]
    public function adminStripeConfig(): AdminStripeConfig
    {
        return new AdminStripeConfig($this->settings->snapshot(), $this->superadmin->isSuperadmin());
    }

    /**
     * Changes settings. Secrets are write-only (empty = unchanged) and the superadmin's alone (`superadmin_required`);
     * switching to live and writing a secret need a step-up; a publishable key and a secret key of different accounts are refused at the field.
     */
    #[Mutation]
    #[Right(PaymentRightsProvider::CONFIG)]
    public function adminStripeConfigModify(AdminStripeConfigInput $input): AdminStripeConfigPayload
    {
        return AdminStripeConfigPayload::of($this->modifier->modify($input->toChange()), $this->superadmin->isSuperadmin());
    }
}
