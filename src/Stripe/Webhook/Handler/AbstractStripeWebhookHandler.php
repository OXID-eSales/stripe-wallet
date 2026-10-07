<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Webhook\Handler;

use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Service\Commit\CommitOutcome;
use OxidEsales\PaymentBase\Service\Commit\ContractCommitServiceInterface;
use OxidEsales\PaymentBase\Service\Commit\PaymentConfirmation;
use OxidEsales\PaymentBase\Webhook\WebhookResult;
use OxidEsales\Payments\Stripe\Core\StripeDefinitions;
use OxidEsales\Payments\Stripe\Webhook\StripeWebhookEventHandlerInterface;
use OxidEsales\Payments\Stripe\Webhook\StripeWebhookOutcome;
use OxidEsales\Payments\Stripe\Webhook\StripeWebhookEventParser;
use Psr\Log\LoggerInterface;

/**
 * Base class for Stripe webhook handlers.
 *
 * Provides shared helpers used across multiple handlers:
 * - mapHandlerResult(): maps tri-state ?bool → StripeWebhookOutcome
 * - setContractIdFromProviderOrderId(): resolves contractId from PI lookup
 *
 * @since Sprint 114.4
 */
abstract class AbstractStripeWebhookHandler implements StripeWebhookEventHandlerInterface
{
    /**
     * GRAPH-QL / PS3: the commit service is optional so a services.yaml that
     * predates it keeps the old behaviour (commit only from the return leg).
     */
    public function __construct(
        protected readonly StripeWebhookEventParser $parser,
        protected readonly WebhookContractFulfillmentHandlerInterface $fulfillmentHandler,
        protected readonly ContractRepositoryInterface $contractRepository,
        protected readonly LoggerInterface $logger,
        protected readonly ?ContractCommitServiceInterface $contractCommit = null
    ) {
    }

    /**
     * GRAPH-QL / PS3 — Stripe says paid for a contract that is still open
     * (the browser return leg never came, or has not come yet): commit it
     * through payment-base's ContractCommitService - the same chain the return
     * leg runs - before the fulfilment below. Answers null when there is
     * nothing to do (no service, no contract, already settled, committed
     * now) so the caller continues, or an outcome that ends the handling
     * (commit refused or left pending: the fulfilment must not run).
     *
     * `$requiresCapture` marks a manual-capture authorization (PS6 follow-up):
     * the contract is committed, the order is created but not marked paid
     * (payment-base's ContractCommitmentHandler reads it from the context).
     *
     * @param array<string, mixed> $extraContext
     */
    protected function commitOpenContract(
        ?PaymentContractInterface $contract,
        string $authorizationId,
        string $providerOrderId,
        ?float $amount,
        string $currency,
        array $extraContext = [],
        bool $requiresCapture = false
    ): ?StripeWebhookOutcome {
        if ($this->contractCommit === null || $contract === null) {
            return null;
        }

        $state = $contract->getState();
        if ($state->isCommitted() || $state->isFulfilled() || $state->isTerminal()) {
            return null;
        }

        if ($amount === null || $currency === '') {
            $this->logger->warning('Webhook names a paid contract but no amount; leaving the commit to the return leg', [
                'contract_id' => $contract->getId(),
                'authorization_id' => $authorizationId,
            ]);

            return null;
        }

        $outcome = $this->contractCommit->commit(new PaymentConfirmation(
            contractId: (string) $contract->getId(),
            providerName: StripeDefinitions::PROVIDER,
            authorizationId: $authorizationId,
            providerOrderId: $providerOrderId,
            amount: $amount,
            currency: $currency,
            requiresCapture: $requiresCapture,
            source: 'webhook',
            extraContext: $extraContext,
        ));

        $this->logger->info('Webhook commit of an open contract', [
            'contract_id' => $contract->getId(),
            'outcome' => $outcome->outcome,
            'order_id' => $outcome->orderId,
            'reason' => $outcome->reason,
        ]);

        if ($outcome->isSettled()) {
            return null;
        }

        return StripeWebhookOutcome::of(
            $outcome->outcome === CommitOutcome::PENDING
                ? WebhookResult::skipped('Commit pending: another condition is still open')
                : WebhookResult::failure('commit_refused', 'Commit refused: ' . (string) $outcome->reason),
            (string) $contract->getId()
        );
    }

    /**
     * Map tri-state handler result (true/false/null) to a StripeWebhookOutcome.
     *
     * A non-null result means the handler ran against a real contract — resolve
     * and link its ID for the webhook log row, regardless of whether the action
     * ultimately ran or was state-guard skipped.
     */
    protected function mapHandlerResult(
        ?bool $result,
        string $providerOrderId,
        string $successAction,
        string $skipReason
    ): StripeWebhookOutcome {
        if ($result === null) {
            return StripeWebhookOutcome::of(WebhookResult::skipped('Contract not found'));
        }

        $contractId = $this->resolveContractIdFromProviderOrderId($providerOrderId);

        if ($result === true) {
            return StripeWebhookOutcome::of(WebhookResult::success($successAction), $contractId);
        }

        return StripeWebhookOutcome::of(WebhookResult::skipped($skipReason), $contractId);
    }

    /**
     * Look up the contract ID by providerOrderId (PaymentIntent ID).
     */
    protected function resolveContractIdFromProviderOrderId(string $providerOrderId): ?string
    {
        $contract = $this->contractRepository->findByProviderOrderId($providerOrderId);
        return $contract?->getId();
    }
}
