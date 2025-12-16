<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Stripe\Handler;

use OxidSolutionCatalysts\Payments\Component\Contract\PaymentContract;
use OxidSolutionCatalysts\Payments\Component\Contract\PaymentContractInterface;
use OxidSolutionCatalysts\Payments\Component\Repository\ContractRepositoryInterface;
use OxidSolutionCatalysts\Payments\Component\Service\ContractFulfillmentServiceInterface;

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

        // Sprint 8: Record captured amount on contract
        if ($capturedAmount > 0.0 && $contract instanceof PaymentContract) {
            $contract->setCapturedAmount($capturedAmount);
            $contract->setCapturedAt(new \DateTimeImmutable());
        }

        // Idempotency check - already fulfilled
        if ($contract->getState()->isFulfilled()) {
            // Still save the captured amount if it changed
            if ($capturedAmount > 0.0) {
                $this->contractRepository->save($contract);
            }
            return false;
        }

        // Sprint 7: Handle manual capture mode - AUTHORIZED -> READY_TO_COMMIT
        if ($contract->getState()->isAuthorized() && $contract instanceof PaymentContract) {
            $contract->captureAuthorization();
            $this->contractRepository->save($contract);
            // After capture, contract is READY_TO_COMMIT
            // Continue to check if we can fulfill (need COMMITTED state)
            // For now, just return true as capture was successful
            return true;
        }

        // Validation - must be COMMITTED to fulfill
        if (!$contract->getState()->isCommitted()) {
            // Save captured amount even if not ready to fulfill
            if ($capturedAmount > 0.0) {
                $this->contractRepository->save($contract);
            }
            return false;
        }

        // Sprint 18: Use ContractFulfillmentService for DRY fulfillment
        // Note: capturedAmount is already set on contract above
        return $this->contractFulfillmentService->fulfill($contract);
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
     * Find contract by provider order ID.
     *
     * @param string $providerOrderId Stripe PaymentIntent ID
     */
    private function findContractByProviderOrderId(string $providerOrderId): ?PaymentContractInterface
    {
        return $this->contractRepository->findByProviderOrderId($providerOrderId);
    }
}
