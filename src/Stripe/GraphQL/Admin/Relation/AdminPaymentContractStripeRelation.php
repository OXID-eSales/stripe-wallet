<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\GraphQL\Admin\Relation;

use OxidEsales\PaymentBase\GraphQL\Admin\DataType\AdminPaymentContract;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\PaymentRightsProvider;
use OxidEsales\Payments\Stripe\GraphQL\Admin\DataType\AdminStripePaymentDetails;
use OxidEsales\Payments\Stripe\GraphQL\Admin\Service\StripePaymentDetailsReaderInterface;
use TheCodingMachine\GraphQLite\Annotations\ExtendType;
use TheCodingMachine\GraphQLite\Annotations\Field;
use TheCodingMachine\GraphQLite\Annotations\Right;

/**
 * Adds `stripe` to payment-base's `AdminPaymentContract`: the Stripe facts of the payment (intent, charge, status,
 * dashboard link). Null for a contract of another provider. Read-only, behind the same right as the Payment tab
 * itself (ADMIN_PAYMENT_VIEW).
 *
 * Mapped by NamespaceMapper only when the admin API and payment-base's admin payment API are installed; never
 * implements or extends anything of those optional packages (the container reflects the class at module activation).
 */
#[ExtendType(class: AdminPaymentContract::class)]
final class AdminPaymentContractStripeRelation
{
    public function __construct(private readonly StripePaymentDetailsReaderInterface $details)
    {
    }

    #[Field(name: 'stripe')]
    #[Right(PaymentRightsProvider::VIEW)]
    public function stripe(AdminPaymentContract $contract): ?AdminStripePaymentDetails
    {
        $details = $this->details->find($contract->contractId());

        return $details === null ? null : new AdminStripePaymentDetails($details);
    }
}
