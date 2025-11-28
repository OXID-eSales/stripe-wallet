<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Stripe\EventSystem\Event;

use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\EventContext;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\EventInterface;

/**
 * Event dispatched when Payment Element payment needs to be executed.
 *
 * This event is used in the Payment Element flow (card form on order page)
 * as opposed to the Checkout Session flow.
 *
 * Handlers:
 * - StripePaymentStatusHandler: Verifies payment status, routes to appropriate handler
 */
readonly class StripePaymentExecuteEvent implements EventInterface
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
     * Get the Stripe PaymentIntent ID from context.
     */
    public function getPaymentIntentId(): ?string
    {
        $paymentIntentId = $this->context->get('paymentIntentId');
        return is_string($paymentIntentId) ? $paymentIntentId : null;
    }

    /**
     * Get the client secret for Stripe.js confirmation.
     */
    public function getClientSecret(): ?string
    {
        $clientSecret = $this->context->get('clientSecret');
        return is_string($clientSecret) ? $clientSecret : null;
    }
}
