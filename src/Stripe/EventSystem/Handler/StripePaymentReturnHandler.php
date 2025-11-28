<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Stripe\EventSystem\Handler;

use OxidSolutionCatalysts\Payments\Component\EventSystem\Handler\HandlerInterface;
use OxidSolutionCatalysts\Payments\Component\EventSystem\EventDispatcherInterface;
use OxidSolutionCatalysts\Payments\Component\Repository\ContractRepositoryInterface;
use OxidSolutionCatalysts\Payments\Stripe\EventSystem\Event\StripePaymentReturnEvent;
use OxidSolutionCatalysts\Payments\Stripe\EventSystem\Event\StripePaymentExecuteEvent;
use OxidSolutionCatalysts\Payments\Stripe\Adapter\StripeStatusMapper;

/**
 * Handles return from Stripe after Payment Element confirmation.
 *
 * This handler:
 * 1. Retrieves PaymentIntent ID from URL parameters
 * 2. Checks redirect_status for immediate failure handling
 * 3. Dispatches StripePaymentExecuteEvent for status verification
 *
 * This is different from StripeCheckoutReturnHandler which handles
 * returns from Stripe Checkout hosted page.
 */
class StripePaymentReturnHandler implements HandlerInterface
{
    public function __construct(
        private ContractRepositoryInterface $contractRepository,
        private EventDispatcherInterface $eventDispatcher
    ) {
    }

    public static function getHandledEventClass(): string
    {
        return StripePaymentReturnEvent::class;
    }

    public function handle(object $event): void
    {
        if (!$event instanceof StripePaymentReturnEvent) {
            return;
        }

        $context = $event->getContext();
        $paymentIntentId = $event->getPaymentIntentId();

        if ($paymentIntentId === null) {
            $context->set('error', 'Payment information missing');
            $context->set('redirectTarget', 'payment');
            return;
        }

        $redirectStatus = $event->getRedirectStatus();

        // Handle immediate failure based on redirect_status
        if ($redirectStatus === 'failed') {
            $context->set('error', 'Payment failed. Please try again.');
            $context->set('redirectTarget', 'payment');
            return;
        }

        // For succeeded or other statuses, dispatch execute event to verify
        $executeEvent = new StripePaymentExecuteEvent($context);
        $this->eventDispatcher->dispatch($executeEvent);
    }
}
