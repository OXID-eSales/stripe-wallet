<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\EventSystem\Handler;

use OxidEsales\PaymentBase\Adapter\ShopAdapterInterface;
use OxidEsales\PaymentBase\EventSystem\Event\EventContext;
use OxidEsales\PaymentBase\Adapter\Response\CancellationResponse;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Service\FileLoggerInterface;
use OxidEsales\Payments\Stripe\EventSystem\Event\StripeCancelAuthorizationRequestEvent;
use OxidEsales\Payments\Stripe\Service\CancelAuthorizationServiceInterface;
use OxidEsales\Payments\Stripe\Service\PaymentIntentResolver;
use OxidEsales\PaymentBase\Service\RequestLogServiceInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

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
class StripeCancelAuthorizationRequestHandler extends AbstractStripeRequestHandler
{
    private LoggerInterface $logger;

    public function __construct(
        private readonly CancelAuthorizationServiceInterface $cancelService,
        private readonly RequestLogServiceInterface $requestLogService,
        private readonly ShopAdapterInterface $shopAdapter,
        private readonly ContractRepositoryInterface $contractRepository,
        ?LoggerInterface $logger = null,
        ?FileLoggerInterface $eventLogger = null,
        private readonly ?PaymentIntentResolver $paymentIntentResolver = null
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->eventLogger = $eventLogger;
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
        $paymentIntentId = $this->resolvePaymentIntentId($event, $context);
        if ($paymentIntentId === null) {
            return;
        }

        $result = $this->cancelService->cancelAuthorization(
            $paymentIntentId,
            $event->getCancellationReason()
        );

        $this->handleCancellationResult($result, $event, $context);
    }

    /**
     * Resolve the PaymentIntent ID for this cancel request via PaymentIntentResolver.
     *
     * Admin Stripe-tab path sets paymentIntentId explicitly in the event (via OrderActionDispatcher).
     * Provider-agnostic paths (opalreturns) dispatch with only contractId;
     * the resolver falls back to contract providerOrderId.
     */
    private function resolvePaymentIntentId(
        StripeCancelAuthorizationRequestEvent $event,
        EventContext $context
    ): ?string {
        $resolver = $this->paymentIntentResolver ?? new PaymentIntentResolver($this->contractRepository);
        try {
            return $resolver->resolve($event->getPaymentIntentId(), $event->getContractId());
        } catch (RuntimeException $e) {
            $context->set('error', $e->getMessage());
            $context->set('cancelSuccess', false);
            return null;
        }
    }

    private function handleCancellationResult(
        CancellationResponse $result,
        StripeCancelAuthorizationRequestEvent $event,
        EventContext $context
    ): void {
        if (!$result->isSuccessful()) {
            $context->set('error', $result->errorMessage);
            $context->set('cancelSuccess', false);
            return;
        }

        $this->logCancelRequest($result, $event);
        $this->setSuccessResults($context, $result);
    }

    private function logCancelRequest(
        CancellationResponse $result,
        StripeCancelAuthorizationRequestEvent $event
    ): void {
        $this->requestLogService->logRequest(
            action: 'cancel_authorization',
            request: ['payment_intent_id' => $result->providerPaymentId],
            response: [
                'status' => $result->status,
                'reason' => $event->getCancellationReason(),
            ],
            referenceId: $event->getOrderId() ?? $result->providerPaymentId ?? '',
            shopId: (int) $this->shopAdapter->getShopId()
        );
    }

    private function setSuccessResults(EventContext $context, CancellationResponse $result): void
    {
        $context->set('cancelSuccess', true);
        $context->set('cancelledPaymentIntentId', $result->providerPaymentId);
        $context->set('cancelledStatus', $result->status);

        $this->logger->info('Cancel authorization processed successfully', [
            'payment_intent_id' => $result->providerPaymentId,
            'status' => $result->status,
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

}
