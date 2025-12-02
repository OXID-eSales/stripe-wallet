<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Stripe\EventSystem\Event;

use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\EventContext;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\EventInterface;

/**
 * Event dispatched when a refund is requested from admin panel.
 *
 * This event is emitted by OrderRefund admin controller and handled by
 * StripeRefundRequestHandler which processes the refund via Stripe API.
 *
 * Multi-channel support: Same event can be emitted from:
 * - Admin backend (OrderRefund controller)
 * - Webhook handler (for Stripe-initiated refunds)
 * - API controller (for external integrations)
 * - MCP handler (for AI assistant integrations)
 *
 * Context data:
 * INPUT (set by trigger):
 * - orderId: string - OXID order ID
 * - contractId: ?string - Payment contract ID (if available)
 * - amount: ?float - Refund amount (null = full refund)
 * - reason: string - Stripe refund reason (duplicate, fraudulent, requested_by_customer)
 * - description: ?string - Optional description for metadata
 * - initiator: string - Who triggered the refund (admin, webhook, api, mcp)
 *
 * OUTPUT (set by handler):
 * - refundSuccess: bool - Whether refund succeeded
 * - refundId: ?string - Stripe refund ID
 * - refundedAmount: float - Amount actually refunded
 * - error: ?string - Error message if failed
 * - errorCode: ?string - Error code if failed
 *
 * @since 2.0.0
 */
readonly class StripeRefundRequestEvent implements EventInterface
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
     * Get the OXID order ID.
     */
    public function getOrderId(): ?string
    {
        $orderId = $this->context->get('orderId');
        return is_string($orderId) ? $orderId : null;
    }

    /**
     * Get the payment contract ID (if available).
     */
    public function getContractId(): ?string
    {
        $contractId = $this->context->get('contractId');
        return is_string($contractId) ? $contractId : null;
    }

    /**
     * Get the refund amount.
     *
     * @return float|null Null means full refund
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
     * Check if this is a full refund request.
     */
    public function isFullRefund(): bool
    {
        return $this->getAmount() === null;
    }

    /**
     * Get the refund reason.
     *
     * Valid Stripe reasons: duplicate, fraudulent, requested_by_customer
     */
    public function getReason(): ?string
    {
        $reason = $this->context->get('reason');
        return is_string($reason) ? $reason : null;
    }

    /**
     * Get the refund description (for metadata).
     */
    public function getDescription(): ?string
    {
        $description = $this->context->get('description');
        return is_string($description) ? $description : null;
    }

    /**
     * Get the initiator of the refund request.
     *
     * @return string One of: admin, webhook, api, mcp
     */
    public function getInitiator(): string
    {
        $initiator = $this->context->get('initiator');
        return is_string($initiator) ? $initiator : 'admin';
    }

    /**
     * Get the Stripe charge ID (if provided directly).
     */
    public function getChargeId(): ?string
    {
        $chargeId = $this->context->get('chargeId');
        return is_string($chargeId) ? $chargeId : null;
    }

    /**
     * Get the Stripe payment intent ID (if provided directly).
     */
    public function getPaymentIntentId(): ?string
    {
        $paymentIntentId = $this->context->get('paymentIntentId');
        return is_string($paymentIntentId) ? $paymentIntentId : null;
    }
}
