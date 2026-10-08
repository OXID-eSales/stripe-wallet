<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

use OxidEsales\PaymentBase\Adapter\ShopOrderServiceInterface;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\Payments\Stripe\Core\AmountConverter;
use OxidEsales\Payments\Stripe\Core\StripeDefinitions;
use OxidEsales\PaymentBase\Service\Commit\PaymentConfirmation;
use OxidEsales\PaymentBase\Service\Commit\ContractCommitServiceInterface;
use OxidEsales\Payments\Stripe\Service\CheckoutInFlightGuard;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Handles cleanup of previous checkout attempts on retry.
 *
 * When a user navigates back from Stripe payment page and retries,
 * this service cancels the previous contract and deletes the NOT_FINISHED order.
 *
 * @since 2.0.0 STRP-100
 */
class RetryCleanupService
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly ContractRepositoryInterface $contractRepository,
        private readonly ShopOrderServiceInterface $orderService,
        ?LoggerInterface $logger = null,
        private readonly ?CheckoutInFlightGuard $inFlightGuard = null,
        // GRAPH-QL / PS3: a session Stripe reports as paid is committed, not cancelled.
        private readonly ?ContractCommitServiceInterface $contractCommit = null
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Clean up a previous checkout attempt by contract ID.
     *
     * 1. Load contract, verify it's in a non-terminal, non-committed state
     * 2. If contract has an orderId, delete the NOT_FINISHED order
     * 3. Cancel the contract with reason 'checkout_retry'
     */
    public function cleanupPreviousAttempt(?string $contractId): bool
    {
        if ($contractId === null) {
            return false;
        }

        $contract = $this->contractRepository->findById($contractId);

        return $this->cancelContractAndDeleteOrder($contract);
    }

    /**
     * Clean up any dangling checkout attempt for a user.
     *
     * Covers the case where the user closed the browser/tab (no cancel redirect,
     * session lost) and comes back later with a new session. Looks up the most
     * recent active contract by userId.
     */
    public function cleanupForUser(string $userId): bool
    {
        $contract = $this->contractRepository->findActiveByUserId($userId);

        return $this->cancelContractAndDeleteOrder($contract);
    }

    /**
     * Clean up stale NOT_FINISHED contracts older than the given threshold.
     *
     * Called after webhook processing to garbage-collect abandoned checkouts
     * (e.g. user hit browser back and never retried).
     *
     * STRP-168 item 4: $limit bounds one pass. The sweep runs on the webhook
     * request after its answer has left (the controller releases the client
     * first), so a backlog costs worker time, not response time - but one
     * pass is still bounded so a worker is not tied up indefinitely. Whatever
     * is left over is picked up by the next webhook, or by
     * oe:payments:not_finished:cleanup.
     *
     * @param int|null $limit cap the batch, or null for no cap
     *
     * @return int Number of contracts cleaned up
     */
    public function cleanupStaleContracts(int $minutesOld, ?int $limit = null): int
    {
        $staleContracts = $this->contractRepository->findStaleNotFinished($minutesOld, $limit);
        $cleaned = 0;

        foreach ($staleContracts as $contract) {
            if ($this->cancelContractAndDeleteOrder($contract)) {
                $cleaned++;
            }
        }

        return $cleaned;
    }

    /**
     * Sprint 133 · Story 15 (F15): this narrowed to the concrete PaymentContract
     * and returned a bare false for a type mismatch, so a contract that could
     * never satisfy the guard was silently re-fetched after every webhook and
     * never cleaned or reported. Every method used here — getState(), getOrderId(),
     * cancel() — is on PaymentContractInterface, so the narrowing was an
     * unnecessary DIP break; it is gone, and a genuine skip is now logged rather
     * than being indistinguishable from "nothing to do".
     */
    private function cancelContractAndDeleteOrder(?PaymentContractInterface $contract): bool
    {
        if ($contract === null) {
            return false;
        }

        if ($contract->getState()->isTerminal() || $contract->getState()->isCommitted()) {
            $this->logger->info('Checkout retry cleanup skipped: contract is already settled', [
                'contract_id' => $contract->getId(),
                'state' => $contract->getStateValue(),
            ]);

            return false;
        }

        // GRAPH-QL / PS3: ask Stripe BEFORE anything is deleted. Until
        // 2026-10-06 the order was deleted first and the in-flight guard asked
        // afterwards - and the guard answers null for a paid session, so a
        // shopper who paid but never reached the return leg lost their order
        // (money taken, no order). A paid session is committed; a usable unpaid
        // one is kept; only then may the attempt be retired.
        if ($this->settlePaidSession($contract)) {
            return false;
        }

        // Not every previous attempt is stale. The OPC checkout API prepares a
        // Stripe session before the customer reaches the payment or order page,
        // and cancelling it on the next page render meant the session they were
        // about to pay in was thrown away and replaced — several times per
        // checkout. Keep it while it still matches the basket and nobody has
        // paid it; the guard answers null whenever it cannot tell, which leaves
        // the old cancel-always behaviour in place.
        if ($this->inFlightGuard?->inspect($contract) !== null) {
            $this->logger->info('Keeping the checkout in flight instead of cancelling it', [
                'contractId' => $contract->getId(),
            ]);

            return false;
        }

        $orderId = $contract->getOrderId();
        if ($orderId !== null) {
            $this->orderService->deleteNotFinishedOrder($orderId);
        }

        $contract->cancel('checkout_retry');
        $this->contractRepository->save($contract);

        return true;
    }

    /**
     * True when Stripe reports the contract's session as paid: the contract is
     * then committed through payment-base's ContractCommitService (or, without
     * one wired, left alone and logged) and must never be cancelled here.
     */
    private function settlePaidSession(PaymentContractInterface $contract): bool
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
