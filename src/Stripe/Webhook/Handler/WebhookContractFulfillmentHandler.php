<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Webhook\Handler;

use OxidEsales\Payments\Stripe\Core\ShopId;
use Psr\Log\LoggerInterface;
use OxidEsales\PaymentBase\Adapter\ShopAdapterInterface;
use OxidEsales\PaymentBase\Contract\ContractTerminationServiceInterface;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Contract\TerminationOutcome;
use OxidEsales\PaymentBase\Contract\Transaction;
use OxidEsales\PaymentBase\Refund\ProviderRefundLedgerInterface;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Repository\TransactionRepositoryInterface;
use OxidEsales\PaymentBase\Service\ContractFulfillmentServiceInterface;
use OxidEsales\Payments\Stripe\Core\StripeDefinitions;
use OxidEsales\Payments\Stripe\Adapter\StripeStatusMapper;
use OxidEsales\Payments\Stripe\Service\ContractCaptureRecorder;
use OxidEsales\Payments\Stripe\Webhook\ChargeRefund;

/**
 * Handles webhook events with contract-awareness.
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
        private readonly ContractTerminationServiceInterface $termination,
        private readonly TransactionRepositoryInterface $transactionRepository,
        private readonly ShopAdapterInterface $shopAdapter,
        private readonly ContractCaptureRecorder $captureRecorder,
        private readonly ProviderRefundLedgerInterface $refundLedger,
        private readonly LoggerInterface $logger
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

        // Only non-terminal contracts can fail; the linked order is ended by payment-base.
        if ($this->termination->fail($contract, $failureReason) !== TerminationOutcome::Terminated) {
            return false;
        }

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

        // Only non-terminal contracts can be cancelled; the linked order is ended by payment-base.
        if ($this->termination->cancel($contract, $cancellationReason) !== TerminationOutcome::Terminated) {
            return false;
        }

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

        $outcome = $this->termination->expire($contract);

        if ($outcome === TerminationOutcome::Committed) {
            // STRP-168: `committed` is not terminal. A late or duplicate expiry for a contract whose payment
            // has already been taken is skipped, never acted on.
            $this->logger->info('Ignoring an expiry for a contract whose payment was already taken', [
                'contract_id' => $contract->getId(),
                'state' => $contract->getStateValue(),
            ]);
        }

        return $outcome === TerminationOutcome::Terminated;
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
