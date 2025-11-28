<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Stripe\EventSystem\Event;

use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\EventContext;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\EventInterface;

/**
 * Event dispatched when a Stripe Checkout Session is requested.
 *
 * This event triggers the creation of a contract and Stripe Checkout Session.
 * No order is created at this point - only the contract captures the intent.
 *
 * Handlers:
 * - ContractCreationHandler: Creates the payment contract
 * - StripeCheckoutSessionHandler: Creates the Stripe Checkout Session
 */
readonly class StripeCheckoutSessionRequestEvent implements EventInterface
{
    public function __construct(
        private EventContext $context
    ) {
    }

    public function getContext(): EventContext
    {
        return $this->context;
    }
}
