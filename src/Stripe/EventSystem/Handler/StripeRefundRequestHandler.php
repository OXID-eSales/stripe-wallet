<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\EventSystem\Handler;

use OxidEsales\PaymentBase\Adapter\ShopAdapterInterface;
use OxidEsales\PaymentBase\EventSystem\Event\EventContext;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Service\FileLoggerInterface;
use OxidEsales\Payments\Stripe\EventSystem\Event\StripeRefundRequestEvent;
use OxidEsales\PaymentBase\Adapter\Response\RefundResponse;
use OxidEsales\Payments\Stripe\Service\ContractRefundRecorder;
use OxidEsales\Payments\Stripe\Service\PaymentIntentResolver;
use OxidEsales\Payments\Stripe\Service\RefundServiceInterface;
use OxidEsales\PaymentBase\Service\RequestLogServiceInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Handles refund requests via Stripe API (full and partial).
 *
 * Handler responsibilities (ONLY):
 * 1. Receive event and extract parameters
 * 2. Resolve PaymentIntent ID via agnostic PaymentIntentResolver (A3: no oxNew(Order))
 * 3. Delegate to RefundService
 * 4. Delegate logging to RequestLogService
 * 5. Set results in context
 *
 * Sprint 114.10a (A3): Replaced oxNew(Order) + oxorder__oxtransid lookup with
 * PaymentIntentResolver to mirror the agnostic PI resolution in capture/cancel handlers.
 *
 * @since 2.0.0
 */
class StripeRefundRequestHandler extends AbstractStripeRequestHandler
{
    private LoggerInterface $logger;

    public function __construct(
        private readonly RefundServiceInterface $refundService,
        private readonly ContractRepositoryInterface $contractRepository,
        private readonly RequestLogServiceInterface $requestLogService,
        private readonly ShopAdapterInterface $shopAdapter,
        ?LoggerInterface $logger = null,
        ?FileLoggerInterface $eventLogger = null,
        private readonly ?ContractRefundRecorder $refundRecorder = null,
        private readonly ?PaymentIntentResolver $paymentIntentResolver = null
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->eventLogger = $eventLogger;
    }

    public static function getHandledEventClass(): string
    {
        return StripeRefundRequestEvent::class;
    }

    public function handle(object $event): void
    {
        $this->logEvent('StripeRefundRequestHandler::handle() START');

        if (!$event instanceof StripeRefundRequestEvent) {
            $this->logEvent('StripeRefundRequestHandler: Wrong event type, skipping');
            return;
        }

        $context = $event->getContext();

        try {
            $this->logEvent('StripeRefundRequestHandler: Processing refund', [
                'orderId' => $event->getOrderId(),
                'amount' => $event->getAmount(),
                'isFullRefund' => $event->isFullRefund(),
            ]);
            $this->processRefund($event, $context);
            $this->logEvent('StripeRefundRequestHandler::handle() END - SUCCESS');
        } catch (\Throwable $e) {
            $this->logEvent('StripeRefundRequestHandler: EXCEPTION', [
                'error' => $e->getMessage(),
            ]);
            $this->handleException($e, $context, $event);
        }
    }

    private function processRefund(StripeRefundRequestEvent $event, EventContext $context): void
    {
        $orderId = $event->getOrderId();
        if ($orderId === null) {
            $context->set('error', 'Order ID is missing');
            $context->set('refundSuccess', false);
            return;
        }

        $paymentIntentId = $this->resolvePaymentIntentId($event, $context);
        if ($paymentIntentId === null) {
            return;
        }

        $result = $this->executeRefund($event, $orderId, $paymentIntentId);
        $this->handleRefundResult($result, $event, $orderId, $context);
    }

    /**
     * Resolve PaymentIntent ID via agnostic resolver.
     *
     * Mirrors StripeCaptureRequestHandler::getPaymentIntentId and
     * StripeCancelAuthorizationRequestHandler::resolvePaymentIntentId.
     * Priority: explicit event id → contract providerOrderId → contract metadata.
     */
    private function resolvePaymentIntentId(
        StripeRefundRequestEvent $event,
        EventContext $context
    ): ?string {
        $resolver = $this->paymentIntentResolver ?? new PaymentIntentResolver($this->contractRepository);
        try {
            return $resolver->resolve($event->getPaymentIntentId(), $event->getContractId());
        } catch (RuntimeException $e) {
            $context->set('error', $e->getMessage());
            $context->set('refundSuccess', false);
            return null;
        }
    }

    private function executeRefund(
        StripeRefundRequestEvent $event,
        string $orderId,
        string $paymentIntentId
    ): RefundResponse {
        $chargeId = $event->getChargeId();
        if ($chargeId !== null) {
            return $this->refundService->processRefundByCharge(
                $chargeId,
                $event->getReason(),
                $this->buildMetadata($event, $orderId)
            );
        }

        return $this->refundService->processRefund(
            $orderId,
            $paymentIntentId,
            $event->getReason(),
            $event->getDescription(),
            $event->getInitiator(),
            $event->getAmount()
        );
    }

    /**
     * @return array<string, string>
     */
    private function buildMetadata(StripeRefundRequestEvent $event, string $orderId): array
    {
        $metadata = [
            'order_id' => $orderId,
            'initiator' => $event->getInitiator(),
        ];

        $description = $event->getDescription();
        if ($description !== null) {
            $metadata['description'] = $description;
        }

        return $metadata;
    }

    private function handleRefundResult(
        RefundResponse $result,
        StripeRefundRequestEvent $event,
        string $orderId,
        EventContext $context
    ): void {
        if (!$result->isSuccessful()) {
            $context->set('error', $result->errorMessage);
            $context->set('errorCode', $result->errorCode);
            $context->set('refundSuccess', false);
            return;
        }

        $this->updateContractState($event);
        $this->logRefundRequest($result, $orderId);
        $this->setSuccessResults($context, $result, $orderId);
    }

    protected function updateContractState(StripeRefundRequestEvent $event): void
    {
        $contractId = $event->getContractId();
        if ($contractId === null) {
            return;
        }

        $contract = $this->contractRepository->findById($contractId);
        if ($contract === null) {
            return;
        }

        $refundAmount = $event->getAmount() ?? $contract->getAmount();
        $recorder = $this->refundRecorder ?? new ContractRefundRecorder($this->contractRepository, $this->logger);
        $recorder->record($contract, $refundAmount, $contractId);
    }

    /**
     * Log refund request to request log.
     *
     * Sprint 8: Now delegates to RequestLogService (Facade pattern).
     * Sprint 114.10a (A3): Uses orderId string directly; no longer requires Order object.
     */
    private function logRefundRequest(RefundResponse $result, string $orderId): void
    {
        $this->requestLogService->logRequest(
            action: 'refund',
            request: ['refund_id' => $result->refundId],
            response: [
                'status' => $result->status,
                'amount' => $result->amountRefunded,
                'currency' => $result->currency,
            ],
            referenceId: $orderId,
            shopId: (int) $this->shopAdapter->getShopId()
        );
    }

    private function setSuccessResults(EventContext $context, RefundResponse $result, string $orderId): void
    {
        $context->set('refundSuccess', true);
        $context->set('refundId', $result->refundId);
        $context->set('refundedAmount', $result->amountRefunded);
        $context->set('refundStatus', $result->status);
        $context->set('refundCurrency', $result->currency);

        $this->logger->info('Refund processed successfully', [
            'refund_id' => $result->refundId,
            'amount' => $result->amountRefunded,
            'order_id' => $orderId,
        ]);
    }

    private function handleException(
        \Throwable $e,
        EventContext $context,
        StripeRefundRequestEvent $event
    ): void {
        $context->set('error', $e->getMessage());
        $context->set('refundSuccess', false);

        $this->logger->error('Refund handler exception', [
            'error' => $e->getMessage(),
            'order_id' => $event->getOrderId(),
        ]);

        $this->logExceptionToRequestLog($e, $event);
    }

    /**
     * Log exception to request log.
     *
     * Sprint 8: Now delegates to RequestLogService (Facade pattern).
     */
    private function logExceptionToRequestLog(\Throwable $e, StripeRefundRequestEvent $event): void
    {
        $orderId = $event->getOrderId();
        if ($orderId === null) {
            return;
        }

        $this->requestLogService->logException(
            action: 'refund',
            exception: $e,
            referenceId: $orderId,
            shopId: (int) $this->shopAdapter->getShopId()
        );
    }
}
