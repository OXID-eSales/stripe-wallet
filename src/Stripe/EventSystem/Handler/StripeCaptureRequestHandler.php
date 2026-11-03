<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\EventSystem\Handler;

use OxidEsales\PaymentBase\Adapter\ShopAdapterInterface;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\EventSystem\Event\EventContext;
use OxidEsales\PaymentBase\Adapter\Response\CaptureResponse;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Service\FileLoggerInterface;
use OxidEsales\Payments\Stripe\EventSystem\Event\StripeCaptureRequestEvent;
use OxidEsales\Payments\Stripe\Service\CaptureServiceInterface;
use OxidEsales\PaymentBase\Service\RequestLogServiceInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Throwable;

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
class StripeCaptureRequestHandler extends AbstractStripeRequestHandler
{
    private LoggerInterface $logger;

    public function __construct(
        private readonly CaptureServiceInterface $captureService,
        private readonly ContractRepositoryInterface $contractRepository,
        private readonly RequestLogServiceInterface $requestLogService,
        private readonly ShopAdapterInterface $shopAdapter,
        ?LoggerInterface $logger = null,
        ?FileLoggerInterface $eventLogger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->eventLogger = $eventLogger;
    }

    public static function getHandledEventClass(): string
    {
        return StripeCaptureRequestEvent::class;
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
        } catch (Throwable $e) {
            $this->logEvent('StripeCaptureRequestHandler: EXCEPTION', [
                'error' => $e->getMessage(),
            ]);
            $this->handleException($e, $context, $event);
        }
    }

    private function processCapture(StripeCaptureRequestEvent $event, EventContext $context): void
    {
        $contractId = $event->getContractId();

        // VR-22 D-8: money is only captured for a contract; the PaymentIntent comes from the contract.
        if ($contractId === null) {
            $context->set('error', 'Contract ID is missing');
            $context->set('captureSuccess', false);
            return;
        }

        $contract = $this->contractRepository->findById($contractId);
        if ($contract === null) {
            $context->set('error', 'Contract not found: ' . $contractId);
            $context->set('captureSuccess', false);
            return;
        }

        $context->set('contract', $contract);

        // Sprint 114.11b (S3): capturable-state policy lives in CaptureService::processCapture().
        // The handler delegates directly; the service owns state validation and PI ID resolution.
        $this->executeCapture($event, $contract, $context);
    }

    /**
     * Execute the capture via CaptureService.
     *
     * Sprint 9: Delegates to CaptureService for capture execution and contract state transition.
     * Sprint 114.11b (S3): no longer pre-resolves PI ID — service owns state check + PI lookup.
     */
    private function executeCapture(
        StripeCaptureRequestEvent $event,
        PaymentContractInterface $contract,
        EventContext $context
    ): void {
        $this->logger->info('Executing Stripe capture', [
            'contract_id' => $event->getContractId(),
            'amount' => $event->getAmount(),
            'initiator' => $event->getInitiator(),
        ]);

        $metadata = $this->buildMetadata($event);
        $result = $this->captureService->processCapture($contract, $event->getAmount(), $metadata);

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
            throw new RuntimeException($result->errorMessage ?? 'Capture failed');
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
        Throwable $e,
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
    private function logExceptionToRequestLog(Throwable $e, StripeCaptureRequestEvent $event): void
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
}
