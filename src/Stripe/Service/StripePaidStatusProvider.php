<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

use OxidEsales\PaymentBase\Reconciliation\ProviderPaymentStatus;
use OxidEsales\PaymentBase\Reconciliation\ProviderPaymentStatusInterface;
use OxidEsales\Payments\Stripe\Core\StripeDefinitions;
use OxidEsales\Payments\Stripe\Service\Factory\StripeAdapterFactoryInterface;

/**
 * Stripe's answer to payment-base's shared OXPAID reconciliation: is the PaymentIntent paid, and since when.
 *
 * Paid means captured; the capture time is the payment date. When Stripe reports a capture but no time the
 * status says so ({@see ProviderPaymentStatus::paidWithoutTimestamp()}) and the reconciliation skips the order:
 * OXPAID feeds accounting and dunning, a guessed date lands in the wrong period.
 */
final class StripePaidStatusProvider implements ProviderPaymentStatusInterface
{
    public function __construct(private readonly StripeAdapterFactoryInterface $adapterFactory)
    {
    }

    public function provider(): string
    {
        return StripeDefinitions::PROVIDER;
    }

    public function statusOf(string $providerOrderId): ProviderPaymentStatus
    {
        $details = $this->adapterFactory->getStripeAdapter()->getPaymentDetails($providerOrderId);

        if (!$details->isCaptured) {
            return ProviderPaymentStatus::notPaid($details->status);
        }
        if ($details->capturedAt === null) {
            return ProviderPaymentStatus::paidWithoutTimestamp($details->status);
        }

        return ProviderPaymentStatus::paidAt($details->status, \DateTimeImmutable::createFromInterface($details->capturedAt));
    }
}
