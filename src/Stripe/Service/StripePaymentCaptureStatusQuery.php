<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Service\PaymentCaptureStatusQueryInterface;
use OxidEsales\Payments\Stripe\Adapter\StripeStatusMapper;
use OxidEsales\Payments\Stripe\Core\StripeDefinitions;
use OxidEsales\Payments\Stripe\Service\Factory\StripeAdapterFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * STRP-AUTOCAP-REFUND Sprint 06 — Stripe implementation of
 * {@see PaymentCaptureStatusQueryInterface}.
 *
 * Resolves the PaymentIntent for the contract and answers whether
 * the underlying payment has actually moved money, by reading the
 * normalized status returned by the Stripe adapter:
 *
 *   - STATUS_CAPTURED   (Stripe `succeeded`)        → true
 *   - STATUS_AUTHORIZED (Stripe `requires_capture`) → false
 *   - anything else                                 → null
 *
 * Returns null for non-Stripe contracts, contracts without a
 * provider order ID, and any unexpected adapter failure (degraded
 * mode: the listener falls back to its conservative default).
 */
class StripePaymentCaptureStatusQuery implements PaymentCaptureStatusQueryInterface
{
    public function __construct(
        private readonly StripeAdapterFactoryInterface $adapterFactory,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function isPaymentCaptured(PaymentContractInterface $contract): ?bool
    {
        if ($contract->getProvider() !== StripeDefinitions::PROVIDER) {
            return null;
        }

        $providerOrderId = $contract->getProviderOrderId();
        if (!is_string($providerOrderId) || $providerOrderId === '') {
            return null;
        }

        try {
            $details = $this->adapterFactory->getStripeAdapter()->getPaymentDetails($providerOrderId);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'StripePaymentCaptureStatusQuery: failed to query PSP for capture status — '
                . 'falling back to unknown.',
                [
                    'contract_id'       => $contract->getId(),
                    'provider_order_id' => $providerOrderId,
                    'error'             => $e->getMessage(),
                ],
            );
            return null;
        }

        return match ($details->status) {
            StripeStatusMapper::STATUS_CAPTURED   => true,
            StripeStatusMapper::STATUS_AUTHORIZED => false,
            default                               => null,
        };
    }
}
