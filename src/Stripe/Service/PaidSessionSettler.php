<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Service\Commit\ContractCommitServiceInterface;
use OxidEsales\PaymentBase\Service\Commit\PaymentConfirmation;
use OxidEsales\Payments\Stripe\Core\AmountConverter;
use OxidEsales\Payments\Stripe\Core\StripeDefinitions;
use Psr\Log\LoggerInterface;

/**
 * Answers "is this attempt already money?" before anybody cancels it.
 *
 * GRAPH-QL / PS3 put this question into RetryCleanupService. OPC-175 moved it
 * here because payment-base's shared retire (EarlyOrderCreationHandler, via
 * StripeAwareAttemptCleaner) must ask it too, now that the one-page checkout
 * retires the previous attempt of any provider.
 *
 * Not final: StripeAwareAttemptCleaner's unit test replaces it.
 *
 * @since OPC-175
 */
class PaidSessionSettler
{
    public function __construct(
        private readonly ?CheckoutInFlightGuard $inFlightGuard,
        private readonly ?ContractCommitServiceInterface $contractCommit,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * True when Stripe reports the contract's session as paid: the contract is
     * then committed through payment-base's ContractCommitService (or, without
     * one wired, left alone and logged) and must never be cancelled. False when
     * the session is unpaid or Stripe cannot answer - the caller carries on.
     */
    public function settle(PaymentContractInterface $contract): bool
    {
        $session = $this->inFlightGuard?->sessionOf($contract);
        if ($session === null || $session->paymentStatus !== 'paid') {
            return false;
        }

        if ($this->contractCommit === null) {
            $this->logger->error('Stale checkout has a PAID Stripe session and no commit service; left untouched', [
                'contractId' => $contract->getId(),
                'sessionId' => $session->id,
                'paymentIntentId' => $session->paymentIntentId,
            ]);

            return true;
        }

        $currency = strtoupper($session->currency);
        $outcome = $this->contractCommit->commit(new PaymentConfirmation(
            contractId: (string) $contract->getId(),
            providerName: StripeDefinitions::PROVIDER,
            authorizationId: $session->paymentIntentId,
            providerOrderId: $session->id,
            amount: AmountConverter::toMajorUnits($session->amountTotal, $currency),
            currency: $currency,
            requiresCapture: false,
            source: 'cleanup',
            extraContext: ['checkoutSessionId' => $session->id],
        ));

        $this->logger->info('Stale checkout had a paid Stripe session; committed instead of cancelled', [
            'contractId' => $contract->getId(),
            'sessionId' => $session->id,
            'outcome' => $outcome->outcome,
            'orderId' => $outcome->orderId,
            'reason' => $outcome->reason,
        ]);

        return true;
    }
}
