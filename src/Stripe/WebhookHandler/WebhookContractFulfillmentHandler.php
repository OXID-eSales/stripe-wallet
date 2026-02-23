<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\WebhookHandler;

use OxidEsales\PaymentComponent\Contract\PaymentContract;
use OxidEsales\PaymentComponent\Contract\PaymentContractInterface;
use OxidEsales\PaymentComponent\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentComponent\Service\ContractFulfillmentServiceInterface;

/**
 * Handles webhook events with contract-awareness.
 *
 * Sprint 6: Contract-Aware Webhook Processing
 * Sprint 18: Uses ContractFulfillmentService for DRY fulfillment
 *
 * This handler bridges Stripe webhooks to the contract state machine by:
 * 1. Looking up contract by providerOrderId (payment intent ID)
 * 2. Delegating fulfillment to ContractFulfillmentService
 * 3. Handling capture/refund amounts
 *
 * @since 1.0.0
 */
class WebhookContractFulfillmentHandler implements WebhookContractFulfillmentHandlerInterface
{
    public function __construct(
        private readonly ContractRepositoryInterface $contractRepository,
        private readonly ContractFulfillmentServiceInterface $contractFulfillmentService
    ) {
    }

    /**
     * @inheritDoc
     */
    public function handlePaymentSucceeded(string $providerOrderId): ?bool
    {
        // Sprint 18: Delegate to ContractFulfillmentService (DRY)
        return $this->contractFulfillmentService->fulfillByProviderOrderId($providerOrderId);
    }

    /**
     * @inheritDoc
     *
     * Sprint 7: Enhanced to handle AUTHORIZED state (manual capture mode).
     * When a charge is captured, transitions AUTHORIZED -> READY_TO_COMMIT.
     */
    public function handleChargeCaptured(string $providerOrderId, float $capturedAmount = 0.0): ?bool
    {
        $contract = $this->findContractByProviderOrderId($providerOrderId);

        if ($contract === null) {
            return null;
        }

        // Idempotency check - already fulfilled (can record amount in this state)
        if ($contract->getState()->isFulfilled()) {
            $this->recordCapturedAmount($contract, $capturedAmount);
            $this->saveIfAmountPositive($contract, $capturedAmount);
            return false;
        }

        // Sprint 7: Handle manual capture mode - AUTHORIZED -> READY_TO_COMMIT
        if ($this->handleAuthorizedCapture($contract)) {
            return true;
        }

        // Validation - must be COMMITTED to fulfill
        if (!$contract->getState()->isCommitted()) {
            return false;
        }

        // Record amount on COMMITTED contract before fulfillment
        $this->recordCapturedAmount($contract, $capturedAmount);

        return $this->contractFulfillmentService->fulfill($contract);
    }

    /**
     * Record captured amount on concrete PaymentContract.
     */
    private function recordCapturedAmount(PaymentContractInterface $contract, float $amount): void
    {
        if ($amount > 0.0 && $contract instanceof PaymentContract) {
            $contract->setCapturedAmount($amount);
            $contract->setCapturedAt(new \DateTimeImmutable());
        }
    }

    /**
     * Handle AUTHORIZED -> READY_TO_COMMIT transition for manual capture.
     */
    private function handleAuthorizedCapture(PaymentContractInterface $contract): bool
    {
        if (!$contract->getState()->isAuthorized() || !$contract instanceof PaymentContract) {
            return false;
        }

        $contract->captureAuthorization();
        $this->contractRepository->save($contract);
        return true;
    }

    /**
     * Save contract if captured amount is positive.
     */
    private function saveIfAmountPositive(PaymentContractInterface $contract, float $amount): void
    {
        if ($amount > 0.0) {
            $this->contractRepository->save($contract);
        }
    }

    /**
     * @inheritDoc
     */
    public function handleChargeRefunded(string $providerOrderId, float $refundAmount): ?bool
    {
        $contract = $this->findContractByProviderOrderId($providerOrderId);

        if ($contract === null) {
            return null;
        }

        // Refunds can only happen on FULFILLED contracts
        if (!$contract->getState()->isFulfilled()) {
            return false;
        }

        // Sprint 8: Record refund amount on contract (accumulates for partial refunds)
        if ($refundAmount > 0.0 && $contract instanceof PaymentContract) {
            $contract->addRefundedAmount($refundAmount);
            $contract->setRefundedAt(new \DateTimeImmutable());
            $this->contractRepository->save($contract);
        }

        return true;
    }

    /**
     * @inheritDoc
     */
    public function handlePaymentFailed(string $providerOrderId, string $failureReason): ?bool
    {
        $contract = $this->findContractByProviderOrderId($providerOrderId);

        if ($contract === null) {
            return null;
        }

        // Can only fail non-terminal contracts
        if ($contract->getState()->isTerminal()) {
            return false;
        }

        // Mark contract as failed using the concrete method
        if ($contract instanceof PaymentContract) {
            $contract->fail($failureReason);
            $this->contractRepository->save($contract);
            return true;
        }

        return false;
    }

    /**
     * @inheritDoc
     *
     * Sprint 1 Bug Fix: Handle payment_intent.canceled webhook event.
     * Previously, this was only logged but contract state was never updated.
     */
    public function handlePaymentCanceled(string $providerOrderId, string $cancellationReason): ?bool
    {
        $contract = $this->findContractByProviderOrderId($providerOrderId);

        if ($contract === null) {
            return null;
        }

        // Can only cancel non-terminal contracts
        if ($contract->getState()->isTerminal()) {
            return false;
        }

        // Mark contract as cancelled using the concrete method
        if ($contract instanceof PaymentContract) {
            $contract->cancel($cancellationReason);
            $this->contractRepository->save($contract);
            return true;
        }

        return false;
    }

    /**
     * @inheritDoc
     *
     * Sprint 1 Bug Fix: Handle checkout.session.expired webhook event.
     * Previously, expired sessions didn't update contract state.
     * Uses EXPIRED state (distinct from CANCELLED) for semantic clarity.
     */
    public function handleSessionExpired(string $contractId): ?bool
    {
        $contract = $this->contractRepository->findById($contractId);

        if ($contract === null) {
            return null;
        }

        // Can only expire non-terminal contracts
        if ($contract->getState()->isTerminal()) {
            return false;
        }

        // Mark contract as expired
        $contract->expire();
        $this->contractRepository->save($contract);
        return true;
    }

    /**
     * Find contract by provider order ID.
     *
     * @param string $providerOrderId Stripe PaymentIntent ID
     */
    private function findContractByProviderOrderId(string $providerOrderId): ?PaymentContractInterface
    {
        return $this->contractRepository->findByProviderOrderId($providerOrderId);
    }
}
