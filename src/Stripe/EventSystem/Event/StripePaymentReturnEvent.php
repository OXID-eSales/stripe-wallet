<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Stripe\EventSystem\Event;

use OxidEsales\PaymentComponent\EventSystem\Event\EventContext;
use OxidEsales\PaymentComponent\EventSystem\Event\EventInterface;

/**
 * Event dispatched when customer returns from Stripe after Payment Element confirmation.
 *
 * This event is used when customer is redirected back after Stripe.confirmPayment()
 * for Payment Element integration. Different from StripeCheckoutReturnEvent which
 * handles returns from Stripe Checkout hosted page.
 *
 * Handlers:
 * - StripePaymentReturnHandler: Retrieves payment intent and dispatches execute event
 */
readonly class StripePaymentReturnEvent implements EventInterface
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
     * Get the PaymentIntent ID from context or URL parameters.
     */
    public function getPaymentIntentId(): ?string
    {
        $paymentIntentId = $this->context->get('paymentIntentId');
        return is_string($paymentIntentId) ? $paymentIntentId : null;
    }

    /**
     * Get the redirect status from Stripe URL parameter.
     */
    public function getRedirectStatus(): ?string
    {
        $redirectStatus = $this->context->get('redirectStatus');
        return is_string($redirectStatus) ? $redirectStatus : null;
    }

    /**
     * Get the client secret for verification.
     */
    public function getClientSecret(): ?string
    {
        $clientSecret = $this->context->get('clientSecret');
        return is_string($clientSecret) ? $clientSecret : null;
    }
}
