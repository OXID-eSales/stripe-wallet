<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

use OxidEsales\PaymentBase\Adapter\Response\FraudCheckResponse;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Service\FraudCheckServiceInterface;
use OxidEsales\Payments\Stripe\Service\Factory\StripeAdapterFactoryInterface;

/**
 * Stripe Radar fraud check service implementation.
 *
 * Sprint 2: Checks Stripe Radar risk score for the PaymentIntent
 * associated with the contract. Binary pass/fail based on threshold.
 *
 * Sprint 31: Returns FraudCheckResponse instead of FraudCheckResult.
 *
 * Default threshold: 0.7 (scores >= 0.7 fail)
 *
 * @since 1.0.0
 */
class StripeRadarFraudCheckService implements FraudCheckServiceInterface
{
    private const DEFAULT_THRESHOLD = 0.7;

    public function __construct(
        private readonly StripeAdapterFactoryInterface $adapterFactory,
        private readonly float $threshold = self::DEFAULT_THRESHOLD
    ) {
    }

    /**
     * Check fraud score for a contract via Stripe Radar.
     *
     * Retrieves the PaymentIntent from contract metadata and checks
     * the Stripe Radar risk score.
     */
    public function check(PaymentContractInterface $contract): FraudCheckResponse
    {
        $paymentIntentId = $contract->getMetadata('stripe_payment_intent_id');

        if ($paymentIntentId === null || !is_string($paymentIntentId)) {
            // No PaymentIntent associated - pass by default
            return FraudCheckResponse::success(0.0);
        }

        try {
            $adapter = $this->adapterFactory->getStripeAdapter();
            $riskScore = $adapter->getPaymentIntentRiskScore($paymentIntentId);

            if ($riskScore === null) {
                // No risk score available - pass by default
                return FraudCheckResponse::success(0.0);
            }

            if ($riskScore >= $this->threshold) {
                return FraudCheckResponse::failure(
                    $riskScore,
                    sprintf(
                        'Stripe Radar risk score %.2f exceeds threshold %.2f',
                        $riskScore,
                        $this->threshold
                    )
                );
            }

            return FraudCheckResponse::success($riskScore);
        } catch (\Throwable $e) {
            // On error, pass by default to not block legitimate transactions
            // Log the error for debugging
            return FraudCheckResponse::success(0.0);
        }
    }
}
