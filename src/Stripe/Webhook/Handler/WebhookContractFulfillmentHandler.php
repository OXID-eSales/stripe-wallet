<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Webhook\Handler;

use OxidEsales\Payments\Stripe\Core\ShopId;
use Psr\Log\LoggerInterface;
use OxidEsales\PaymentBase\Adapter\ShopAdapterInterface;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Contract\Transaction;
use OxidEsales\PaymentBase\Refund\ProviderRefundLedgerInterface;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Repository\TransactionRepositoryInterface;
use OxidEsales\PaymentBase\Service\ContractFulfillmentServiceInterface;
use OxidEsales\Payments\Stripe\Core\StripeDefinitions;
use OxidEsales\Payments\Stripe\Adapter\StripeStatusMapper;
use OxidEsales\Payments\Stripe\Service\ContractLinkedOrderUpdaterInterface;
use OxidEsales\Payments\Stripe\Service\ContractCaptureRecorder;
use OxidEsales\Payments\Stripe\Webhook\ChargeRefund;

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
        private readonly ContractCaptureRecorder $captureRecorder,
        private readonly ProviderRefundLedgerInterface $refundLedger,
        private readonly ?LoggerInterface $logger = null
    ) {
    }

    /**
     * @inheritDoc
     */
    public function handlePaymentSucceeded(string $providerOrderId, ?float $amountReceived = null): ?bool
    {
        $result = $this->contractFulfillmentService->fulfillByProviderOrderId($providerOrderId);
        if ($result !== true) {
            return $result;
        }

        $contract = $this->findContractByProviderOrderId($providerOrderId);
        if ($contract !== null) {
            $this->captureRecorder->record($contract, $amountReceived, $providerOrderId);
        }

        return $result;
    }

    /**
     * @inheritDoc
     *
     * VR-21 F5: every refund is booked through payment-base's ledger by Stripe's refund id, for the amount Stripe
     * refunded - the admin refund books the same id, so whichever path comes second is a duplicate. No cumulative
     * arithmetic and no refund id of our own: two refunds of the same amount are two refund ids.
     */
    public function handleChargeRefunded(string $providerOrderId, array $refunds): ?bool
    {
        $contract = $this->findContractByProviderOrderId($providerOrderId);

        if ($contract === null) {
            return null;
        }

        // The ledger books on FULFILLED contracts only.
        if (!$contract->getState()->isFulfilled()) {
            return false;
        }

        foreach ($refunds as $refund) {
            $this->bookRefund((string) $contract->getId(), $refund);
        }

        return true;
    }

    private function bookRefund(string $contractId, ChargeRefund $refund): void
    {
        if ($refund->amount <= 0.0) {
            return;
        }

        $this->refundLedger->book($contractId, $refund->id, $refund->amount, $refund->currency, 'webhook', $refund->succeeded);
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

    private function recordAudit(
        PaymentContractInterface $contract,
        string $type,
        float $amount,
        string $providerReference = ''
    ): void {
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
        if ($providerReference !== '') {
            $transaction->setTransactionId($providerReference);
        }

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

        // STRP-168: `committed` is not terminal. A late or duplicate expiry for
        // a contract whose payment has already been taken must be skipped, not
        // acted on — it would now throw from the state machine, and before the
        // mirror was added it would have quietly stornoed a paid order.
        if ($contract->getState()->isCommitted()) {
            $this->logger?->info('Ignoring an expiry for a contract whose payment was already taken', [
                'contract_id' => $contract->getId(),
                'state' => $contract->getStateValue(),
            ]);

            return false;
        }

        // Mark contract as expired
        $contract->expire();
        $this->contractRepository->save($contract);
        // STRP-168: mirror onto the order, exactly as the cancel and fail paths
        // do. Without this the order stayed at NOT_FINISHED, and since every
        // contract-keyed cleanup path skips terminal states, expiring the
        // contract was what made the order unreachable.
        $this->mirrorCancellationOnLinkedOrder($contract);
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
