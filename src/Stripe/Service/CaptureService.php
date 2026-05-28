<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

use OxidEsales\PaymentBase\Adapter\Request\CapturePaymentRequest;
use OxidEsales\PaymentBase\Adapter\Response\CaptureResponse;
use OxidEsales\PaymentBase\Adapter\ShopAdapterInterface;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Contract\Transaction;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Repository\TransactionRepositoryInterface;
use OxidEsales\PaymentBase\Service\ContractFulfillmentServiceInterface;
use OxidEsales\Payments\Stripe\Core\StripeDefinitions;
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
        private readonly ShopAdapterInterface $shopAdapter,
        ?LoggerInterface $logger = null
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function processCapture(
        PaymentContractInterface $contract,
        ?float $amount,
        array $metadata
    ): CaptureResponse {
        // Sprint 114.11b (S3): capturable-state policy lives in the service, not the handler.
        // Only AUTHORIZED and COMMITTED contracts may be captured.
        if (!$contract->getState()->isAuthorized() && !$contract->getState()->isCommitted()) {
            return CaptureResponse::failure(sprintf(
                'Cannot capture: contract not in capturable state (current: %s)',
                $contract->getStateValue()
            ));
        }

        $paymentIntentId = $this->resolvePaymentIntentId($contract);
        if ($paymentIntentId === null) {
            return CaptureResponse::failure('Contract has no PaymentIntent ID');
        }

        $result = $this->executeCapture($paymentIntentId, $amount, $metadata, $contract->getCurrency());

        if ($result->isSuccessful()) {
            $this->recordCaptureTransaction($contract, $result);
            $this->transitionContractState($contract);
        }

        return $result;
    }

    /**
     * Resolves the PaymentIntent ID from the contract.
     * Checks providerOrderId first, then falls back to metadata 'payment_intent_id'.
     */
    private function resolvePaymentIntentId(PaymentContractInterface $contract): ?string
    {
        $providerOrderId = $contract->getProviderOrderId();
        if (is_string($providerOrderId) && $providerOrderId !== '') {
            return $providerOrderId;
        }

        $metadataId = $contract->getMetadata('payment_intent_id');
        if (is_string($metadataId) && $metadataId !== '') {
            return $metadataId;
        }

        return null;
    }

    public function processDirectCapture(
        string $paymentIntentId,
        ?float $amount,
        array $metadata
    ): CaptureResponse {
        // No currency available here without an extra API call — null falls back to 2-decimal behaviour.
        return $this->executeCapture($paymentIntentId, $amount, $metadata);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function executeCapture(
        string $paymentIntentId,
        ?float $amount,
        array $metadata,
        ?string $currency = null
    ): CaptureResponse {
        try {
            $request = new CapturePaymentRequest(
                providerPaymentId: $paymentIntentId,
                amount: $amount,
                metadata: $metadata,
                currency: $currency
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
            shopId: (int) $this->shopAdapter->getShopId(),
            orderId: $contract->getOrderId() ?? '',
            contractId: $contract->getId(),
            provider: StripeDefinitions::PROVIDER,
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
