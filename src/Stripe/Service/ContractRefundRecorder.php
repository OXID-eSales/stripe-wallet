<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

use DateTimeImmutable;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Records a refund amount on a PaymentContract.
 *
 * Sprint 114.8: Extracted from D3 duplication across StripeRefundRequestHandler and
 * WebhookContractFulfillmentHandler. Enforces the FULFILLED guard uniformly across all
 * call sites — previously StripeRefundRequestHandler had the guard and WebhookContractFulfillmentHandler
 * also had it. Now a single place owns the rule.
 *
 * Business rule: addRefundedAmount() is only valid on FULFILLED contracts.
 * If the contract is not FULFILLED, the refund already succeeded at the provider level
 * and must not cause an error here — we simply skip the recording.
 *
 * @since 2.0.0
 */
class ContractRefundRecorder
{
    private LoggerInterface $logger;

    public function __construct(
        private readonly ContractRepositoryInterface $contractRepository,
        ?LoggerInterface $logger = null
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Record a refund amount on the contract if it is in FULFILLED state.
     *
     * Non-FULFILLED contracts are silently skipped: the Stripe refund already
     * succeeded and we must not throw, as that would report an error to the caller
     * despite the refund being processed.
     *
     * @param string|null $contractId Optional id hint for structured log context.
     */
    public function record(PaymentContractInterface $contract, float $amount, ?string $contractId = null): void
    {
        if (!$contract->getState()->isFulfilled()) {
            $this->logger->warning('Cannot record refund on contract: not in FULFILLED state', [
                'contractId' => $contractId,
                'state' => $contract->getState()->getValue(),
            ]);
            return;
        }

        $contract->addRefundedAmount($amount);
        $contract->setRefundedAt(new DateTimeImmutable());
        $this->contractRepository->save($contract);
    }
}
