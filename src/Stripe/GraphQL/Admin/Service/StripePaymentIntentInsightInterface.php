<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\GraphQL\Admin\Service;

/**
 * Asks Stripe about a PaymentIntent. Fails closed: when Stripe cannot be asked the answer says so instead of throwing.
 */
interface StripePaymentIntentInsightInterface
{
    public function factsOf(string $paymentIntentId): PaymentIntentFacts;
}
