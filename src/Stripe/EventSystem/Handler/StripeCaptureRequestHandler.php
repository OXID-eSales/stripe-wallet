<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\EventSystem\Handler;

use OxidEsales\PaymentComponent\Adapter\ShopAdapterInterface;
use OxidEsales\PaymentComponent\Contract\PaymentContractInterface;
use OxidEsales\PaymentComponent\EventSystem\Event\EventContext;
use OxidEsales\PaymentComponent\Adapter\Response\CaptureResponse;
use OxidEsales\PaymentComponent\EventSystem\Handler\HandlerInterface;
use OxidEsales\PaymentComponent\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentComponent\Service\FileLoggerInterface;
use OxidEsales\Payments\Stripe\EventSystem\Event\StripeCaptureRequestEvent;
use OxidEsales\Payments\Stripe\Service\CaptureServiceInterface;
use OxidEsales\PaymentComponent\Service\RequestLogServiceInterface;
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
        private readonly CaptureServiceInterface $captureService,
        private readonly ContractRepositoryInterface $contractRepository,
        private readonly RequestLogServiceInterface $requestLogService,
        private readonly ShopAdapterInterface $shopAdapter,
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
     * Execute the capture via CaptureService.
     *
     * Sprint 9: Delegates to CaptureService for capture execution and contract state transition.
     */
    private function executeCapture(
        StripeCaptureRequestEvent $event,
        PaymentContractInterface $contract,
        string $paymentIntentId,
        EventContext $context
    ): void {
        $this->logger->info('Executing Stripe capture', [
            'contract_id' => $event->getContractId(),
            'payment_intent_id' => $paymentIntentId,
            'amount' => $event->getAmount(),
            'initiator' => $event->getInitiator(),
        ]);

        $metadata = $this->buildMetadata($event);
        $result = $this->captureService->processCapture($contract, $event->getAmount(), $metadata);

        $this->handleCaptureResult($result, $event, $context);
    }

    /**
     * Execute direct capture via CaptureService (without contract).
     *
     * Sprint 9: Used for admin panel captures where we have PaymentIntent ID but no contract.
     */
    private function executeDirectCapture(
        StripeCaptureRequestEvent $event,
        string $paymentIntentId,
        EventContext $context
    ): void {
        $this->logger->info('Executing Stripe direct capture (no contract)', [
            'payment_intent_id' => $paymentIntentId,
            'order_id' => $event->getOrderId(),
            'amount' => $event->getAmount(),
            'initiator' => $event->getInitiator(),
        ]);

        $metadata = $this->buildDirectCaptureMetadata($event);
        $result = $this->captureService->processDirectCapture($paymentIntentId, $event->getAmount(), $metadata);

        $this->handleCaptureResult($result, $event, $context);
    }

    /**
     * Build metadata for contract-based capture.
     *
     * @return array<string, string>
     */
    private function buildMetadata(StripeCaptureRequestEvent $event): array
    {
        $metadata = [
            'initiator' => $event->getInitiator(),
        ];

        $contractId = $event->getContractId();
        if ($contractId !== null) {
            $metadata['contract_id'] = $contractId;
        }

        $reason = $event->getReason();
        if ($reason !== null) {
            $metadata['reason'] = $reason;
        }

        return $metadata;
    }

    /**
     * Build metadata for direct capture (admin panel).
     *
     * @return array<string, string>
     */
    private function buildDirectCaptureMetadata(StripeCaptureRequestEvent $event): array
    {
        $metadata = [
            'initiator' => $event->getInitiator(),
        ];

        $orderId = $event->getOrderId();
        if ($orderId !== null) {
            $metadata['order_id'] = $orderId;
        }

        $reason = $event->getReason();
        if ($reason !== null) {
            $metadata['reason'] = $reason;
        }

        return $metadata;
    }

    /**
     * Handle capture result - set context and log.
     *
     * Sprint 9: Centralized result handling for both capture modes.
     */
    private function handleCaptureResult(
        CaptureResponse $result,
        StripeCaptureRequestEvent $event,
        EventContext $context
    ): void {
        if (!$result->isSuccessful()) {
            throw new \RuntimeException($result->errorMessage ?? 'Capture failed');
        }

        $this->logger->info('Stripe capture successful', [
            'capture_id' => $result->captureId,
            'captured_amount' => $result->amountCaptured,
            'currency' => $result->currency,
        ]);

        $this->logCaptureResult($result, $event);

        $context->set('captureSuccess', true);
        $context->set('captureId', $result->captureId);
        $context->set('capturedAmount', $result->amountCaptured);
        $context->set('captureCurrency', $result->currency);

        $capturedAt = $result->capturedAt;
        if ($capturedAt !== null) {
            $context->set('capturedAt', $capturedAt->format('Y-m-d H:i:s'));
        }
    }

    /**
     * Log the capture result to the request log.
     *
     * Sprint 8/9: Delegates to RequestLogService (Facade pattern).
     */
    private function logCaptureResult(CaptureResponse $result, StripeCaptureRequestEvent $event): void
    {
        $this->requestLogService->logRequest(
            action: 'capture',
            request: ['capture_id' => $result->captureId],
            response: [
                'amount' => $result->amountCaptured,
                'currency' => $result->currency,
                'contract_id' => $event->getContractId(),
                'initiator' => $event->getInitiator(),
            ],
            referenceId: $event->getOrderId() ?? $event->getContractId() ?? '',
            shopId: (int) $this->shopAdapter->getShopId()
        );
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

    /**
     * Log exception to the request log.
     *
     * Sprint 8: Now delegates to RequestLogService (Facade pattern).
     */
    private function logExceptionToRequestLog(\Throwable $e, StripeCaptureRequestEvent $event): void
    {
        $referenceId = $event->getContractId() ?? $event->getOrderId() ?? '';
        if ($referenceId === '') {
            return;
        }

        $this->requestLogService->logException(
            action: 'capture',
            exception: $e,
            referenceId: $referenceId,
            shopId: (int) $this->shopAdapter->getShopId()
        );
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
