<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Stripe\Handler;

use OxidSolutionCatalysts\Payments\Component\Contract\PaymentContract;
use OxidSolutionCatalysts\Payments\Component\Contract\PaymentContractInterface;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\Contract\ContractFulfilledEvent;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\EventContext;
use OxidSolutionCatalysts\Payments\Component\EventSystem\EventDispatcherInterface;
use OxidSolutionCatalysts\Payments\Component\Repository\ContractRepositoryInterface;

/**
 * Handles webhook events with contract-awareness.
 *
 * Sprint 6: Contract-Aware Webhook Processing
 *
 * This handler bridges Stripe webhooks to the contract state machine by:
 * 1. Looking up contract by providerOrderId (payment intent ID)
 * 2. Validating contract state (must be COMMITTED for fulfillment)
 * 3. Transitioning contract to FULFILLED
 * 4. Dispatching ContractFulfilledEvent
 *
 * @since 1.0.0
 */
class WebhookContractFulfillmentHandler implements WebhookContractFulfillmentHandlerInterface
{
    public function __construct(
        private readonly ContractRepositoryInterface $contractRepository,
        private readonly EventDispatcherInterface $eventDispatcher
    ) {
    }

    /**
     * @inheritDoc
     */
    public function handlePaymentSucceeded(string $providerOrderId): ?bool
    {
        return $this->fulfillContractByProviderOrderId($providerOrderId);
    }

    /**
     * @inheritDoc
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

        // Validation - must be COMMITTED to fulfill
        if (!$contract->getState()->isCommitted()) {
            return false;
        }

        // Transition contract to FULFILLED
        $contract->fulfill();
        $this->contractRepository->save($contract);

        // Dispatch ContractFulfilledEvent
        $this->dispatchContractFulfilledEvent($contract);

        return true;
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
     * Find contract by provider order ID and fulfill it.
     *
     * @param string $providerOrderId Stripe PaymentIntent ID
     * @return bool|null true if fulfilled, false if skipped (idempotent), null if not found
     */
    private function fulfillContractByProviderOrderId(string $providerOrderId): ?bool
    {
        $contract = $this->findContractByProviderOrderId($providerOrderId);

        if ($contract === null) {
            return null;
        }

        // Idempotency check - already fulfilled
        if ($contract->getState()->isFulfilled()) {
            return false;
        }

        // Validation - must be COMMITTED to fulfill
        if (!$contract->getState()->isCommitted()) {
            return false;
        }

        // Transition contract to FULFILLED
        $contract->fulfill();
        $this->contractRepository->save($contract);

        // Dispatch ContractFulfilledEvent
        $this->dispatchContractFulfilledEvent($contract);

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

    /**
     * Dispatch ContractFulfilledEvent for event handlers.
     */
    private function dispatchContractFulfilledEvent(PaymentContractInterface $contract): void
    {
        $context = new EventContext([
            'contractId' => $contract->getId(),
            'orderId' => $contract->getOrderId(),
            'providerOrderId' => $contract->getProviderOrderId(),
            'source' => 'webhook',
        ]);

        $event = new ContractFulfilledEvent(
            $contract,
            $context,
            $contract->getOrderId() ?? ''
        );

        $this->eventDispatcher->dispatch($event);
    }
}
