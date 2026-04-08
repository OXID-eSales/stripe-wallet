<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

use OxidEsales\PaymentComponent\Adapter\Request\CapturePaymentRequest;
use OxidEsales\PaymentComponent\Adapter\Response\CaptureResponse;
use OxidEsales\PaymentComponent\Contract\PaymentContractInterface;
use OxidEsales\PaymentComponent\Contract\Transaction;
use OxidEsales\PaymentComponent\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentComponent\Repository\TransactionRepositoryInterface;
use OxidEsales\PaymentComponent\Service\ContractFulfillmentServiceInterface;
use OxidEsales\Payments\Stripe\Service\Factory\StripeAdapterFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Service for capturing Stripe payments.
 *
 * Sprint 9: Extracted from StripeCaptureRequestHandler.
 * Sprint 26: Changed to use factory for lazy adapter creation (module activation fix).
 * Sprint 82: Added ContractFulfillmentService for COMMITTED→FULFILLED transition.
 *
 * Handles both contract-based and direct captures:
 * - processCapture(): With contract, handles state transition
 * - processDirectCapture(): Without contract (admin panel)
 *
 * Follows existing patterns: NullLogger default, readonly properties, final class.
 *
 * @since 2.0.0
 */
final class CaptureService implements CaptureServiceInterface
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly StripeAdapterFactoryInterface $adapterFactory,
        private readonly ContractRepositoryInterface $contractRepository,
        private readonly ContractFulfillmentServiceInterface $contractFulfillmentService,
        private readonly TransactionRepositoryInterface $transactionRepository,
        ?LoggerInterface $logger = null
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function processCapture(
        PaymentContractInterface $contract,
        ?float $amount,
        array $metadata
    ): CaptureResponse {
        $paymentIntentId = $contract->getProviderOrderId();
        if (!is_string($paymentIntentId) || $paymentIntentId === '') {
            return CaptureResponse::failure('Contract has no PaymentIntent ID');
        }

        $result = $this->executeCapture($paymentIntentId, $amount, $metadata);

        if ($result->isSuccessful()) {
            $this->recordCaptureTransaction($contract, $result);
            $this->transitionContractState($contract);
        }

        return $result;
    }

    public function processDirectCapture(
        string $paymentIntentId,
        ?float $amount,
        array $metadata
    ): CaptureResponse {
        return $this->executeCapture($paymentIntentId, $amount, $metadata);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function executeCapture(
        string $paymentIntentId,
        ?float $amount,
        array $metadata
    ): CaptureResponse {
        try {
            $request = new CapturePaymentRequest(
                providerPaymentId: $paymentIntentId,
                amount: $amount,
                metadata: $metadata
            );

            $response = $this->adapterFactory->getStripeAdapter()->capturePayment($request);

            $this->logger->info('Capture processed successfully', [
                'payment_intent_id' => $paymentIntentId,
                'capture_id' => $response->captureId,
                'amount' => $response->amountCaptured,
                'currency' => $response->currency,
            ]);

            return $response;
        } catch (\Throwable $e) {
            $this->logger->error('Capture failed', [
                'payment_intent_id' => $paymentIntentId,
                'error' => $e->getMessage(),
            ]);

            return CaptureResponse::failure($e->getMessage());
        }
    }

    private function recordCaptureTransaction(
        PaymentContractInterface $contract,
        CaptureResponse $result
    ): void {
        $transaction = new Transaction(
            id: 'cap_' . bin2hex(random_bytes(16)),
            shopId: 1,
            orderId: $contract->getOrderId() ?? '',
            contractId: $contract->getId(),
            provider: 'stripe',
            type: 'capture',
            status: 'completed',
            amount: $result->amountCaptured ?? 0,
            currency: $result->currency ?? 'EUR'
        );
        $transaction->setTransactionId($result->captureId);
        $transaction->setProviderOrderId($contract->getProviderOrderId());

        $this->transactionRepository->save($transaction);
    }

    private function transitionContractState(PaymentContractInterface $contract): void
    {
        // Sprint 82 (STRP-118): Handle two capture scenarios:
        // - AUTHORIZED: Normal delayed capture → captureAuthorization() → READY_TO_COMMIT
        // - COMMITTED: Manual capture order that skipped AUTHORIZED state.
        //   Use ContractFulfillmentService to dispatch ContractFulfilledEvent,
        //   which triggers OXPAID update via OrderPaymentCompletedHandler.
        if ($contract->getState()->isAuthorized()) {
            $contract->captureAuthorization();
            $this->contractRepository->save($contract);
        } elseif ($contract->getState()->isCommitted()) {
            $this->contractFulfillmentService->fulfill($contract);
        }

        $this->logger->info('Contract transitioned after capture', [
            'contract_id' => $contract->getId(),
            'new_state' => $contract->getStateValue(),
        ]);
    }
}
