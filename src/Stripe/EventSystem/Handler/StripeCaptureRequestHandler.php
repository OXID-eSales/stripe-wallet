<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\EventSystem\Handler;

use OxidEsales\PaymentComponent\Adapter\Request\CapturePaymentRequest;
use OxidEsales\PaymentComponent\Adapter\Response\CaptureResponse;
use OxidEsales\PaymentComponent\Contract\PaymentContractInterface;
use OxidEsales\PaymentComponent\EventSystem\Event\EventContext;
use OxidEsales\PaymentComponent\EventSystem\Handler\HandlerInterface;
use OxidEsales\PaymentComponent\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentComponent\Service\FileLoggerInterface;
use OxidEsales\Payments\Stripe\Adapter\StripeAdapterInterface;
use OxidEsales\Payments\Stripe\EventSystem\Event\StripeCaptureRequestEvent;
use OxidEsales\Payments\Stripe\Application\Model\RequestLog;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Handles capture requests for Stripe authorized payments.
 *
 * This handler processes StripeCaptureRequestEvent and captures the payment
 * via Stripe API when the contract is in AUTHORIZED state.
 *
 * Handler responsibilities:
 * 1. Receive event and extract parameters
 * 2. Validate contract state (must be AUTHORIZED)
 * 3. Call Stripe adapter to execute capture
 * 4. Transition contract from AUTHORIZED to READY_TO_COMMIT
 * 5. Log request/response
 * 6. Set results in context
 *
 * @since 2.0.0
 */
class StripeCaptureRequestHandler implements HandlerInterface
{
    private LoggerInterface $logger;

    public function __construct(
        private readonly StripeAdapterInterface $stripeAdapter,
        private readonly ContractRepositoryInterface $contractRepository,
        ?LoggerInterface $logger = null,
        private readonly ?FileLoggerInterface $eventLogger = null
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public static function getHandledEventClass(): string
    {
        return StripeCaptureRequestEvent::class;
    }

    public function getPriority(): int
    {
        return 0;
    }

    public function handle(object $event): void
    {
        $this->logEvent('StripeCaptureRequestHandler::handle() START');

        if (!$event instanceof StripeCaptureRequestEvent) {
            $this->logEvent('StripeCaptureRequestHandler: Wrong event type, skipping');
            return;
        }

        $context = $event->getContext();

        try {
            $this->logEvent('StripeCaptureRequestHandler: Processing capture', [
                'contractId' => $event->getContractId(),
                'amount' => $event->getAmount(),
            ]);
            $this->processCapture($event, $context);
            $this->logEvent('StripeCaptureRequestHandler::handle() END - SUCCESS');
        } catch (\Throwable $e) {
            $this->logEvent('StripeCaptureRequestHandler: EXCEPTION', [
                'error' => $e->getMessage(),
            ]);
            $this->handleException($e, $context, $event);
        }
    }

    private function processCapture(StripeCaptureRequestEvent $event, EventContext $context): void
    {
        $contractId = $event->getContractId();

        // Support two capture modes:
        // 1. Direct capture (admin panel) - PaymentIntent ID provided directly, no contract
        // 2. Contract-based capture (automated flows) - Contract ID provided

        if ($contractId === null) {
            // Direct capture mode - use PaymentIntent ID directly
            $paymentIntentId = $event->getPaymentIntentId();
            if ($paymentIntentId === null || $paymentIntentId === '') {
                $context->set('error', 'PaymentIntent ID is missing');
                $context->set('captureSuccess', false);
                return;
            }

            // Execute direct capture without contract
            $this->executeDirectCapture($event, $paymentIntentId, $context);
            return;
        }

        // Contract-based capture mode
        $contract = $this->contractRepository->findById($contractId);
        if ($contract === null) {
            $context->set('error', 'Contract not found: ' . $contractId);
            $context->set('captureSuccess', false);
            return;
        }

        $context->set('contract', $contract);

        // Validate contract state - must be AUTHORIZED for delayed capture
        if (!$contract->getState()->isAuthorized()) {
            $context->set('error', sprintf(
                'Cannot capture: contract not in AUTHORIZED state (current: %s)',
                $contract->getState()->getValue()
            ));
            $context->set('captureSuccess', false);
            return;
        }

        // Get PaymentIntent ID from contract
        $paymentIntentId = $this->getPaymentIntentId($event, $contract, $context);
        if ($paymentIntentId === null) {
            return;
        }

        // Execute capture
        $this->executeCapture($event, $contract, $paymentIntentId, $context);
    }

    /**
     * Get PaymentIntent ID from event or contract.
     *
     * @param StripeCaptureRequestEvent $event
     * @param PaymentContractInterface $contract
     * @param EventContext $context
     * @return string|null
     */
    private function getPaymentIntentId(
        StripeCaptureRequestEvent $event,
        PaymentContractInterface $contract,
        EventContext $context
    ): ?string {
        // First try from event
        $paymentIntentId = $event->getPaymentIntentId();
        if ($paymentIntentId !== null) {
            return $paymentIntentId;
        }

        // Then try from contract's provider order ID
        $providerOrderId = $contract->getProviderOrderId();
        if (is_string($providerOrderId) && $providerOrderId !== '') {
            return $providerOrderId;
        }

        // Try from contract metadata
        $paymentIntentFromMetadata = $contract->getMetadata('payment_intent_id');
        if (is_string($paymentIntentFromMetadata) && $paymentIntentFromMetadata !== '') {
            return $paymentIntentFromMetadata;
        }

        $context->set('error', 'No PaymentIntent ID found for this contract');
        $context->set('captureSuccess', false);
        return null;
    }

    /**
     * Execute the capture via Stripe API.
     *
     * @param StripeCaptureRequestEvent $event
     * @param PaymentContractInterface $contract
     * @param string $paymentIntentId
     * @param EventContext $context
     */
    private function executeCapture(
        StripeCaptureRequestEvent $event,
        PaymentContractInterface $contract,
        string $paymentIntentId,
        EventContext $context
    ): void {
        $amount = $event->getAmount();

        $this->logger->info('Executing Stripe capture', [
            'contract_id' => $event->getContractId(),
            'payment_intent_id' => $paymentIntentId,
            'amount' => $amount,
            'initiator' => $event->getInitiator(),
        ]);

        try {
            // Build capture request
            $metadata = [
                'contract_id' => $event->getContractId(),
                'initiator' => $event->getInitiator(),
            ];

            $reason = $event->getReason();
            if ($reason !== null) {
                $metadata['reason'] = $reason;
            }

            $request = new CapturePaymentRequest(
                providerPaymentId: $paymentIntentId,
                amount: $amount,
                metadata: $metadata
            );

            // Execute capture via adapter
            $response = $this->stripeAdapter->capturePayment($request);

            // Transition contract from AUTHORIZED to READY_TO_COMMIT
            $contract->captureAuthorization();
            $this->contractRepository->save($contract);

            // Log success
            $this->logger->info('Stripe capture successful', [
                'contract_id' => $event->getContractId(),
                'capture_id' => $response->captureId,
                'captured_amount' => $response->amountCaptured,
                'currency' => $response->currency,
            ]);

            // Log to request log
            $this->logCaptureRequest($response, $event);

            // Set success results
            $context->set('captureSuccess', true);
            $context->set('captureId', $response->captureId);
            $context->set('capturedAmount', $response->amountCaptured);
            $context->set('captureCurrency', $response->currency);
            $context->set('capturedAt', $response->capturedAt->format('Y-m-d H:i:s'));
        } catch (\Throwable $e) {
            throw $e;
        }
    }

    /**
     * Execute direct capture via Stripe API (without contract).
     *
     * Used for admin panel captures where we have PaymentIntent ID but no contract.
     *
     * @param StripeCaptureRequestEvent $event
     * @param string $paymentIntentId
     * @param EventContext $context
     */
    private function executeDirectCapture(
        StripeCaptureRequestEvent $event,
        string $paymentIntentId,
        EventContext $context
    ): void {
        $amount = $event->getAmount();

        $this->logger->info('Executing Stripe direct capture (no contract)', [
            'payment_intent_id' => $paymentIntentId,
            'order_id' => $event->getOrderId(),
            'amount' => $amount,
            'initiator' => $event->getInitiator(),
        ]);

        try {
            // Build capture request
            $metadata = [
                'order_id' => $event->getOrderId(),
                'initiator' => $event->getInitiator(),
            ];

            $reason = $event->getReason();
            if ($reason !== null) {
                $metadata['reason'] = $reason;
            }

            $request = new CapturePaymentRequest(
                providerPaymentId: $paymentIntentId,
                amount: $amount,
                metadata: $metadata
            );

            // Execute capture via adapter
            $response = $this->stripeAdapter->capturePayment($request);

            // Log success
            $this->logger->info('Stripe direct capture successful', [
                'payment_intent_id' => $paymentIntentId,
                'capture_id' => $response->captureId,
                'captured_amount' => $response->amountCaptured,
                'currency' => $response->currency,
            ]);

            // Log to request log
            $this->logCaptureRequest($response, $event);

            // Set success results
            $context->set('captureSuccess', true);
            $context->set('captureId', $response->captureId);
            $context->set('capturedAmount', $response->amountCaptured);
            $context->set('captureCurrency', $response->currency);
            $context->set('capturedAt', $response->capturedAt->format('Y-m-d H:i:s'));
        } catch (\Throwable $e) {
            throw $e;
        }
    }

    /**
     * Log the capture request to the request log.
     *
     * @param CaptureResponse $response Capture response from adapter
     * @param StripeCaptureRequestEvent $event
     */
    private function logCaptureRequest(CaptureResponse $response, StripeCaptureRequestEvent $event): void
    {
        try {
            // @phpstan-ignore-next-line - RequestLog is from legacy Stripe module
            $requestLog = oxNew(RequestLog::class);
            // @phpstan-ignore-next-line - RequestLog is from legacy Stripe module
            $requestLog->logRequest(
                ['capture_id' => $response->captureId],
                [
                    'amount' => $response->amountCaptured,
                    'currency' => $response->currency,
                    'contract_id' => $event->getContractId(),
                    'initiator' => $event->getInitiator(),
                ],
                $event->getOrderId() ?? $event->getContractId(),
                (int) \OxidEsales\Eshop\Core\Registry::getConfig()->getShopId()
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to log capture request', ['error' => $e->getMessage()]);
        }
    }

    private function handleException(
        \Throwable $e,
        EventContext $context,
        StripeCaptureRequestEvent $event
    ): void {
        $context->set('error', $e->getMessage());
        $context->set('captureSuccess', false);

        $this->logger->error('Capture handler exception', [
            'error' => $e->getMessage(),
            'contract_id' => $event->getContractId(),
            'trace' => $e->getTraceAsString(),
        ]);

        $this->logExceptionToRequestLog($e, $event);
    }

    private function logExceptionToRequestLog(\Throwable $e, StripeCaptureRequestEvent $event): void
    {
        try {
            $contractId = $event->getContractId();
            if ($contractId === null) {
                return;
            }

            // @phpstan-ignore-next-line - RequestLog is from legacy Stripe module
            $requestLog = oxNew(RequestLog::class);
            // @phpstan-ignore-next-line - RequestLog is from legacy Stripe module
            $requestLog->logExceptionResponse(
                ['contract_id' => $contractId],
                (int) ($e->getCode() ?: 500),
                $e->getMessage(),
                'capture',
                $contractId
            );
        } catch (\Throwable $logError) {
            $this->logger->warning('Failed to log capture error', ['error' => $logError->getMessage()]);
        }
    }

    /**
     * Log event to file logger for debugging.
     *
     * @param string $message
     * @param array<string, mixed> $context
     */
    private function logEvent(string $message, array $context = []): void
    {
        if ($this->eventLogger !== null) {
            $this->eventLogger->log($message, $context);
        }
    }
}
