<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\GraphQL\Admin\DataType;

use OxidEsales\Payments\Stripe\GraphQL\Admin\Service\StripePaymentDetails;
use TheCodingMachine\GraphQLite\Annotations\Field;
use TheCodingMachine\GraphQLite\Annotations\Type;

/**
 * The Stripe facts of the payment of an order (`AdminPaymentContract.stripe`). Read-only; no key, no raw payload.
 * `intentStatus` and `chargeId` come from Stripe live: when Stripe cannot be asked they are null and `apiError` says
 * why, the rest stays.
 */
#[Type(name: 'AdminStripePaymentDetails')]
final class AdminStripePaymentDetails
{
    public function __construct(private readonly StripePaymentDetails $details)
    {
    }

    /** `pi_...`: the PaymentIntent the order was paid with; null while the order has none. */
    #[Field]
    public function paymentIntentId(): ?string
    {
        return $this->details->paymentIntentId;
    }

    /** `ch_...`: the latest charge of the intent. */
    #[Field]
    public function chargeId(): ?string
    {
        return $this->details->chargeId;
    }

    /** The reference checkout stored on the contract (a PaymentIntent or Checkout Session id). */
    #[Field]
    public function externalTransactionId(): ?string
    {
        return $this->details->externalTransactionId;
    }

    /** Stripe's status of the intent, e.g. `requires_capture`, `succeeded`. */
    #[Field]
    public function intentStatus(): ?string
    {
        return $this->details->intentStatus;
    }

    /** The payment in Stripe's Dashboard (test or live, as the shop's mode). */
    #[Field]
    public function dashboardUrl(): ?string
    {
        return $this->details->dashboardUrl;
    }

    /** Why Stripe could not be asked; null when it was. A short message, never a payload. */
    #[Field]
    public function apiError(): ?string
    {
        return $this->details->apiError;
    }
}
