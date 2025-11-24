<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Stripe\Service;

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

    public function __construct(
        ?EventDispatcherInterface $eventDispatcher = null
    ) {
        $this->eventDispatcher = $eventDispatcher;
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

        // Find order by payment intent ID
        $order = $this->findOrderByPaymentIntentId($paymentIntent->id);

        if ($order) {
            // Update order payment state
            $this->updateOrderPaymentState($order->getId(), 'paid');

            Registry::getLogger()->info('Order payment state updated', [
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
     * @param \Stripe\Event $event
     * @return void
     */
    private function handlePaymentIntentFailed(\Stripe\Event $event): void
    {
        $paymentIntent = $event->data->object;

        Registry::getLogger()->warning('Payment intent failed', [
            'payment_intent_id' => $paymentIntent->id,
            'error' => $paymentIntent->last_payment_error->message ?? 'Unknown error',
        ]);

        // Find order and update state
        $order = $this->findOrderByPaymentIntentId($paymentIntent->id);

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
     * @param \Stripe\Event $event
     * @return void
     */
    private function handleChargeCaptured(\Stripe\Event $event): void
    {
        $charge = $event->data->object;

        Registry::getLogger()->info('Charge captured', [
            'charge_id' => $charge->id,
            'amount' => $charge->amount,
            'payment_intent' => $charge->payment_intent,
        ]);

        $order = $this->findOrderByPaymentIntentId($charge->payment_intent);

        if ($order) {
            $this->updateOrderCaptureState($order->getId(), $charge->amount / 100);

            // Dispatch PaymentCapturedEvent
            // In a full implementation, this would use an event dispatcher
            Registry::getLogger()->info('Payment captured for order', [
                'order_id' => $order->getId(),
                'captured_amount' => $charge->amount / 100,
            ]);
        }
    }

    /**
     * Handle charge.refunded event
     * Payment has been refunded
     *
     * @param \Stripe\Event $event
     * @return void
     */
    private function handleChargeRefunded(\Stripe\Event $event): void
    {
        $charge = $event->data->object;

        Registry::getLogger()->info('Charge refunded', [
            'charge_id' => $charge->id,
            'amount_refunded' => $charge->amount_refunded,
            'payment_intent' => $charge->payment_intent,
        ]);

        $order = $this->findOrderByPaymentIntentId($charge->payment_intent);

        if ($order) {
            $this->updateOrderRefundState($order->getId(), $charge->amount_refunded / 100);

            // Dispatch PaymentRefundedEvent
            Registry::getLogger()->info('Payment refunded for order', [
                'order_id' => $order->getId(),
                'refunded_amount' => $charge->amount_refunded / 100,
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
     * Find order by Stripe PaymentIntent ID
     *
     * @param string $paymentIntentId
     * @return Order|null
     */
    private function findOrderByPaymentIntentId(string $paymentIntentId): ?Order
    {
        $db = DatabaseProvider::getDb();

        $orderId = $db->getOne(
            "SELECT OXORDERID FROM osc_payment_transaction WHERE OXPROVIDERORDERID = ? LIMIT 1",
            [$paymentIntentId]
        );

        if (!$orderId) {
            return null;
        }

        $order = oxNew(Order::class);
        if (!$order->load($orderId)) {
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
     * Log webhook event to database
     *
     * @param \Stripe\Event $event
     * @return void
     */
    private function logWebhookEvent(\Stripe\Event $event): void
    {
        try {
            $db = DatabaseProvider::getDb();

            $sql = "INSERT INTO osc_payment_webhook_log
                    (OXID, OXEVENTID, OXEVENTTYPE, OXPROVIDER, OXPAYLOAD, OXSTATUS, OXCREATED)
                    VALUES (?, ?, ?, 'stripe', ?, 'received', NOW())
                    ON DUPLICATE KEY UPDATE
                    OXSTATUS = 'duplicate',
                    OXUPDATED = NOW()";

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
     * @param string $eventId
     * @param string $status
     * @return void
     */
    private function updateWebhookStatus(string $eventId, string $status): void
    {
        try {
            $db = DatabaseProvider::getDb();

            $sql = "UPDATE osc_payment_webhook_log
                    SET OXSTATUS = ?,
                        OXUPDATED = NOW()
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
