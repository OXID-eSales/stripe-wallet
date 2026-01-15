<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Stripe\EventSystem\Handler;

use OxidEsales\PaymentComponent\EventSystem\Event\EventContext;
use OxidEsales\PaymentComponent\EventSystem\Handler\HandlerInterface;
use OxidEsales\PaymentComponent\Service\FileLoggerInterface;
use OxidSolutionCatalysts\Payments\Stripe\Adapter\StripeAdapterInterface;
use OxidSolutionCatalysts\Payments\Stripe\EventSystem\Event\StripeCancelAuthorizationRequestEvent;
use OxidSolutionCatalysts\Stripe\Application\Model\RequestLog;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Handles cancel authorization requests for Stripe PaymentIntents.
 *
 * This handler processes StripeCancelAuthorizationRequestEvent and cancels
 * the PaymentIntent via Stripe API, releasing the authorization hold.
 *
 * Used for manual capture mode orders where the merchant decides not to
 * capture the authorized payment.
 *
 * Handler responsibilities:
 * 1. Receive event and extract parameters
 * 2. Validate PaymentIntent ID exists
 * 3. Call Stripe adapter to cancel the PaymentIntent
 * 4. Log request/response
 * 5. Set results in context
 *
 * @since 2.0.0
 */
class StripeCancelAuthorizationRequestHandler implements HandlerInterface
{
    private LoggerInterface $logger;

    public function __construct(
        private readonly StripeAdapterInterface $stripeAdapter,
        ?LoggerInterface $logger = null,
        private readonly ?FileLoggerInterface $eventLogger = null
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public static function getHandledEventClass(): string
    {
        return StripeCancelAuthorizationRequestEvent::class;
    }

    public function getPriority(): int
    {
        return 0;
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

        $this->executeCancelAuthorization($event, $paymentIntentId, $context);
    }

    private function executeCancelAuthorization(
        StripeCancelAuthorizationRequestEvent $event,
        string $paymentIntentId,
        EventContext $context
    ): void {
        $this->logger->info('Executing Stripe cancel authorization', [
            'payment_intent_id' => $paymentIntentId,
            'reason' => $event->getCancellationReason(),
            'initiator' => $event->getInitiator(),
        ]);

        try {
            // Execute cancel via adapter
            $cancelledPaymentIntent = $this->stripeAdapter->cancelPaymentIntent(
                $paymentIntentId,
                $event->getCancellationReason()
            );

            // Log success
            $this->logger->info('Stripe cancel authorization successful', [
                'payment_intent_id' => $paymentIntentId,
                'status' => $cancelledPaymentIntent->status,
            ]);

            // Log to request log
            $this->logCancelRequest($paymentIntentId, $event);

            // Set success results
            $context->set('cancelSuccess', true);
            $context->set('cancelledPaymentIntentId', $paymentIntentId);
            $context->set('cancelledStatus', $cancelledPaymentIntent->status);
        } catch (\Throwable $e) {
            throw $e;
        }
    }

    /**
     * Log the cancel request to the request log.
     */
    private function logCancelRequest(string $paymentIntentId, StripeCancelAuthorizationRequestEvent $event): void
    {
        try {
            // @phpstan-ignore-next-line - RequestLog is from legacy Stripe module
            $requestLog = oxNew(RequestLog::class);
            // @phpstan-ignore-next-line - RequestLog is from legacy Stripe module
            $requestLog->logRequest(
                ['payment_intent_id' => $paymentIntentId],
                [
                    'action' => 'cancel_authorization',
                    'reason' => $event->getCancellationReason(),
                    'initiator' => $event->getInitiator(),
                ],
                $event->getOrderId() ?? $paymentIntentId,
                (int) \OxidEsales\Eshop\Core\Registry::getConfig()->getShopId()
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to log cancel request', ['error' => $e->getMessage()]);
        }
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
            'trace' => $e->getTraceAsString(),
        ]);

        $this->logExceptionToRequestLog($e, $event);
    }

    private function logExceptionToRequestLog(
        \Throwable $e,
        StripeCancelAuthorizationRequestEvent $event
    ): void {
        try {
            $paymentIntentId = $event->getPaymentIntentId();
            if ($paymentIntentId === null) {
                return;
            }

            // @phpstan-ignore-next-line - RequestLog is from legacy Stripe module
            $requestLog = oxNew(RequestLog::class);
            // @phpstan-ignore-next-line - RequestLog is from legacy Stripe module
            $requestLog->logExceptionResponse(
                ['payment_intent_id' => $paymentIntentId],
                (int) ($e->getCode() ?: 500),
                $e->getMessage(),
                'cancel_authorization',
                $paymentIntentId
            );
        } catch (\Throwable $logError) {
            $this->logger->warning('Failed to log cancel error', ['error' => $logError->getMessage()]);
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
