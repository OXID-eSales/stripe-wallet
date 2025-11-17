<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Stripe\Component\EventSystem\EventHandler;

use OxidSolutionCatalysts\Payments\Stripe\Component\EventSystem\Event\PaymentCompletedEvent;
use Psr\Log\LoggerInterface;

/**
 * PaymentCompletedEventHandler - Example handler for payment completion
 *
 * This handler demonstrates subscriber pattern for PaymentCompletedEvent.
 * Multiple handlers can subscribe to the same event:
 * - Email notification handler
 * - Order status update handler
 * - Audit log handler
 * - Fulfillment trigger handler
 */
class PaymentCompletedEventHandler
{
    public function __construct(
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Handle PaymentCompletedEvent
     *
     * This is called automatically by EventDispatcher when
     * PaymentCompletedEvent is dispatched
     */
    public function handle(PaymentCompletedEvent $event): void
    {
        $this->logger->info('Payment completed', [
            'orderId' => $event->getOrderId(),
            'transactionId' => $event->getTransactionId(),
            'amount' => $event->getAmount(),
            'currency' => $event->getCurrency(),
            'status' => $event->getStatus(),
        ]);

        if ($event->isSuccessful()) {
            $this->handleSuccessfulPayment($event);
        } elseif ($event->requiresAction()) {
            $this->handleRequiresAction($event);
        } else {
            $this->handleFailedPayment($event);
        }
    }

    /**
     * Handle successful payment
     */
    private function handleSuccessfulPayment(PaymentCompletedEvent $event): void
    {
        $this->logger->info('Processing successful payment', [
            'orderId' => $event->getOrderId(),
        ]);

        // TODO: Implement actual logic
        // - Send confirmation email
        // - Update order status to 'paid'
        // - Trigger fulfillment workflow
        // - Send to accounting system
        // - Update inventory
    }

    /**
     * Handle payment that requires additional action (e.g., 3D Secure)
     */
    private function handleRequiresAction(PaymentCompletedEvent $event): void
    {
        $this->logger->info('Payment requires action', [
            'orderId' => $event->getOrderId(),
            'metadata' => $event->getMetadata(),
        ]);

        // TODO: Implement actual logic
        // - Return redirect URL for 3D Secure
        // - Update order status to 'pending_authentication'
        // - Send notification to customer
    }

    /**
     * Handle failed payment
     */
    private function handleFailedPayment(PaymentCompletedEvent $event): void
    {
        $this->logger->warning('Payment failed', [
            'orderId' => $event->getOrderId(),
            'metadata' => $event->getMetadata(),
        ]);

        // TODO: Implement actual logic
        // - Send failure notification
        // - Update order status to 'payment_failed'
        // - Offer alternative payment methods
    }
}
