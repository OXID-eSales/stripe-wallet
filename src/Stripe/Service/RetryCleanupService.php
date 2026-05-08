<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

use OxidEsales\PaymentBase\Adapter\ShopOrderServiceInterface;
use OxidEsales\PaymentBase\Contract\PaymentContract;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;

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
    public function __construct(
        private readonly ContractRepositoryInterface $contractRepository,
        private readonly ShopOrderServiceInterface $orderService
    ) {
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
     * @return int Number of contracts cleaned up
     */
    public function cleanupStaleContracts(int $minutesOld): int
    {
        $staleContracts = $this->contractRepository->findStaleNotFinished($minutesOld);
        $cleaned = 0;

        foreach ($staleContracts as $contract) {
            if ($this->cancelContractAndDeleteOrder($contract)) {
                $cleaned++;
            }
        }

        return $cleaned;
    }

    private function cancelContractAndDeleteOrder(?\OxidEsales\PaymentBase\Contract\PaymentContractInterface $contract): bool
    {
        if (!$contract instanceof PaymentContract) {
            return false;
        }

        if ($contract->getState()->isTerminal() || $contract->getState()->isCommitted()) {
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
}
