<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Stripe\EventSystem\Event;

use OxidEsales\PaymentComponent\EventSystem\Event\EventContext;
use OxidEsales\PaymentComponent\EventSystem\Event\EventInterface;

/**
 * Event dispatched when admin requests to cancel a payment authorization.
 *
 * This event is used for manual capture mode orders where the payment
 * has been authorized but not yet captured, and the merchant wants to
 * release the authorization instead of capturing.
 *
 * Context data:
 * INPUT (set by trigger):
 * - orderId: ?string - OXID order ID
 * - paymentIntentId: string - Stripe PaymentIntent ID to cancel
 * - cancellationReason: ?string - Stripe reason (requested_by_customer, duplicate, fraudulent, abandoned)
 * - initiator: string - Who triggered the cancel (admin, webhook, api)
 *
 * OUTPUT (set by handler):
 * - cancelSuccess: bool - Whether cancel succeeded
 * - cancelledPaymentIntentId: ?string - Cancelled PaymentIntent ID
 * - error: ?string - Error message if failed
 *
 * @since 2.0.0
 */
readonly class StripeCancelAuthorizationRequestEvent implements EventInterface
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
     * Get the PaymentIntent ID to cancel.
     */
    public function getPaymentIntentId(): ?string
    {
        $id = $this->context->get('paymentIntentId');
        return is_string($id) ? $id : null;
    }

    /**
     * Get the cancellation reason.
     *
     * Valid Stripe reasons: 'duplicate', 'fraudulent', 'requested_by_customer', 'abandoned'
     */
    public function getCancellationReason(): ?string
    {
        $reason = $this->context->get('cancellationReason');
        return is_string($reason) ? $reason : null;
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
     * Get the initiator of the cancel request.
     *
     * @return string One of: admin, webhook, api
     */
    public function getInitiator(): string
    {
        $initiator = $this->context->get('initiator');
        return is_string($initiator) ? $initiator : 'admin';
    }
}
