<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Webhook\Handler;

use Closure;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\LateMoney\LateMoneyResolverInterface;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Service\Commit\CommitOutcome;
use OxidEsales\PaymentBase\Service\Commit\ContractCommitServiceInterface;
use OxidEsales\PaymentBase\Service\Commit\PaymentConfirmation;
use OxidEsales\PaymentBase\Webhook\WebhookEvent;
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
     * VR-23 T3 (S-2 / L-2) - applies a terminal transition (cancel, fail) to the contract a PaymentIntent belongs to:
     * the one indexed by the intent id, else the one the intent's metadata names (a Checkout Session contract still
     * carries the session id as its provider order id), and only while the event is of its current attempt.
     * A contract that is not found is a skipped event, never an error.
     *
     * @param Closure(string): ?bool $apply the fulfilment handler's transition for a provider order id
     */
    protected function applyToContractOfPaymentIntent(
        WebhookEvent $event,
        string $paymentIntentId,
        Closure $apply,
        string $successAction,
        string $skipReason
    ): StripeWebhookOutcome {
        $result = $apply($paymentIntentId);
        if ($result !== null) {
            return $this->mapHandlerResult($result, $paymentIntentId, $successAction, $skipReason);
        }

        $providerOrderId = $this->providerOrderIdNamedByMetadata($event);
        if ($providerOrderId === null) {
            return $this->mapHandlerResult(null, $paymentIntentId, $successAction, $skipReason);
        }

        return $this->mapHandlerResult($apply($providerOrderId), $providerOrderId, $successAction, $skipReason);
    }

    /**
     * The contract of the current attempt a PaymentIntent belongs to: indexed by the intent id, or named by the
     * intent's metadata (a Checkout Session contract still carries the session id as its provider order id).
     */
    protected function contractOfPaymentIntent(WebhookEvent $event, string $paymentIntentId): ?PaymentContractInterface
    {
        $contract = $this->contractRepository->findByProviderOrderId($paymentIntentId);
        if ($contract !== null) {
            return $contract;
        }

        $contractId = $this->parser->extractContractIdFromMetadata($event);

        return $contractId !== null ? $this->contractOfCurrentAttempt($event, $contractId) : null;
    }

    private function providerOrderIdNamedByMetadata(WebhookEvent $event): ?string
    {
        $contractId = $this->parser->extractContractIdFromMetadata($event);
        $contract = $contractId === null ? null : $this->contractOfCurrentAttempt($event, $contractId);
        $providerOrderId = $contract?->getProviderOrderId();

        return $providerOrderId === null || $providerOrderId === '' ? null : $providerOrderId;
    }

    /**
     * Look up the contract ID by providerOrderId (PaymentIntent ID).
     */
    /**
     * OPC-175 — the contract the event's metadata names, but only when the event
     * belongs to the contract's CURRENT provider attempt. The customer may have
     * changed the payment method: the same contract then continues with another
     * attempt, and an event of the replaced Stripe session (expiry, a late
     * payment) must not touch it. Logged as an error: a late payment is money.
     */
    protected function contractOfCurrentAttempt(WebhookEvent $event, string $contractId): ?PaymentContractInterface
    {
        $contract = $this->contractRepository->findById($contractId);
        if ($contract === null || $this->isOfReplacedAttempt($event, $contract)) {
            return null;
        }

        return $contract;
    }

    /**
     * VR-23 T5 (OPC-175 / S2g) - a payment that lands on a contract that can no longer take it: an attempt the
     * customer replaced, or a contract the shop already cancelled, expired or failed (the stale-order cleanup, an
     * admin). payment-base's LateMoneyResolver gives the money back (refund, or release of an authorization) once;
     * the answer is 200, Stripe must not retry it. Null when the payment belongs to a contract that can take it.
     */
    protected function resolveLateMoney(
        WebhookEvent $event,
        string $paymentIntentId,
        LateMoneyResolverInterface $lateMoney
    ): ?StripeWebhookOutcome {
        $contract = $this->contractOfPayment($event, $paymentIntentId);
        if ($contract === null || !$this->cannotTakeMoney($event, $contract)) {
            return null;
        }

        $outcome = $lateMoney->resolve($contract, $paymentIntentId);

        return StripeWebhookOutcome::of(
            WebhookResult::skipped('Late payment on a contract that cannot take it: ' . $outcome->kind->value),
            (string) $contract->getId()
        );
    }

    /**
     * The contract a PaymentIntent belongs to, whatever its attempt: indexed by the intent id, or named by the
     * metadata Stripe echoes (a Checkout Session contract still carries the session id as its provider order id).
     */
    private function contractOfPayment(WebhookEvent $event, string $paymentIntentId): ?PaymentContractInterface
    {
        $contract = $this->contractRepository->findByProviderOrderId($paymentIntentId);
        if ($contract !== null) {
            return $contract;
        }

        $contractId = $this->parser->extractContractIdFromMetadata($event);

        return $contractId === null ? null : $this->contractRepository->findById($contractId);
    }

    private function cannotTakeMoney(WebhookEvent $event, PaymentContractInterface $contract): bool
    {
        $state = $contract->getState();

        return $state->isCancelled() || $state->isExpired() || $state->isFailed() || $this->isOfReplacedAttempt($event, $contract);
    }

    protected function isOfReplacedAttempt(WebhookEvent $event, PaymentContractInterface $contract): bool
    {
        if ($this->parser->isForCurrentAttempt($event, $contract)) {
            return false;
        }

        $this->logger->error('Stripe event of a replaced checkout attempt not applied to its contract', [
            'contract_id' => $contract->getId(),
            'event_type' => $event->type,
            'object_id' => $event->getObjectId(),
        ]);

        return true;
    }

    protected function resolveContractIdFromProviderOrderId(string $providerOrderId): ?string
    {
        $contract = $this->contractRepository->findByProviderOrderId($providerOrderId);
        return $contract?->getId();
    }
}
