<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\EventSystem\Handler;

use OxidEsales\PaymentBase\EventSystem\Handler\HandlerInterface;
use OxidEsales\PaymentBase\EventSystem\EventDispatcherInterface;
use OxidEsales\PaymentBase\Service\FileLoggerInterface;
use OxidEsales\Payments\Stripe\EventSystem\Event\StripePaymentReturnEvent;
use OxidEsales\Payments\Stripe\EventSystem\Event\StripePaymentExecuteEvent;

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
        private EventDispatcherInterface $eventDispatcher,
        private ?FileLoggerInterface $eventLogger = null
    ) {
    }

    public static function getHandledEventClass(): string
    {
        return StripePaymentReturnEvent::class;
    }

    public function handle(object $event): void
    {
        $this->logEvent('StripePaymentReturnHandler::handle() START');

        if (!$event instanceof StripePaymentReturnEvent) {
            $this->logEvent('StripePaymentReturnHandler: Wrong event type, skipping');
            return;
        }

        $context = $event->getContext();
        $paymentIntentId = $event->getPaymentIntentId();

        $this->logEvent('StripePaymentReturnHandler: Processing return', [
            'paymentIntentId' => $paymentIntentId,
            'redirectStatus' => $event->getRedirectStatus(),
        ]);

        if ($paymentIntentId === null) {
            $this->logEvent('StripePaymentReturnHandler: ERROR - Payment information missing');
            $context->set('error', 'Payment information missing');
            $context->set('redirectTarget', 'payment');
            return;
        }

        $redirectStatus = $event->getRedirectStatus();

        // Handle immediate failure based on redirect_status
        if ($redirectStatus === 'failed') {
            $this->logEvent('StripePaymentReturnHandler: Payment failed');
            $context->set('error', 'Payment failed. Please try again.');
            $context->set('redirectTarget', 'payment');
            return;
        }

        // For succeeded or other statuses, dispatch execute event to verify
        $this->logEvent('StripePaymentReturnHandler: Dispatching StripePaymentExecuteEvent');
        $executeEvent = new StripePaymentExecuteEvent($context);
        $this->eventDispatcher->dispatch($executeEvent);

        $this->logEvent('StripePaymentReturnHandler::handle() END', [
            'redirectTarget' => $context->get('redirectTarget'),
        ]);
    }

    /**
     * Log event to file logger for debugging.
     *
     * @param string $message
     * @param array<string, mixed> $context
     */
    private function logEvent(string $message, array $context = []): void
    {
        if ($this->eventLogger !== null) {
            $this->eventLogger->log($message, $context);
        }
    }
}
