<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Stripe\EventSystem\Handler;

use OxidEsales\Eshop\Application\Model\Order;
use OxidEsales\Eshop\Core\Field;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Handler\HandlerInterface;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\EventContext;
use OxidSolutionCatalysts\Payments\Component\Repository\ContractRepositoryInterface;
use OxidSolutionCatalysts\Payments\Component\Service\FileLoggerInterface;
use OxidSolutionCatalysts\Payments\Stripe\EventSystem\Event\StripeRefundRequestEvent;
use OxidSolutionCatalysts\Payments\Stripe\DTO\RefundResult;
use OxidSolutionCatalysts\Payments\Stripe\Service\RefundServiceInterface;
use OxidSolutionCatalysts\Stripe\Application\Model\RequestLog;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Handles refund requests via Stripe API.
 *
 * Sprint 21: Refactored to delegate to RefundService (SRP).
 *
 * Handler responsibilities (ONLY):
 * 1. Receive event and extract parameters
 * 2. Delegate to RefundService
 * 3. Update order state on success
 * 4. Log request/response
 * 5. Set results in context
 *
 * @since 2.0.0
 */
class StripeRefundRequestHandler implements HandlerInterface
{
    private LoggerInterface $logger;

    public function __construct(
        private readonly RefundServiceInterface $refundService,
        private readonly ContractRepositoryInterface $contractRepository,
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

    private function executeRefund(
        StripeRefundRequestEvent $event,
        string $orderId,
        string $paymentIntentId
    ): RefundResult {
        $chargeId = $event->getChargeId();
        if ($chargeId !== null) {
            $amountCents = $this->convertAmountToCents($event->getAmount());
            return $this->refundService->processRefundByCharge(
                $chargeId,
                $amountCents,
                $event->getReason(),
                $this->buildMetadata($event, $orderId)
            );
        }

        if ($event->isFullRefund()) {
            return $this->refundService->processFullRefund(
                $orderId,
                $paymentIntentId,
                $event->getReason(),
                $event->getDescription(),
                $event->getInitiator()
            );
        }

        $amountCents = $this->convertAmountToCents($event->getAmount());
        if ($amountCents === null) {
            return RefundResult::failure('Invalid refund amount');
        }

        return $this->refundService->processPartialRefund(
            $orderId,
            $amountCents,
            $paymentIntentId,
            $event->getReason(),
            $event->getDescription(),
            $event->getInitiator()
        );
    }

    private function convertAmountToCents(?float $amount): ?int
    {
        if ($amount === null) {
            return null;
        }
        return (int) round($amount * 100);
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

        $this->updateOrderAfterRefund($order, $event);
        $this->updateContractState($event);
        $this->logRefundRequest($result, $order);
        $this->setSuccessResults($context, $result, $order);
    }

    private function updateOrderAfterRefund(Order $order, StripeRefundRequestEvent $event): void
    {
        if (!$event->isFullRefund()) {
            return;
        }

        $order->oxorder__stripedelcostrefunded = new Field($order->oxorder__oxdelcost->value);
        $order->oxorder__stripepaycostrefunded = new Field($order->oxorder__oxpaycost->value);
        $order->oxorder__stripewrapcostrefunded = new Field($order->oxorder__oxwrapcost->value);
        $order->oxorder__stripegiftcardrefunded = new Field($order->oxorder__oxgiftcardcost->value);
        $order->oxorder__stripevoucherdiscountrefunded = new Field($order->oxorder__oxvoucherdiscount->value);
        $order->oxorder__stripediscountrefunded = new Field($order->oxorder__oxdiscount->value);
        $order->save();

        foreach ($order->getOrderArticles() as $orderArticle) {
            $orderArticle->oxorderarticles__stripeamountrefunded = new Field(
                $orderArticle->oxorderarticles__oxbrutprice->value
            );
            $orderArticle->save();
        }
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

    private function logRefundRequest(RefundResult $result, Order $order): void
    {
        try {
            // @phpstan-ignore-next-line - RequestLog is from legacy Stripe module
            $requestLog = oxNew(RequestLog::class);
            // @phpstan-ignore-next-line - RequestLog is from legacy Stripe module
            $requestLog->logRequest(
                ['refund_id' => $result->getRefundId()],
                [
                    'status' => $result->getStatus(),
                    'amount' => $result->getRefundedAmountCents(),
                    'currency' => $result->getCurrency(),
                ],
                $order->getId(),
                (int) \OxidEsales\Eshop\Core\Registry::getConfig()->getShopId()
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to log refund request', ['error' => $e->getMessage()]);
        }
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

    private function logExceptionToRequestLog(\Throwable $e, StripeRefundRequestEvent $event): void
    {
        try {
            $orderId = $event->getOrderId();
            if ($orderId === null) {
                return;
            }

            // @phpstan-ignore-next-line - RequestLog is from legacy Stripe module
            $requestLog = oxNew(RequestLog::class);
            // @phpstan-ignore-next-line - RequestLog is from legacy Stripe module
            $requestLog->logExceptionResponse(
                ['order_id' => $orderId],
                (int) ($e->getCode() ?: 500),
                $e->getMessage(),
                'refund',
                $orderId
            );
        } catch (\Throwable $logError) {
            $this->logger->warning('Failed to log refund error', ['error' => $logError->getMessage()]);
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
