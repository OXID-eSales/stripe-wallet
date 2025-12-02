<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Stripe\EventSystem\Handler;

use OxidEsales\Eshop\Application\Model\Order;
use OxidEsales\Eshop\Core\Field;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Handler\HandlerInterface;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\EventContext;
use OxidSolutionCatalysts\Payments\Component\Repository\ContractRepositoryInterface;
use OxidSolutionCatalysts\Payments\Stripe\EventSystem\Event\StripeRefundRequestEvent;
use OxidSolutionCatalysts\Payments\Stripe\Service\Factory\StripeAdapterFactoryInterface;
use OxidSolutionCatalysts\Stripe\Application\Model\RequestLog;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Stripe\Exception\ApiErrorException;
use Stripe\Refund;

/**
 * Handles refund requests via Stripe API.
 *
 * This handler processes StripeRefundRequestEvent and:
 * 1. Validates the order exists and is refundable
 * 2. Retrieves the charge from Stripe
 * 3. Creates a refund via Stripe API
 * 4. Updates order status in database
 * 5. Logs the request/response
 *
 * FAT Handler Pattern: All refund business logic is here, not in controller.
 *
 * @since 2.0.0
 */
class StripeRefundRequestHandler implements HandlerInterface
{
    /** @var array<string> Valid Stripe refund reasons */
    private const VALID_REASONS = ['duplicate', 'fraudulent', 'requested_by_customer'];

    private LoggerInterface $logger;

    public function __construct(
        private StripeAdapterFactoryInterface $adapterFactory,
        private ContractRepositoryInterface $contractRepository,
        ?LoggerInterface $logger = null
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public static function getHandledEventClass(): string
    {
        return StripeRefundRequestEvent::class;
    }

    public function handle(object $event): void
    {
        if (!$event instanceof StripeRefundRequestEvent) {
            return;
        }

        $context = $event->getContext();

        try {
            // Step 1: Load and validate order
            $order = $this->loadOrder($context);
            if ($order === null) {
                return;
            }

            // Step 2: Get payment intent ID from order
            $paymentIntentId = $this->getPaymentIntentId($order, $context);
            if ($paymentIntentId === null) {
                return;
            }

            // Step 3: Get charge from Stripe
            $chargeId = $this->getChargeId($paymentIntentId, $context);
            if ($chargeId === null) {
                return;
            }

            // Step 4: Build refund parameters
            $refundParams = $this->buildRefundParams($event, $order, $chargeId);

            // Step 5: Execute refund via Stripe API
            $refund = $this->executeStripeRefund($refundParams, $context);
            if ($refund === null) {
                return;
            }

            // Step 6: Update order status
            $this->updateOrderAfterRefund($order, $event, $refund);

            // Step 7: Log success
            $this->logRefundRequest($refundParams, $refund, $order);

            // Step 8: Set success results in context
            $this->setSuccessResults($context, $refund, $order);
        } catch (\Throwable $e) {
            $this->handleException($e, $context, $event);
        }
    }

    private function loadOrder(EventContext $context): ?Order
    {
        $orderId = $context->get('orderId');
        if (!is_string($orderId) || $orderId === '') {
            $context->set('error', 'Order ID is missing');
            $context->set('refundSuccess', false);
            return null;
        }

        $order = oxNew(Order::class);
        if (!$order->load($orderId)) {
            $context->set('error', 'Order not found: ' . $orderId);
            $context->set('refundSuccess', false);
            return null;
        }

        $context->set('order', $order);
        return $order;
    }

    private function getPaymentIntentId(Order $order, EventContext $context): ?string
    {
        // First check if provided directly in context
        $paymentIntentId = $context->get('paymentIntentId');
        if (is_string($paymentIntentId) && $paymentIntentId !== '') {
            return $paymentIntentId;
        }

        // Get from order's transaction ID field
        $transId = $order->oxorder__oxtransid->value ?? null;
        if (!is_string($transId) || $transId === '') {
            $context->set('error', 'Order has no payment transaction ID');
            $context->set('refundSuccess', false);
            return null;
        }

        return $transId;
    }

    private function getChargeId(string $paymentIntentId, EventContext $context): ?string
    {
        // First check if provided directly in context
        $chargeId = $context->get('chargeId');
        if (is_string($chargeId) && $chargeId !== '') {
            return $chargeId;
        }

        try {
            $stripeClient = $this->adapterFactory->getStripeClient();
            $paymentIntent = $stripeClient->paymentIntents->retrieve($paymentIntentId);

            $latestCharge = $paymentIntent->latest_charge ?? null;
            if ($latestCharge === null) {
                $context->set('error', 'No charge found for payment intent');
                $context->set('refundSuccess', false);
                return null;
            }

            return is_string($latestCharge) ? $latestCharge : ($latestCharge->id ?? null);
        } catch (ApiErrorException $e) {
            $context->set('error', 'Failed to retrieve payment intent: ' . $e->getMessage());
            $context->set('refundSuccess', false);
            $this->logger->error('Stripe API error retrieving payment intent', [
                'payment_intent_id' => $paymentIntentId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildRefundParams(
        StripeRefundRequestEvent $event,
        Order $order,
        string $chargeId
    ): array {
        $params = [
            'charge' => $chargeId,
        ];

        // Amount (null = full refund)
        $amount = $event->getAmount();
        if ($amount !== null) {
            // Convert to cents
            $params['amount'] = (int) round($amount * 100);
        } else {
            // Full refund - get total from order
            $totalAmount = (float) ($order->oxorder__oxtotalordersum->value ?? 0);
            $params['amount'] = (int) round($totalAmount * 100);
        }

        // Reason
        $reason = $event->getReason();
        if ($reason !== null && in_array($reason, self::VALID_REASONS, true)) {
            $params['reason'] = $reason;
        }

        // Metadata
        $description = $event->getDescription();
        if ($description !== null) {
            $params['metadata'] = [
                'description' => $description,
                'initiator' => $event->getInitiator(),
                'order_id' => $order->getId(),
            ];
        } else {
            $params['metadata'] = [
                'initiator' => $event->getInitiator(),
                'order_id' => $order->getId(),
            ];
        }

        return $params;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function executeStripeRefund(array $params, EventContext $context): ?Refund
    {
        try {
            $stripeClient = $this->adapterFactory->getStripeClient();
            $refund = $stripeClient->refunds->create($params);

            if ($refund->status !== 'succeeded' && $refund->status !== 'pending') {
                $context->set('error', 'Refund failed with status: ' . $refund->status);
                $context->set('refundSuccess', false);
                return null;
            }

            return $refund;
        } catch (ApiErrorException $e) {
            $context->set('error', $e->getMessage());
            $context->set('errorCode', $e->getStripeCode() ?? 'stripe_error');
            $context->set('refundSuccess', false);

            $this->logger->error('Stripe refund failed', [
                'error' => $e->getMessage(),
                'code' => $e->getStripeCode(),
                'params' => $params,
            ]);

            return null;
        }
    }

    private function updateOrderAfterRefund(
        Order $order,
        StripeRefundRequestEvent $event,
        Refund $refund
    ): void {
        // Mark costs as refunded for full refund
        if ($event->isFullRefund()) {
            $order->oxorder__stripedelcostrefunded = new Field($order->oxorder__oxdelcost->value);
            $order->oxorder__stripepaycostrefunded = new Field($order->oxorder__oxpaycost->value);
            $order->oxorder__stripewrapcostrefunded = new Field($order->oxorder__oxwrapcost->value);
            $order->oxorder__stripegiftcardrefunded = new Field($order->oxorder__oxgiftcardcost->value);
            $order->oxorder__stripevoucherdiscountrefunded = new Field($order->oxorder__oxvoucherdiscount->value);
            $order->oxorder__stripediscountrefunded = new Field($order->oxorder__oxdiscount->value);
            $order->save();

            // Mark all order articles as refunded
            foreach ($order->getOrderArticles() as $orderArticle) {
                $orderArticle->oxorderarticles__stripeamountrefunded = new Field(
                    $orderArticle->oxorderarticles__oxbrutprice->value
                );
                $orderArticle->save();
            }
        }

        // Update contract state if we have one
        $contractId = $event->getContractId();
        if ($contractId !== null) {
            $contract = $this->contractRepository->findById($contractId);
            if ($contract !== null) {
                // If full refund, mark contract as REFUNDED
                if ($event->isFullRefund()) {
                    $contract->setState('REFUNDED');
                    $this->contractRepository->save($contract);
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $params
     */
    private function logRefundRequest(array $params, Refund $refund, Order $order): void
    {
        try {
            $requestLog = oxNew(RequestLog::class);
            $requestLog->logRequest(
                $params,
                $refund->toArray(),
                $order->getId(),
                (int) \OxidEsales\Eshop\Core\Registry::getConfig()->getShopId()
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to log refund request', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function setSuccessResults(EventContext $context, Refund $refund, Order $order): void
    {
        $context->set('refundSuccess', true);
        $context->set('refundId', $refund->id);
        $context->set('refundedAmount', $refund->amount / 100);
        $context->set('refundStatus', $refund->status);
        $context->set('refundCurrency', $refund->currency);

        $this->logger->info('Refund processed successfully', [
            'refund_id' => $refund->id,
            'amount' => $refund->amount / 100,
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
            'trace' => $e->getTraceAsString(),
        ]);

        // Log to RequestLog on error
        try {
            $orderId = $event->getOrderId();
            if ($orderId !== null) {
                $requestLog = oxNew(RequestLog::class);
                $requestLog->logExceptionResponse(
                    ['order_id' => $orderId],
                    (int) ($e->getCode() ?: 500),
                    $e->getMessage(),
                    'refund',
                    $orderId
                );
            }
        } catch (\Throwable $logError) {
            $this->logger->warning('Failed to log refund error', [
                'error' => $logError->getMessage(),
            ]);
        }
    }
}
