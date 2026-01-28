<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

use OxidEsales\PaymentComponent\Adapter\Request\CapturePaymentRequest;
use OxidEsales\PaymentComponent\Contract\PaymentContractInterface;
use OxidEsales\PaymentComponent\Repository\ContractRepositoryInterface;
use OxidEsales\Payments\Stripe\Adapter\StripeAdapterInterface;
use OxidEsales\PaymentComponent\Service\Result\CaptureResult;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Service for capturing Stripe payments.
 *
 * Sprint 9: Extracted from StripeCaptureRequestHandler.
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
        private readonly StripeAdapterInterface $stripeAdapter,
        private readonly ContractRepositoryInterface $contractRepository,
        ?LoggerInterface $logger = null
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function processCapture(
        PaymentContractInterface $contract,
        ?float $amount,
        array $metadata
    ): CaptureResult {
        $paymentIntentId = $contract->getProviderOrderId();
        if (!is_string($paymentIntentId) || $paymentIntentId === '') {
            return CaptureResult::failure('Contract has no PaymentIntent ID');
        }

        $result = $this->executeCapture($paymentIntentId, $amount, $metadata);

        if ($result->isSuccessful()) {
            $this->transitionContractState($contract);
        }

        return $result;
    }

    public function processDirectCapture(
        string $paymentIntentId,
        ?float $amount,
        array $metadata
    ): CaptureResult {
        return $this->executeCapture($paymentIntentId, $amount, $metadata);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function executeCapture(
        string $paymentIntentId,
        ?float $amount,
        array $metadata
    ): CaptureResult {
        try {
            $request = new CapturePaymentRequest(
                providerPaymentId: $paymentIntentId,
                amount: $amount,
                metadata: $metadata
            );

            $response = $this->stripeAdapter->capturePayment($request);

            $this->logger->info('Capture processed successfully', [
                'payment_intent_id' => $paymentIntentId,
                'capture_id' => $response->captureId,
                'amount' => $response->amountCaptured,
                'currency' => $response->currency,
            ]);

            return CaptureResult::success(
                captureId: $response->captureId,
                amountCaptured: $response->amountCaptured,
                currency: $response->currency,
                capturedAt: $this->toDateTimeImmutable($response->capturedAt)
            );
        } catch (\Throwable $e) {
            $this->logger->error('Capture failed', [
                'payment_intent_id' => $paymentIntentId,
                'error' => $e->getMessage(),
            ]);

            return CaptureResult::failure($e->getMessage());
        }
    }

    private function transitionContractState(PaymentContractInterface $contract): void
    {
        $contract->captureAuthorization();
        $this->contractRepository->save($contract);

        $this->logger->info('Contract transitioned after capture', [
            'contract_id' => $contract->getId(),
            'new_state' => $contract->getStateValue(),
        ]);
    }

    private function toDateTimeImmutable(\DateTimeInterface $dateTime): \DateTimeImmutable
    {
        if ($dateTime instanceof \DateTimeImmutable) {
            return $dateTime;
        }

        return \DateTimeImmutable::createFromMutable($dateTime);
    }
}
