<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Stripe\Component\EventSystem\EventHandler;

use OxidSolutionCatalysts\Payments\Stripe\Component\EventSystem\Event\CheckoutAbandonedEvent;
use Psr\Log\LoggerInterface;

/**
 * CheckoutAbandonedEventHandler - Handles checkout abandonment events
 *
 * This handler is responsible for:
 * - Logging abandonment for analytics
 * - Releasing reserved inventory
 * - Tracking abandonment metrics
 * - Triggering cart recovery workflows
 * - Sending abandonment notifications (if configured)
 */
class CheckoutAbandonedEventHandler
{
    public function __construct(
        private readonly LoggerInterface $logger,
        // Inject additional services as needed:
        // private readonly InventoryService $inventoryService,
        // private readonly AbandonmentTrackingService $trackingService,
        // private readonly EmailService $emailService,
        // private readonly AnalyticsService $analyticsService,
    ) {
    }

    /**
     * Handle CheckoutAbandonedEvent
     *
     * @param CheckoutAbandonedEvent $event
     * @return void
     */
    public function handle(CheckoutAbandonedEvent $event): void
    {
        $this->logger->info('Checkout abandoned', [
            'sessionId' => $event->getSessionId(),
            'customerId' => $event->getCustomerId(),
            'reason' => $event->getReason(),
            'stage' => $event->getAbandonedStage(),
            'cartTotal' => $event->getCartTotal(),
            'hasCompletedAddress' => $event->hasCompletedAddress(),
            'hasAttemptedPayment' => $event->hasAttemptedPayment(),
        ]);

        // Track abandonment metrics
        $this->trackAbandonmentMetrics($event);

        // Release reserved inventory (if any)
        $this->releaseInventory($event);

        // Handle based on abandonment stage
        if ($event->hasCompletedAddress() && $event->getCustomerEmail()) {
            // Customer provided email - can send recovery email
            $this->scheduleRecoveryEmail($event);
        }

        // Cancel any pending payment intents
        if ($event->getContractId()) {
            $this->cancelPendingPayments($event);
        }

        // Track abandonment reason for analytics
        $this->trackAbandonmentReason($event);
    }

    /**
     * Track abandonment metrics for analytics
     */
    private function trackAbandonmentMetrics(CheckoutAbandonedEvent $event): void
    {
        $metrics = [
            'session_id' => $event->getSessionId(),
            'customer_id' => $event->getCustomerId(),
            'reason' => $event->getReason(),
            'stage' => $event->getAbandonedStage(),
            'cart_total' => $event->getCartTotal(),
            'currency' => $event->getCurrency(),
            'cart_items_count' => count($event->getCartItems()),
            'time_spent' => $event->getTimeSpent(),
            'had_address' => $event->hasCompletedAddress(),
            'attempted_payment' => $event->hasAttemptedPayment(),
            'timestamp' => date('Y-m-d H:i:s'),
        ];

        $this->logger->info('Abandonment metrics tracked', $metrics);

        // TODO: Send to analytics service
        // $this->analyticsService->track('checkout_abandoned', $metrics);
    }

    /**
     * Release reserved inventory items
     */
    private function releaseInventory(CheckoutAbandonedEvent $event): void
    {
        $cartItems = $event->getCartItems();

        if (empty($cartItems)) {
            return;
        }

        $this->logger->info('Releasing reserved inventory', [
            'sessionId' => $event->getSessionId(),
            'itemCount' => count($cartItems),
        ]);

        // TODO: Implement inventory release
        // foreach ($cartItems as $item) {
        //     $this->inventoryService->releaseReservation(
        //         $item['productId'],
        //         $item['quantity'],
        //         $event->getSessionId()
        //     );
        // }
    }

    /**
     * Schedule cart recovery email
     */
    private function scheduleRecoveryEmail(CheckoutAbandonedEvent $event): void
    {
        $email = $event->getCustomerEmail();

        if (!$email) {
            return;
        }

        // Only send recovery emails for high-value carts
        $minimumCartValue = 20.00; // Configure as needed

        if ($event->getCartTotal() && $event->getCartTotal() < $minimumCartValue) {
            return;
        }

        $this->logger->info('Scheduling cart recovery email', [
            'email' => $email,
            'cartTotal' => $event->getCartTotal(),
            'sessionId' => $event->getSessionId(),
        ]);

        // TODO: Schedule recovery email
        // Send after 1 hour, 24 hours, and 3 days
        // $this->emailService->scheduleRecoveryEmail(
        //     email: $email,
        //     cartData: $event->getCartItems(),
        //     cartTotal: $event->getCartTotal(),
        //     sessionId: $event->getSessionId(),
        //     delays: [3600, 86400, 259200] // 1h, 24h, 3d
        // );
    }

    /**
     * Cancel any pending payment intents
     */
    private function cancelPendingPayments(CheckoutAbandonedEvent $event): void
    {
        $contractId = $event->getContractId();

        if (!$contractId) {
            return;
        }

        $this->logger->info('Cancelling pending payment intents', [
            'contractId' => $contractId,
            'sessionId' => $event->getSessionId(),
        ]);

        // TODO: Cancel Stripe payment intents
        // $this->paymentService->cancelPendingPayment($contractId);
    }

    /**
     * Track abandonment reason for improving conversion
     */
    private function trackAbandonmentReason(CheckoutAbandonedEvent $event): void
    {
        $reasonStats = [
            'reason' => $event->getReason(),
            'stage' => $event->getAbandonedStage(),
            'metadata' => $event->getMetadata(),
        ];

        $this->logger->info('Abandonment reason tracked', $reasonStats);

        // TODO: Store in analytics database for reporting
        // Common reasons to track:
        // - High shipping costs discovered late
        // - Payment method not supported
        // - Complex checkout process
        // - Technical errors
        // - Price concerns
        // - Trust/security concerns
    }
}
