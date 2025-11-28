<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Stripe\EventSystem\Event;

use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\EventContext;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\EventInterface;

/**
 * Event dispatched when 3D Secure authentication is required.
 *
 * This event signals that the payment requires additional customer authentication.
 * The handler should set up the necessary data for Stripe.js to handle 3DS.
 *
 * Handlers:
 * - Stripe3DSHandler: Prepares context for 3DS authentication
 */
readonly class Stripe3DSRequiredEvent implements EventInterface
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
     * Get the client secret for Stripe.js 3DS handling.
     */
    public function getClientSecret(): ?string
    {
        $clientSecret = $this->context->get('clientSecret');
        return is_string($clientSecret) ? $clientSecret : null;
    }

    /**
     * Get the PaymentIntent ID.
     */
    public function getPaymentIntentId(): ?string
    {
        $paymentIntentId = $this->context->get('paymentIntentId');
        return is_string($paymentIntentId) ? $paymentIntentId : null;
    }

    /**
     * Get the return URL after 3DS authentication.
     */
    public function getReturnUrl(): ?string
    {
        $returnUrl = $this->context->get('returnUrl');
        return is_string($returnUrl) ? $returnUrl : null;
    }
}
