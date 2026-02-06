<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Adapter;

use Stripe\PaymentIntent;

/**
 * Stripe PaymentIntent operations.
 *
 * Sprint 46: ISP split from StripeAdapterInterface.
 *
 * @since 2.0.0
 */
interface StripePaymentIntentAdapterInterface
{
    /**
     * @param array<string> $expand
     */
    public function retrievePaymentIntent(string $paymentIntentId, array $expand = []): PaymentIntent;

    public function cancelPaymentIntent(string $paymentIntentId, ?string $cancellationReason = null): PaymentIntent;

    public function getPaymentIntentRiskScore(string $paymentIntentId): ?float;
}
