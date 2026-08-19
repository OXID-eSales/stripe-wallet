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
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Stripe Radar fraud check service implementation.
 *
 * Sprint 2: Checks Stripe Radar risk score for the PaymentIntent
 * associated with the contract. Binary pass/fail based on threshold.
 *
 * Sprint 31: Returns FraudCheckResponse instead of FraudCheckResult.
 *
 * Sprint 133 · Story 4 (F1): reports honestly. It used to swallow every
 * Throwable and return success(0.0) — maximally clean on FraudCheckResponse's
 * documented 0..1 scale — so a Stripe outage produced contracts stamped
 * "passed: true, score: 0.0" for orders Radar never saw, with nothing logged
 * despite a comment claiming otherwise. Whether an unscreenable order may still
 * proceed is FraudCheckHandler's policy, not this adapter's to fake.
 *
 * Default threshold: 0.7 (scores >= 0.7 fail)
 *
 * @since 1.0.0
 */
class StripeRadarFraudCheckService implements FraudCheckServiceInterface
{
    private const DEFAULT_THRESHOLD = 0.7;

    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly StripeAdapterFactoryInterface $adapterFactory,
        private readonly float $threshold = self::DEFAULT_THRESHOLD,
        ?LoggerInterface $logger = null
    ) {
        $this->logger = $logger ?? new NullLogger();
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
            // Nothing to screen: proceed, but do not claim a clean score.
            return FraudCheckResponse::unscreened('no_payment_intent');
        }

        try {
            $adapter = $this->adapterFactory->getStripeAdapter();
            $riskScore = $adapter->getPaymentIntentRiskScore($paymentIntentId);

            if ($riskScore === null) {
                // Radar does not score every payment method: proceed, but the
                // absence of a score is not evidence of a low one.
                return FraudCheckResponse::unscreened('score_unavailable');
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
            $this->logger->error('Stripe Radar fraud check could not be executed', [
                'contract_id' => $contract->getId(),
                'payment_intent_id' => $paymentIntentId,
                'error' => $e->getMessage(),
            ]);

            return FraudCheckResponse::error($e->getMessage());
        }
    }
}
