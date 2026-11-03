<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

use OxidEsales\PaymentBase\Adapter\Exception\PaymentAdapterException;
use OxidEsales\PaymentBase\Adapter\Request\CapturePaymentRequest;
use OxidEsales\PaymentBase\Adapter\Response\CaptureResponse;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Payment\ProviderCaptureLedgerInterface;
use OxidEsales\PaymentBase\Service\ContractFulfillmentServiceInterface;
use OxidEsales\Payments\Stripe\Service\Factory\StripeAdapterFactoryInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Service for capturing Stripe payments.
 *
 * Sprint 9: Extracted from StripeCaptureRequestHandler.
 * Sprint 26: Changed to use factory for lazy adapter creation (module activation fix).
 * Sprint 82: Added ContractFulfillmentService for COMMITTED→FULFILLED transition.
 *
 * - processCapture(): the broker's capture (a return's partial capture): asks Stripe, books the capture through
 *   payment-base's ProviderCaptureLedger by Stripe's charge id, moves the contract's state.
 * - captureAtStripe(): only asks Stripe. The admin's capture (VR-22 A5, {@see \OxidEsales\Payments\Stripe\Admin\StripeMoneyGateway})
 *   is booked by payment-base's MoneyOperationRunner, and the contract's state follows Stripe's webhook.
 *
 * @since 2.0.0
 */
class CaptureService implements CaptureServiceInterface
{
    public function __construct(
        private readonly StripeAdapterFactoryInterface $adapterFactory,
        private readonly ContractRepositoryInterface $contractRepository,
        private readonly ContractFulfillmentServiceInterface $contractFulfillmentService,
        private readonly ProviderCaptureLedgerInterface $captureLedger,
        private readonly LoggerInterface $logger
    ) {
    }

    public function processCapture(
        PaymentContractInterface $contract,
        ?float $amount,
        array $metadata
    ): CaptureResponse {
        // Sprint 121 (STRP-129): defense-in-depth — no caller may push a
        // non-positive partial amount to the Stripe API. Null = full capture.
        if ($amount !== null && $amount <= 0.0) {
            return CaptureResponse::failure('Capture amount must be greater than zero');
        }

        // Sprint 127 (STRP-15123): reject partial captures above the remaining capturable.
        // Browser max= is UX only; an over-capture otherwise reaches Stripe and corrupts state.
        // Null amount = full capture (no partial-amount guard needed).
        if ($amount !== null) {
            $authorized = $contract->getAmount();
            $captured = $contract->getCapturedAmount();
            if (CapturableAmount::isExceededBy($amount, $authorized, $captured)) {
                return CaptureResponse::failure(sprintf(
                    'Capture amount %.2f exceeds remaining capturable %.2f',
                    $amount,
                    max(0.0, CapturableAmount::remaining($authorized, $captured))
                ));
            }
        }

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

        $result = $this->captureAtStripe($paymentIntentId, $amount, $contract->getCurrency(), $metadata);

        if ($result->isSuccessful()) {
            $this->transitionContractState($this->bookCapture($contract, $paymentIntentId, $result));
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

    public function captureAtStripe(
        string $paymentIntentId,
        ?float $amount,
        string $currency,
        array $metadata
    ): CaptureResponse {
        // Sprint 121 (STRP-129): defense-in-depth - no caller may push a non-positive partial amount to Stripe.
        if ($amount !== null && $amount <= 0.0) {
            return CaptureResponse::failure('Capture amount must be greater than zero');
        }

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
        } catch (Throwable $e) {
            $this->logger->error('Capture failed', [
                'payment_intent_id' => $paymentIntentId,
                'error' => $e->getMessage(),
            ]);

            return CaptureResponse::failure(
                $e->getMessage(),
                $e instanceof PaymentAdapterException ? $e->getErrorCode() : null
            );
        }
    }

    /**
     * The capture Stripe made, booked once under the PaymentIntent id (Stripe captures a PaymentIntent once; the webhook
     * books the same id and is then a duplicate). The ledger saves the contract on its own instance, so the state moves on a fresh one: saving the
     * caller's would write the old captured total back.
     */
    private function bookCapture(PaymentContractInterface $contract, string $paymentIntentId, CaptureResponse $result): PaymentContractInterface
    {
        $contractId = (string) $contract->getId();
        $this->captureLedger->book(
            $contractId,
            $paymentIntentId,
            (float) $result->amountCaptured,
            (string) $result->currency,
            'event'
        );

        $booked = $this->contractRepository->findById($contractId);
        if ($booked === null) {
            throw new RuntimeException('Contract ' . $contractId . ' vanished while its capture was booked.');
        }

        return $booked;
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
