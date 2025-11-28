<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Stripe\EventSystem\Event;

use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\EventContext;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\EventInterface;

/**
 * Event dispatched when customer returns from Stripe Checkout.
 *
 * This event triggers verification of the payment and fulfillment of
 * the payment_authorized condition, which then triggers order creation.
 *
 * Handlers:
 * - StripeCheckoutReturnHandler: Verifies payment, dispatches PaymentConfirmedEvent
 * - PaymentAuthorizationConditionHandler: Fulfills payment_authorized condition
 * - OrderCreationHandler: Creates order when all conditions met
 */
readonly class StripeCheckoutReturnEvent implements EventInterface
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
     * Get the Stripe Checkout Session ID from context.
     */
    public function getCheckoutSessionId(): ?string
    {
        $sessionId = $this->context->get('checkoutSessionId');
        return is_string($sessionId) ? $sessionId : null;
    }
}
