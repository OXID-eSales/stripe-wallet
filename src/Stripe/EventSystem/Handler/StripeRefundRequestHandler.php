<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\EventSystem\Handler;

use OxidEsales\Eshop\Application\Model\Order;
use OxidEsales\PaymentComponent\Adapter\ShopAdapterInterface;
use OxidEsales\PaymentComponent\EventSystem\Handler\HandlerInterface;
use OxidEsales\PaymentComponent\EventSystem\Event\EventContext;
use OxidEsales\PaymentComponent\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentComponent\Service\FileLoggerInterface;
use OxidEsales\Payments\Stripe\EventSystem\Event\StripeRefundRequestEvent;
use OxidEsales\PaymentComponent\Service\Result\RefundResult;
use OxidEsales\Payments\Stripe\Service\RefundServiceInterface;
use OxidEsales\Payments\Stripe\Service\RequestLogServiceInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Handles refund requests via Stripe API.
 *
 * Sprint 21: Refactored to delegate to RefundService (SRP).
 * Sprint 22: Removed OrderRefundUpdateService (dead code), removed partial refund.
 *
 * Handler responsibilities (ONLY):
 * 1. Receive event and extract parameters
 * 2. Delegate to RefundService (full refund only)
 * 3. Delegate logging to RequestLogService
 * 4. Set results in context
 *
 * @since 2.0.0
 */
class StripeRefundRequestHandler implements HandlerInterface
{
    private LoggerInterface $logger;

    public function __construct(
        private readonly RefundServiceInterface $refundService,
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

        $order = $this->loadOrder($orderId, $context);
        if ($order === null) {
            return;
        }

        $paymentIntentId = $this->getPaymentIntentId($event, $order, $context);
        if ($paymentIntentId === null) {
            return;
        }

        $result = $this->executeRefund($event, $orderId, $paymentIntentId);
        $this->handleRefundResult($result, $event, $order, $context);
    }

    private function loadOrder(string $orderId, EventContext $context): ?Order
    {
        $order = oxNew(Order::class);
        if (!$order->load($orderId)) {
            $context->set('error', 'Order not found: ' . $orderId);
            $context->set('refundSuccess', false);
            return null;
        }

        $context->set('order', $order);
        return $order;
    }

    private function getPaymentIntentId(
        StripeRefundRequestEvent $event,
        Order $order,
        EventContext $context
    ): ?string {
        $paymentIntentId = $event->getPaymentIntentId();
        if ($paymentIntentId !== null) {
            return $paymentIntentId;
        }

        $transId = $order->oxorder__oxtransid->value ?? null;
        if (!is_string($transId) || $transId === '') {
            $context->set('error', 'Order has no payment transaction ID');
            $context->set('refundSuccess', false);
            return null;
        }

        return $transId;
    }

    /**
     * Execute full refund via RefundService.
     *
     * Sprint 22: Stripe module only supports full refunds.
     */
    private function executeRefund(
        StripeRefundRequestEvent $event,
        string $orderId,
        string $paymentIntentId
    ): RefundResult {
        // Stripe module only supports full refunds
        if (!$event->isFullRefund()) {
            return RefundResult::failure(
                'Stripe module only supports full refunds. Use Stripe Dashboard for partial refunds.'
            );
        }

        $chargeId = $event->getChargeId();
        if ($chargeId !== null) {
            return $this->refundService->processRefundByCharge(
                $chargeId,
                $event->getReason(),
                $this->buildMetadata($event, $orderId)
            );
        }

        return $this->refundService->processFullRefund(
            $orderId,
            $paymentIntentId,
            $event->getReason(),
            $event->getDescription(),
            $event->getInitiator()
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
        RefundResult $result,
        StripeRefundRequestEvent $event,
        Order $order,
        EventContext $context
    ): void {
        if (!$result->isSuccessful()) {
            $context->set('error', $result->getErrorMessage());
            $context->set('errorCode', $result->getErrorCode());
            $context->set('refundSuccess', false);
            return;
        }

        $this->updateContractState($event);
        $this->logRefundRequest($result, $order);
        $this->setSuccessResults($context, $result, $order);
    }

    private function updateContractState(StripeRefundRequestEvent $event): void
    {
        $contractId = $event->getContractId();
        if ($contractId === null || !$event->isFullRefund()) {
            return;
        }

        $contract = $this->contractRepository->findById($contractId);
        if ($contract === null) {
            return;
        }

        $contract->setState('REFUNDED');
        $this->contractRepository->save($contract);
    }

    /**
     * Log refund request to request log.
     *
     * Sprint 8: Now delegates to RequestLogService (Facade pattern).
     */
    private function logRefundRequest(RefundResult $result, Order $order): void
    {
        $this->requestLogService->logRequest(
            action: 'refund',
            request: ['refund_id' => $result->getRefundId()],
            response: [
                'status' => $result->getStatus(),
                'amount' => $result->getRefundedAmountCents(),
                'currency' => $result->getCurrency(),
            ],
            referenceId: (string) $order->getId(),
            shopId: (int) $this->shopAdapter->getShopId()
        );
    }

    private function setSuccessResults(EventContext $context, RefundResult $result, Order $order): void
    {
        $context->set('refundSuccess', true);
        $context->set('refundId', $result->getRefundId());
        $context->set('refundedAmount', $result->getRefundedAmount());
        $context->set('refundStatus', $result->getStatus());
        $context->set('refundCurrency', $result->getCurrency());

        $this->logger->info('Refund processed successfully', [
            'refund_id' => $result->getRefundId(),
            'amount' => $result->getRefundedAmount(),
            'order_id' => $order->getId(),
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
