<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\EventSystem\Handler;

use OxidEsales\PaymentComponent\Adapter\ShopAdapterInterface;
use OxidEsales\PaymentComponent\EventSystem\Event\EventContext;
use OxidEsales\PaymentComponent\EventSystem\Handler\HandlerInterface;
use OxidEsales\PaymentComponent\Service\FileLoggerInterface;
use OxidEsales\PaymentComponent\Service\Result\CancellationResult;
use OxidEsales\Payments\Stripe\EventSystem\Event\StripeCancelAuthorizationRequestEvent;
use OxidEsales\Payments\Stripe\Service\CancelAuthorizationServiceInterface;
use OxidEsales\PaymentComponent\Service\RequestLogServiceInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Handles cancel authorization requests for Stripe PaymentIntents.
 *
 * Sprint 11: Refactored to delegate to CancelAuthorizationService (SRP).
 *
 * Handler responsibilities (ONLY):
 * 1. Receive event and extract parameters
 * 2. Delegate to CancelAuthorizationService
 * 3. Delegate logging to RequestLogService
 * 4. Set results in context
 *
 * @since 2.0.0
 */
class StripeCancelAuthorizationRequestHandler implements HandlerInterface
{
    private LoggerInterface $logger;

    public function __construct(
        private readonly CancelAuthorizationServiceInterface $cancelService,
        private readonly RequestLogServiceInterface $requestLogService,
        private readonly ShopAdapterInterface $shopAdapter,
        ?LoggerInterface $logger = null,
        private readonly ?FileLoggerInterface $eventLogger = null
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public static function getHandledEventClass(): string
    {
        return StripeCancelAuthorizationRequestEvent::class;
    }

    public function handle(object $event): void
    {
        $this->logEvent('StripeCancelAuthorizationRequestHandler::handle() START');

        if (!$event instanceof StripeCancelAuthorizationRequestEvent) {
            $this->logEvent('StripeCancelAuthorizationRequestHandler: Wrong event type, skipping');
            return;
        }

        $context = $event->getContext();

        try {
            $this->logEvent('StripeCancelAuthorizationRequestHandler: Processing cancel', [
                'paymentIntentId' => $event->getPaymentIntentId(),
                'reason' => $event->getCancellationReason(),
            ]);
            $this->processCancelAuthorization($event, $context);
            $this->logEvent('StripeCancelAuthorizationRequestHandler::handle() END - SUCCESS');
        } catch (\Throwable $e) {
            $this->logEvent('StripeCancelAuthorizationRequestHandler: EXCEPTION', [
                'error' => $e->getMessage(),
            ]);
            $this->handleException($e, $context, $event);
        }
    }

    private function processCancelAuthorization(
        StripeCancelAuthorizationRequestEvent $event,
        EventContext $context
    ): void {
        $paymentIntentId = $event->getPaymentIntentId();

        if ($paymentIntentId === null || $paymentIntentId === '') {
            $context->set('error', 'PaymentIntent ID is missing');
            $context->set('cancelSuccess', false);
            return;
        }

        $result = $this->cancelService->cancelAuthorization(
            $paymentIntentId,
            $event->getCancellationReason()
        );

        $this->handleCancellationResult($result, $event, $context);
    }

    private function handleCancellationResult(
        CancellationResult $result,
        StripeCancelAuthorizationRequestEvent $event,
        EventContext $context
    ): void {
        if (!$result->isSuccessful()) {
            $context->set('error', $result->getErrorMessage());
            $context->set('cancelSuccess', false);
            return;
        }

        $this->logCancelRequest($result, $event);
        $this->setSuccessResults($context, $result);
    }

    private function logCancelRequest(
        CancellationResult $result,
        StripeCancelAuthorizationRequestEvent $event
    ): void {
        $this->requestLogService->logRequest(
            action: 'cancel_authorization',
            request: ['payment_intent_id' => $result->getPaymentIntentId()],
            response: [
                'status' => $result->getStatus(),
                'reason' => $event->getCancellationReason(),
            ],
            referenceId: $event->getOrderId() ?? $result->getPaymentIntentId() ?? '',
            shopId: (int) $this->shopAdapter->getShopId()
        );
    }

    private function setSuccessResults(EventContext $context, CancellationResult $result): void
    {
        $context->set('cancelSuccess', true);
        $context->set('cancelledPaymentIntentId', $result->getPaymentIntentId());
        $context->set('cancelledStatus', $result->getStatus());

        $this->logger->info('Cancel authorization processed successfully', [
            'payment_intent_id' => $result->getPaymentIntentId(),
            'status' => $result->getStatus(),
        ]);
    }

    private function handleException(
        \Throwable $e,
        EventContext $context,
        StripeCancelAuthorizationRequestEvent $event
    ): void {
        $context->set('error', $e->getMessage());
        $context->set('cancelSuccess', false);

        $this->logger->error('Cancel authorization handler exception', [
            'error' => $e->getMessage(),
            'payment_intent_id' => $event->getPaymentIntentId(),
        ]);

        $this->logExceptionToRequestLog($e, $event);
    }

    private function logExceptionToRequestLog(
        \Throwable $e,
        StripeCancelAuthorizationRequestEvent $event
    ): void {
        $paymentIntentId = $event->getPaymentIntentId();
        if ($paymentIntentId === null) {
            return;
        }

        $this->requestLogService->logException(
            action: 'cancel_authorization',
            exception: $e,
            referenceId: $paymentIntentId,
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
