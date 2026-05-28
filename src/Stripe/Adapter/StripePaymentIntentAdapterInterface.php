<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Adapter;

use OxidEsales\Payments\Stripe\Adapter\Dto\StripePaymentIntentDto;

/**
 * Stripe PaymentIntent operations.
 *
 * Sprint 46: ISP split from StripeAdapterInterface.
 * Sprint 114.10b: return types flipped to DTOs (A1 boundary fix).
 *
 * @since 2.0.0
 */
interface StripePaymentIntentAdapterInterface
{
    /**
     * @param array<string> $expand
     */
    public function retrievePaymentIntent(string $paymentIntentId, array $expand = []): StripePaymentIntentDto;

    public function cancelPaymentIntent(string $paymentIntentId, ?string $cancellationReason = null): StripePaymentIntentDto;

    public function getPaymentIntentRiskScore(string $paymentIntentId): ?float;
}
