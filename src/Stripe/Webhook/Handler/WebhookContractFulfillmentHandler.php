<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Webhook\Handler;

use OxidEsales\Payments\Stripe\Core\ShopId;
use Psr\Log\LoggerInterface;
use OxidEsales\PaymentBase\Adapter\ShopAdapterInterface;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Contract\Transaction;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Repository\TransactionRepositoryInterface;
use OxidEsales\PaymentBase\Service\ContractFulfillmentServiceInterface;
use OxidEsales\Payments\Stripe\Core\StripeDefinitions;
use OxidEsales\Payments\Stripe\Adapter\StripeStatusMapper;
use OxidEsales\Payments\Stripe\Service\ContractLinkedOrderUpdaterInterface;
use OxidEsales\Payments\Stripe\Service\ContractRefundRecorder;

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
        private readonly ContractFulfillmentServiceInterface $contractFulfillmentService,
        private readonly ContractLinkedOrderUpdaterInterface $orderUpdater,
        private readonly TransactionRepositoryInterface $transactionRepository,
        private readonly ShopAdapterInterface $shopAdapter,
        private readonly ?ContractRefundRecorder $refundRecorder = null,
        private readonly ?LoggerInterface $logger = null
    ) {
    }

    /**
     * @inheritDoc
     */
    public function handlePaymentSucceeded(string $providerOrderId): ?bool
    {
        $result = $this->contractFulfillmentService->fulfillByProviderOrderId($providerOrderId);

        if ($result === true) {
            $contract = $this->findContractByProviderOrderId($providerOrderId);
            $capturedAmount = $contract?->getCapturedAmount();
            if ($contract !== null && $capturedAmount !== null) {
                $this->recordAudit($contract, StripeDefinitions::TRANSACTION_TYPE_CAPTURE, $capturedAmount);
            } elseif ($contract !== null) {
                // Sprint 133 · Story 9 (F9): this used to coalesce to 0.0 and
                // write an audit row stating that 0.00 was captured for a real
                // capture. An audit trail with a wrong number is worse than a
                // missing row plus a loud log line.
                $this->logger?->error('Capture audit row skipped: contract has no captured amount', [
                    'contract_id' => $contract->getId(),
                    'provider_order_id' => $providerOrderId,
                ]);
            }
        }

        return $result;
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

        // Sprint 8 / 114.8: Record refund amount on contract (accumulates for partial refunds).
        // Delegates to ContractRefundRecorder which enforces the FULFILLED guard uniformly.
        if ($refundAmount > 0.0) {
            $recorder = $this->refundRecorder ?? new ContractRefundRecorder($this->contractRepository);
            $recorder->record($contract, $refundAmount);
            $this->recordAudit($contract, StripeDefinitions::TRANSACTION_TYPE_REFUND, $refundAmount);
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

        // Mark contract as failed
        $contract->fail($failureReason);
        $this->contractRepository->save($contract);
        $this->mirrorFailureOnLinkedOrder($contract, $failureReason);
        $this->recordAudit($contract, 'failure', 0.0);
        return true;
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

        // Mark contract as cancelled
        $contract->cancel($cancellationReason);
        $this->contractRepository->save($contract);
        $this->mirrorCancellationOnLinkedOrder($contract);
        $this->recordAudit($contract, 'cancellation', 0.0);
        return true;
    }

    private function recordAudit(PaymentContractInterface $contract, string $type, float $amount): void
    {
        $transaction = new Transaction(
            id: $type . '_' . bin2hex(random_bytes(16)),
            shopId: ShopId::of($this->shopAdapter->getShopId(), 'webhook audit row'),
            orderId: $contract->getOrderId() ?? '',
            contractId: $contract->getId(),
            provider: StripeDefinitions::PROVIDER,
            type: $type,
            status: StripeDefinitions::TRANSACTION_STATUS_COMPLETED,
            amount: $amount,
            currency: $contract->getCurrency()
        );
        $transaction->setProviderOrderId($contract->getProviderOrderId());

        $this->transactionRepository->save($transaction);
    }

    private function mirrorCancellationOnLinkedOrder(PaymentContractInterface $contract): void
    {
        $orderId = $contract->getOrderId();
        if ($orderId === null || $orderId === '') {
            return;
        }

        $this->orderUpdater->markCancelled($orderId);
    }

    private function mirrorFailureOnLinkedOrder(PaymentContractInterface $contract, string $reason): void
    {
        $orderId = $contract->getOrderId();
        if ($orderId === null || $orderId === '') {
            return;
        }

        $this->orderUpdater->markFailed($orderId, $reason);
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
