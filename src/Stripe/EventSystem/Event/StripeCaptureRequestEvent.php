<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Stripe\EventSystem\Event;

use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\EventContext;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\EventInterface;

/**
 * Event dispatched when a payment capture is requested.
 *
 * This event is emitted when an admin or automated process wants to capture
 * a previously authorized payment (manual capture mode).
 *
 * Multi-channel support: Same event can be emitted from:
 * - Admin backend (OrderRefund controller capture button)
 * - Webhook handler (for external capture triggers)
 * - API controller (for external integrations)
 * - Automated process (e.g., order shipped trigger)
 *
 * Context data:
 * INPUT (set by trigger):
 * - contractId: string - Payment contract ID
 * - orderId: ?string - OXID order ID (if available)
 * - paymentIntentId: ?string - Stripe PaymentIntent ID (if available)
 * - amount: ?float - Capture amount (null = full authorized amount)
 * - initiator: string - Who triggered the capture (admin, webhook, api, cron)
 * - reason: ?string - Optional capture reason for metadata
 *
 * OUTPUT (set by handler):
 * - captureSuccess: bool - Whether capture succeeded
 * - captureId: ?string - Stripe charge ID
 * - capturedAmount: float - Amount actually captured
 * - error: ?string - Error message if failed
 * - errorCode: ?string - Error code if failed
 *
 * @since 2.0.0
 */
readonly class StripeCaptureRequestEvent implements EventInterface
{
    public function __construct(
        private EventContext $context
    ) {
    }

    public function getContext(): EventContext
    {
        return $this->context;
    }

    /**
     * Get the payment contract ID.
     */
    public function getContractId(): ?string
    {
        $contractId = $this->context->get('contractId');
        return is_string($contractId) ? $contractId : null;
    }

    /**
     * Get the OXID order ID (if available).
     */
    public function getOrderId(): ?string
    {
        $orderId = $this->context->get('orderId');
        return is_string($orderId) ? $orderId : null;
    }

    /**
     * Get the Stripe PaymentIntent ID (if provided directly).
     */
    public function getPaymentIntentId(): ?string
    {
        $paymentIntentId = $this->context->get('paymentIntentId');
        return is_string($paymentIntentId) ? $paymentIntentId : null;
    }

    /**
     * Get the capture amount.
     *
     * @return float|null Null means capture full authorized amount
     */
    public function getAmount(): ?float
    {
        $amount = $this->context->get('amount');
        if ($amount === null) {
            return null;
        }
        return is_numeric($amount) ? (float) $amount : null;
    }

    /**
     * Check if this is a full capture request.
     */
    public function isFullCapture(): bool
    {
        return $this->getAmount() === null;
    }

    /**
     * Get the initiator of the capture request.
     *
     * @return string One of: admin, webhook, api, cron
     */
    public function getInitiator(): string
    {
        $initiator = $this->context->get('initiator');
        return is_string($initiator) ? $initiator : 'admin';
    }

    /**
     * Get the capture reason (for metadata).
     */
    public function getReason(): ?string
    {
        $reason = $this->context->get('reason');
        return is_string($reason) ? $reason : null;
    }

    /**
     * Get idempotency key for Stripe API call.
     *
     * If not provided, one will be generated from contractId.
     */
    public function getIdempotencyKey(): ?string
    {
        $key = $this->context->get('idempotencyKey');
        return is_string($key) ? $key : null;
    }
}
