<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\GraphQL\Admin\Service;

use OxidEsales\PaymentBase\GraphQL\Admin\Contract\PaymentOwnershipProviderInterface;
use OxidEsales\Payments\Stripe\Core\StripeDefinitions;

/**
 * VR-24 H6: tells payment-base which oxpayments rows Stripe owns, so the generic admin
 * API leaves them to the Stripe module's own surface.
 */
final class StripePaymentOwnershipProvider implements PaymentOwnershipProviderInterface
{
    public function providerName(): string
    {
        return StripeDefinitions::PROVIDER;
    }

    public function paymentIds(): array
    {
        return [StripeDefinitions::STRIPE_WALLET_PAYMENT_ID];
    }
}
