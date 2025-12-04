<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Stripe\Service;

use DateTimeImmutable;
use OxidEsales\Eshop\Core\Registry;
use OxidEsales\Eshop\Core\DatabaseProvider;
use OxidEsales\Eshop\Core\UtilsObject;
use OxidEsales\Eshop\Application\Model\Order;
use OxidSolutionCatalysts\Payments\Component\EventSystem\EventDispatcherInterface;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\EventContext;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\Payment\WebhookReceivedEvent;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\Payment\PaymentCapturedEvent;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\Payment\PaymentRefundedEvent;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\Payment\PaymentFailedEvent;
use OxidSolutionCatalysts\Payments\Component\Repository\WebhookLogRepositoryInterface;
use OxidSolutionCatalysts\Payments\Component\Webhook\WebhookLog;
use OxidSolutionCatalysts\Payments\Stripe\Handler\WebhookContractFulfillmentHandlerInterface;

/**
 * Webhook processing service
 *
 * This service handles incoming webhook events from Stripe, processes them,
 * and updates order states accordingly. It integrates with the Component EventSystem
 * to enable event-driven architecture.
 *
 * Responsibilities:
 * - Processes webhook events sent by Stripe
 * - Routes events to specific handlers based on event type
 * - Updates order payment states in database
 * - Logs all webhook activity for audit trail
 * - Dispatches Component EventSystem events
 *
 * Handled Stripe Events:
 * - payment_intent.succeeded - Payment confirmed successfully
 * - payment_intent.payment_failed - Payment failed
 * - payment_intent.canceled - Payment canceled
 * - charge.captured - Payment captured (manual capture mode)
 * - charge.refunded - Refund processed
 * - charge.dispute.created - Chargeback/dispute opened
 *
 * Why it's needed:
 * - Stripe requires webhooks for asynchronous payment updates
 * - Orders need to be updated when payment state changes outside checkout flow
 * - Handles edge cases like delayed 3D Secure authentication
 * - Critical for handling refunds and disputes
 * - Enables event-driven architecture via Component EventSystem
 *
 * @package OxidSolutionCatalysts\Payments\Stripe\Service
 * @author OXID eSales AG
 * @since 1.0.0
 */
class WebhookProcessingService
{
    private ?EventDispatcherInterface $eventDispatcher;
    private ?WebhookLogRepositoryInterface $webhookLogRepository;
    private WebhookContractFulfillmentHandlerInterface $contractFulfillmentHandler;

    public function __construct(
        WebhookContractFulfillmentHandlerInterface $contractFulfillmentHandler,
        ?EventDispatcherInterface $eventDispatcher = null,
        ?WebhookLogRepositoryInterface $webhookLogRepository = null
    ) {
        $this->contractFulfillmentHandler = $contractFulfillmentHandler;
        $this->eventDispatcher = $eventDispatcher;
        $this->webhookLogRepository = $webhookLogRepository;
    }

    /**
     * Process webhook event
     * Routes event to appropriate handler based on event type
     *
     * @param \Stripe\Event $event Stripe webhook event
     * @return void
     */
    public function processEvent(\Stripe\Event $event): void
    {
        // Check idempotency - skip if already processed
        if ($this->webhookLogRepository !== null && $this->webhookLogRepository->existsByEventId($event->id)) {
            Registry::getLogger()->info('Webhook event already processed (idempotency check)', [
                'event_id' => $event->id,
            ]);
            return;
        }

        // Log webhook event to database
        $this->logWebhookEvent($event);

        // Dispatch WebhookReceivedEvent for listeners
        $this->dispatchWebhookReceivedEvent($event);

        Registry::getLogger()->info('Processing webhook event', [
            'event_id' => $event->id,
            'event_type' => $event->type,
        ]);

        // Route to specific handler based on event type
        switch ($event->type) {
            case 'payment_intent.succeeded':
                $this->handlePaymentIntentSucceeded($event);
                break;

            case 'payment_intent.payment_failed':
                $this->handlePaymentIntentFailed($event);
                break;

            case 'payment_intent.canceled':
                $this->handlePaymentIntentCanceled($event);
                break;

            case 'charge.captured':
                $this->handleChargeCaptured($event);
                break;

            case 'charge.refunded':
                $this->handleChargeRefunded($event);
                break;

            case 'charge.dispute.created':
                $this->handleDisputeCreated($event);
                break;

            case 'checkout.session.completed':
                $this->handleCheckoutSessionCompleted($event);
                break;

            default:
                Registry::getLogger()->debug('Unhandled webhook event type', [
                    'event_type' => $event->type,
                ]);
        }

        // Update webhook log status
        $this->updateWebhookStatus($event->id, 'processed');
    }

    /**
     * Handle payment_intent.succeeded event
     * Payment has been successfully confirmed
     *
     * Sprint 6: Contract-Aware Webhook Processing
     * First tries to fulfill via contract, falls back to legacy DB update.
     *
     * @param \Stripe\Event $event
     * @return void
     */
    private function handlePaymentIntentSucceeded(\Stripe\Event $event): void
    {
        $paymentIntent = $event->data->object;

        Registry::getLogger()->info('Payment intent succeeded', [
            'payment_intent_id' => $paymentIntent->id,
            'amount' => $paymentIntent->amount,
            'currency' => $paymentIntent->currency,
        ]);

        // Sprint 6: Try contract-aware fulfillment first
        $contractResult = $this->contractFulfillmentHandler->handlePaymentSucceeded($paymentIntent->id);

        if ($contractResult === true) {
            Registry::getLogger()->info('Contract fulfilled via webhook', [
                'payment_intent_id' => $paymentIntent->id,
            ]);
            // Contract fulfilled - order update handled via ContractFulfilledEvent
            // Still update direct order fields for backward compatibility
            $this->updateOrderFieldsAfterContractFulfillment($paymentIntent->id);
            return;
        }

        if ($contractResult === false) {
            Registry::getLogger()->info('Contract already fulfilled (idempotent) or not in COMMITTED state', [
                'payment_intent_id' => $paymentIntent->id,
            ]);
            // Already processed or not ready - skip
            return;
        }

        // Contract not found (null) - use legacy fallback for orders without contracts
        Registry::getLogger()->debug('No contract found, using legacy fallback', [
            'payment_intent_id' => $paymentIntent->id,
        ]);

        $this->processLegacyPaymentSucceeded($paymentIntent);
    }

    /**
     * Update order fields after contract fulfillment.
     *
     * Updates OXPAID, OXTRANSSTATUS, OXTRANSID for backward compatibility
     * even when contract-aware processing is used.
     */
    private function updateOrderFieldsAfterContractFulfillment(string $paymentIntentId): void
    {
        $order = $this->findOrderByPaymentIntentId($paymentIntentId);

        if ($order) {
            $this->updateOrderPaidTimestamp($order->getId());
            $this->updateOrderTransStatus($order->getId(), 'OK');
            $this->updateOrderTransId($order->getId(), $paymentIntentId);
        }
    }

    /**
     * Legacy payment processing for orders created without contracts.
     *
     * @param object $paymentIntent Stripe PaymentIntent object
     */
    private function processLegacyPaymentSucceeded(object $paymentIntent): void
    {
        $order = $this->findOrderByPaymentIntentId($paymentIntent->id);

        if ($order) {
            // Update order payment state
            $this->updateOrderPaymentState($order->getId(), 'paid');

            // Update OXPAID timestamp - payment has been confirmed
            $this->updateOrderPaidTimestamp($order->getId());

            // Update OXTRANSSTATUS to OK
            $this->updateOrderTransStatus($order->getId(), 'OK');

            // Update OXTRANSID with PaymentIntent ID
            $this->updateOrderTransId($order->getId(), $paymentIntent->id);

            Registry::getLogger()->info('Order payment state updated (legacy path)', [
                'order_id' => $order->getId(),
                'order_number' => $order->getFieldData('oxordernr'),
            ]);
        } else {
            Registry::getLogger()->warning('Order not found for payment intent', [
                'payment_intent_id' => $paymentIntent->id,
            ]);
        }
    }

    /**
     * Handle payment_intent.payment_failed event
     * Payment has failed
     *
     * Sprint 6: Contract-Aware Webhook Processing
     *
     * @param \Stripe\Event $event
     * @return void
     */
    private function handlePaymentIntentFailed(\Stripe\Event $event): void
    {
        $paymentIntent = $event->data->object;
        /** @var string $paymentIntentId */
        $paymentIntentId = $paymentIntent->id ?? '';
        /** @var string $failureReason */
        $failureReason = $paymentIntent->last_payment_error->message ?? 'Unknown error';

        Registry::getLogger()->warning('Payment intent failed', [
            'payment_intent_id' => $paymentIntentId,
            'error' => $failureReason,
        ]);

        if ($paymentIntentId === '') {
            return;
        }

        // Sprint 6: Try contract-aware failure handling first
        $contractResult = $this->contractFulfillmentHandler->handlePaymentFailed(
            $paymentIntentId,
            $failureReason
        );

        if ($contractResult !== null) {
            Registry::getLogger()->info('Contract failure handled via webhook', [
                'payment_intent_id' => $paymentIntentId,
                'result' => $contractResult ? 'failed' : 'skipped',
            ]);
        }

        // Always update legacy order state for backward compatibility
        $order = $this->findOrderByPaymentIntentId($paymentIntentId);

        if ($order) {
            $this->updateOrderPaymentState($order->getId(), 'failed');
        }
    }

    /**
     * Handle payment_intent.canceled event
     * Payment has been canceled
     *
     * @param \Stripe\Event $event
     * @return void
     */
    private function handlePaymentIntentCanceled(\Stripe\Event $event): void
    {
        $paymentIntent = $event->data->object;

        Registry::getLogger()->info('Payment intent canceled', [
            'payment_intent_id' => $paymentIntent->id,
        ]);

        $order = $this->findOrderByPaymentIntentId($paymentIntent->id);

        if ($order) {
            $this->updateOrderPaymentState($order->getId(), 'canceled');
        }
    }

    /**
     * Handle charge.captured event
     * Payment has been captured (for manual capture mode)
     *
     * Sprint 6: Contract-Aware Webhook Processing
     *
     * @param \Stripe\Event $event
     * @return void
     */
    private function handleChargeCaptured(\Stripe\Event $event): void
    {
        $charge = $event->data->object;
        /** @var string|null $paymentIntentId */
        $paymentIntentId = $charge->payment_intent ?? null;
        /** @var int $amount */
        $amount = $charge->amount ?? 0;

        Registry::getLogger()->info('Charge captured', [
            'charge_id' => $charge->id ?? '',
            'amount' => $amount,
            'payment_intent' => $paymentIntentId,
        ]);

        if ($paymentIntentId === null || $paymentIntentId === '') {
            Registry::getLogger()->warning('No payment intent ID in charge.captured event');
            return;
        }

        // Sprint 6: Try contract-aware capture handling first
        $contractResult = $this->contractFulfillmentHandler->handleChargeCaptured($paymentIntentId);

        if ($contractResult === true) {
            Registry::getLogger()->info('Contract fulfilled via charge.captured webhook', [
                'payment_intent_id' => $paymentIntentId,
            ]);
            $this->updateOrderFieldsAfterContractFulfillment($paymentIntentId);
            return;
        }

        if ($contractResult === false) {
            Registry::getLogger()->info('Contract already fulfilled (idempotent) for charge.captured', [
                'payment_intent_id' => $paymentIntentId,
            ]);
            return;
        }

        // Legacy fallback
        $order = $this->findOrderByPaymentIntentId($paymentIntentId);
        $capturedAmount = $amount / 100;

        if ($order) {
            $this->updateOrderCaptureState($order->getId(), $capturedAmount);

            // Update OXPAID timestamp - payment has been captured
            $this->updateOrderPaidTimestamp($order->getId());

            // Update OXTRANSSTATUS to OK
            $this->updateOrderTransStatus($order->getId(), 'OK');

            Registry::getLogger()->info('Payment captured for order (legacy path)', [
                'order_id' => $order->getId(),
                'captured_amount' => $capturedAmount,
            ]);
        }
    }

    /**
     * Handle charge.refunded event
     * Payment has been refunded
     *
     * Sprint 6: Contract-Aware Webhook Processing
     *
     * @param \Stripe\Event $event
     * @return void
     */
    private function handleChargeRefunded(\Stripe\Event $event): void
    {
        $charge = $event->data->object;
        /** @var string|null $paymentIntentId */
        $paymentIntentId = $charge->payment_intent ?? null;
        /** @var int $amountRefunded */
        $amountRefunded = $charge->amount_refunded ?? 0;
        $refundedAmount = $amountRefunded / 100;

        Registry::getLogger()->info('Charge refunded', [
            'charge_id' => $charge->id ?? '',
            'amount_refunded' => $refundedAmount,
            'payment_intent' => $paymentIntentId,
        ]);

        if ($paymentIntentId === null || $paymentIntentId === '') {
            Registry::getLogger()->warning('No payment intent ID in charge.refunded event');
            return;
        }

        // Sprint 6: Try contract-aware refund handling first
        $contractResult = $this->contractFulfillmentHandler->handleChargeRefunded(
            $paymentIntentId,
            $refundedAmount
        );

        if ($contractResult !== null) {
            Registry::getLogger()->info('Contract refund processed via webhook', [
                'payment_intent_id' => $paymentIntentId,
                'result' => $contractResult ? 'processed' : 'skipped',
            ]);
        }

        // Always update legacy order state for refund tracking
        $order = $this->findOrderByPaymentIntentId($paymentIntentId);

        if ($order) {
            $this->updateOrderRefundState($order->getId(), $refundedAmount);

            // Update OXPAID timestamp to refund time
            $this->updateOrderPaidTimestamp($order->getId());

            Registry::getLogger()->info('Payment refunded for order', [
                'order_id' => $order->getId(),
                'refunded_amount' => $refundedAmount,
            ]);
        }
    }

    /**
     * Handle charge.dispute.created event
     * A dispute (chargeback) has been created
     *
     * @param \Stripe\Event $event
     * @return void
     */
    private function handleDisputeCreated(\Stripe\Event $event): void
    {
        $dispute = $event->data->object;

        Registry::getLogger()->warning('Dispute created', [
            'dispute_id' => $dispute->id,
            'amount' => $dispute->amount,
            'reason' => $dispute->reason,
            'charge' => $dispute->charge,
        ]);

        // This would typically trigger an email notification to admin
        // and potentially update order status
    }

    /**
     * Handle checkout.session.completed event
     * Checkout session has been completed (used by Stripe Wallet)
     *
     * Sprint 6: Contract-Aware Webhook Processing
     *
     * @param \Stripe\Event $event
     * @return void
     */
    private function handleCheckoutSessionCompleted(\Stripe\Event $event): void
    {
        $session = $event->data->object;

        Registry::getLogger()->info('Checkout session completed', [
            'session_id' => $session->id,
            'payment_intent' => $session->payment_intent ?? null,
            'payment_status' => $session->payment_status ?? null,
        ]);

        // Only process if payment was successful
        $paymentStatus = $session->payment_status ?? '';
        if ($paymentStatus !== 'paid') {
            Registry::getLogger()->debug('Checkout session not paid, skipping', [
                'payment_status' => $paymentStatus,
            ]);
            return;
        }

        // Find order by payment intent ID
        $paymentIntentId = $session->payment_intent ?? null;
        if (!$paymentIntentId) {
            Registry::getLogger()->warning('No payment intent ID in checkout session', [
                'session_id' => $session->id,
            ]);
            return;
        }

        // Sprint 6: Try contract-aware fulfillment first
        $contractResult = $this->contractFulfillmentHandler->handlePaymentSucceeded($paymentIntentId);

        if ($contractResult === true) {
            Registry::getLogger()->info('Contract fulfilled via checkout.session.completed webhook', [
                'payment_intent_id' => $paymentIntentId,
                'session_id' => $session->id,
            ]);
            $this->updateOrderFieldsAfterContractFulfillment($paymentIntentId);
            return;
        }

        if ($contractResult === false) {
            Registry::getLogger()->info('Contract already fulfilled (idempotent) for checkout session', [
                'payment_intent_id' => $paymentIntentId,
            ]);
            return;
        }

        // Legacy fallback for orders without contracts
        $order = $this->findOrderByPaymentIntentId($paymentIntentId);

        if ($order) {
            // Update order payment state
            $this->updateOrderPaymentState($order->getId(), 'paid');

            // Update OXPAID timestamp - payment has been confirmed
            $this->updateOrderPaidTimestamp($order->getId());

            // Update OXTRANSSTATUS to OK
            $this->updateOrderTransStatus($order->getId(), 'OK');

            // Update OXTRANSID with PaymentIntent ID
            $this->updateOrderTransId($order->getId(), $paymentIntentId);

            Registry::getLogger()->info('Order updated from checkout session (legacy path)', [
                'order_id' => $order->getId(),
                'order_number' => $order->getFieldData('oxordernr'),
            ]);
        } else {
            Registry::getLogger()->warning('Order not found for checkout session payment intent', [
                'payment_intent_id' => $paymentIntentId,
                'session_id' => $session->id,
            ]);
        }
    }

    /**
     * Find order by Stripe PaymentIntent ID
     *
     * Searches for order in two places:
     * 1. osc_payment_transaction.OXPROVIDERORDERID (preferred - Component transaction records)
     * 2. oxorder.OXTRANSID (fallback - direct OXID order field)
     *
     * @param string $paymentIntentId
     * @return Order|null
     */
    private function findOrderByPaymentIntentId(string $paymentIntentId): ?Order
    {
        $db = DatabaseProvider::getDb();

        // First try: Look in osc_payment_transaction table
        $orderId = $db->getOne(
            "SELECT OXORDERID FROM osc_payment_transaction WHERE OXPROVIDERORDERID = ? LIMIT 1",
            [$paymentIntentId]
        );

        // Fallback: Look directly in oxorder.OXTRANSID
        if (!$orderId) {
            $orderId = $db->getOne(
                "SELECT OXID FROM oxorder WHERE OXTRANSID = ? LIMIT 1",
                [$paymentIntentId]
            );
        }

        if (!$orderId) {
            Registry::getLogger()->debug('Order not found for PaymentIntent ID', [
                'payment_intent_id' => $paymentIntentId,
            ]);
            return null;
        }

        $order = oxNew(Order::class);
        if (!$order->load($orderId)) {
            Registry::getLogger()->error('Failed to load order', [
                'order_id' => $orderId,
                'payment_intent_id' => $paymentIntentId,
            ]);
            return null;
        }

        return $order;
    }

    /**
     * Update order payment state
     *
     * @param string $orderId
     * @param string $state
     * @return void
     */
    private function updateOrderPaymentState(string $orderId, string $state): void
    {
        $db = DatabaseProvider::getDb();

        $sql = "UPDATE osc_payment_order_state
                SET OXPAYMENTSTATE = ?,
                    OXUPDATED = NOW()
                WHERE OXORDERID = ?";

        $db->execute($sql, [$state, $orderId]);
    }

    /**
     * Update order capture state
     *
     * @param string $orderId
     * @param float $capturedAmount
     * @return void
     */
    private function updateOrderCaptureState(string $orderId, float $capturedAmount): void
    {
        $db = DatabaseProvider::getDb();

        $sql = "UPDATE osc_payment_order_state
                SET OXCAPTURED = 1,
                    OXCAPTUREDAMOUNT = ?,
                    OXCAPTUREDAT = NOW(),
                    OXPAYMENTSTATE = 'paid',
                    OXUPDATED = NOW()
                WHERE OXORDERID = ?";

        $db->execute($sql, [$capturedAmount, $orderId]);
    }

    /**
     * Update order refund state
     *
     * @param string $orderId
     * @param float $refundedAmount
     * @return void
     */
    private function updateOrderRefundState(string $orderId, float $refundedAmount): void
    {
        $db = DatabaseProvider::getDb();

        $sql = "UPDATE osc_payment_order_state
                SET OXREFUNDED = 1,
                    OXREFUNDEDAMOUNT = COALESCE(OXREFUNDEDAMOUNT, 0) + ?,
                    OXREFUNDEDAT = NOW(),
                    OXPAYMENTSTATE = 'refunded',
                    OXUPDATED = NOW()
                WHERE OXORDERID = ?";

        $db->execute($sql, [$refundedAmount, $orderId]);
    }

    /**
     * Update order OXPAID timestamp
     *
     * Sets the OXPAID field in oxorder table to current timestamp.
     * This field stores "Time when order was paid".
     *
     * @param string $orderId
     * @return void
     */
    private function updateOrderPaidTimestamp(string $orderId): void
    {
        try {
            $db = DatabaseProvider::getDb();

            $sql = "UPDATE oxorder SET OXPAID = NOW() WHERE OXID = ?";
            $db->execute($sql, [$orderId]);

            Registry::getLogger()->debug('OXPAID timestamp updated', [
                'order_id' => $orderId,
            ]);
        } catch (\Exception $e) {
            Registry::getLogger()->error('Failed to update OXPAID', [
                'order_id' => $orderId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Update order OXTRANSSTATUS
     *
     * Sets the OXTRANSSTATUS field in oxorder table.
     * Valid values: NOT_FINISHED, OK, ERROR
     *
     * @param string $orderId
     * @param string $status
     * @return void
     */
    private function updateOrderTransStatus(string $orderId, string $status): void
    {
        try {
            $db = DatabaseProvider::getDb();

            $sql = "UPDATE oxorder SET OXTRANSSTATUS = ? WHERE OXID = ?";
            $db->execute($sql, [$status, $orderId]);

            Registry::getLogger()->debug('OXTRANSSTATUS updated', [
                'order_id' => $orderId,
                'status' => $status,
            ]);
        } catch (\Exception $e) {
            Registry::getLogger()->error('Failed to update OXTRANSSTATUS', [
                'order_id' => $orderId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Update order OXTRANSID (transaction ID)
     *
     * Sets the OXTRANSID field in oxorder table to the PaymentIntent ID.
     *
     * @param string $orderId
     * @param string $transactionId
     * @return void
     */
    private function updateOrderTransId(string $orderId, string $transactionId): void
    {
        try {
            $db = DatabaseProvider::getDb();

            // Only update if OXTRANSID is currently empty
            $sql = "UPDATE oxorder SET OXTRANSID = ? WHERE OXID = ? AND (OXTRANSID IS NULL OR OXTRANSID = '')";
            $db->execute($sql, [$transactionId, $orderId]);

            Registry::getLogger()->debug('OXTRANSID updated', [
                'order_id' => $orderId,
                'transaction_id' => $transactionId,
            ]);
        } catch (\Exception $e) {
            Registry::getLogger()->error('Failed to update OXTRANSID', [
                'order_id' => $orderId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Log webhook event to database
     *
     * @param \Stripe\Event $event
     * @return void
     */
    private function logWebhookEvent(\Stripe\Event $event): void
    {
        // Use repository if available (preferred - LSP compliant)
        if ($this->webhookLogRepository !== null) {
            try {
                $log = new WebhookLog(
                    $event->id,
                    new DateTimeImmutable(),
                    'received'
                );
                $log->setEventType($event->type);
                $log->setProvider('stripe');
                /** @var array<string, mixed> $payload */
                $payload = $event->data->object->toArray();
                $log->setPayload($payload);

                $this->webhookLogRepository->save($log);
            } catch (\Exception $e) {
                Registry::getLogger()->error('Failed to log webhook via repository', [
                    'error' => $e->getMessage(),
                ]);
            }
            return;
        }

        // Fallback to raw SQL (legacy - for backward compatibility)
        try {
            $db = DatabaseProvider::getDb();

            $sql = "INSERT INTO osc_payment_webhooklogs
                    (OXID, OXEVENTID, OXEVENTTYPE, OXPROVIDER, OXPAYLOAD, OXSTATUS, OXRECEIVEDAT)
                    VALUES (?, ?, ?, 'stripe', ?, 'received', NOW())
                    ON DUPLICATE KEY UPDATE
                    OXSTATUS = 'duplicate'";

            $db->execute($sql, [
                UtilsObject::getInstance()->generateUId(),
                $event->id,
                $event->type,
                json_encode($event->data->object->toArray()),
            ]);
        } catch (\Exception $e) {
            Registry::getLogger()->error('Failed to log webhook', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Update webhook processing status
     *
     * Sprint 7 Phase 4: Uses WebhookLogRepository for proper layering.
     * Falls back to direct SQL if repository not available.
     *
     * @param string $eventId
     * @param string $status
     * @return void
     */
    private function updateWebhookStatus(string $eventId, string $status): void
    {
        // Sprint 7 Phase 4: Use repository if available
        if ($this->webhookLogRepository !== null) {
            try {
                $this->webhookLogRepository->updateStatus($eventId, $status);
                return;
            } catch (\Exception $e) {
                Registry::getLogger()->error('Failed to update webhook status via repository', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Fallback to direct SQL (legacy)
        try {
            $db = DatabaseProvider::getDb();

            $sql = "UPDATE osc_payment_webhooklogs
                    SET OXSTATUS = ?, OXPROCESSEDAT = NOW()
                    WHERE OXEVENTID = ?";

            $db->execute($sql, [$status, $eventId]);
        } catch (\Exception $e) {
            Registry::getLogger()->error('Failed to update webhook status', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Dispatch WebhookReceivedEvent
     *
     * @param \Stripe\Event $event
     * @return void
     */
    private function dispatchWebhookReceivedEvent(\Stripe\Event $event): void
    {
        if (!$this->eventDispatcher) {
            return;
        }

        $context = new EventContext([
            'eventId' => $event->id,
            'eventType' => $event->type,
        ]);

        $webhookEvent = new WebhookReceivedEvent(
            context: $context,
            provider: 'stripe',
            eventType: $event->type,
            payload: $event->data->object->toArray(),
            signature: '' // Signature already verified by WebhookController
        );

        $this->eventDispatcher->dispatch($webhookEvent);
    }
}
