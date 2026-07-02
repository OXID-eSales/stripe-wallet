<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\EventSystem\Handler;

use OxidEsales\PaymentBase\Adapter\Response\PaymentDetailsResponse;
use OxidEsales\PaymentBase\EventSystem\Handler\HandlerInterface;
use OxidEsales\PaymentBase\EventSystem\EventDispatcherInterface;
use OxidEsales\PaymentBase\EventSystem\Event\EventContext;
use OxidEsales\PaymentBase\EventSystem\Event\Payment\PaymentAuthorizedEvent;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Service\FileLoggerInterface;
use OxidEsales\Payments\Stripe\Service\Factory\StripeAdapterFactoryInterface;
use OxidEsales\Payments\Stripe\EventSystem\Event\StripePaymentExecuteEvent;
use OxidEsales\Payments\Stripe\Adapter\StripeStatusMapper;

/**
 * Handles payment status verification for Payment Element flow.
 *
 * This handler:
 * 1. Retrieves PaymentIntent status via adapter
 * 2. Routes to appropriate handler based on status:
 *    - CAPTURED/AUTHORIZED → PaymentAuthorizedEvent
 *    - REQUIRES_ACTION → sets 3DS context (requires3DS, clientSecret, redirectTarget)
 *    - FAILED/CANCELLED → Error handling
 *
 * Key difference from Checkout Session flow:
 * - Uses PaymentIntent ID directly (not Checkout Session ID)
 * - May need to handle 3DS authentication
 */
class StripePaymentStatusHandler implements HandlerInterface
{
    public function __construct(
        private ContractRepositoryInterface $contractRepository,
        private StripeAdapterFactoryInterface $adapterFactory,
        private EventDispatcherInterface $eventDispatcher,
        private ?FileLoggerInterface $eventLogger = null
    ) {
    }

    public static function getHandledEventClass(): string
    {
        return StripePaymentExecuteEvent::class;
    }

    public function handle(object $event): void
    {
        $this->logEvent('StripePaymentStatusHandler::handle() START');

        if (!$event instanceof StripePaymentExecuteEvent) {
            $this->logEvent('StripePaymentStatusHandler: Wrong event type, skipping');
            return;
        }

        $context = $event->getContext();
        $paymentIntentId = $event->getPaymentIntentId();

        $this->logEvent('StripePaymentStatusHandler: Processing', [
            'paymentIntentId' => $paymentIntentId,
        ]);

        if ($paymentIntentId === null) {
            $this->logEvent('StripePaymentStatusHandler: ERROR - PaymentIntent ID is missing');
            $context->set('error', 'PaymentIntent ID is missing');
            $context->set('redirectTarget', 'payment');
            return;
        }

        // Load contract if contractId is provided
        $contractId = $context->get('contractId');
        if (is_string($contractId) && $contractId !== '') {
            $contract = $this->contractRepository->findById($contractId);
            if ($contract !== null) {
                $context->setContract($contract);
                $this->logEvent('StripePaymentStatusHandler: Contract loaded', [
                    'contractId' => $contractId,
                ]);
            }
        }

        // Get payment status via adapter
        $adapter = $this->adapterFactory->createDefaultAdapter();
        $paymentDetails = $adapter->getPaymentDetails($paymentIntentId);

        $this->logEvent('StripePaymentStatusHandler: Payment details retrieved', [
            'status' => $paymentDetails->status,
            'amount' => $paymentDetails->amount,
        ]);

        // Store payment details in context
        $context->set('paymentDetails', $paymentDetails);
        $context->set('paymentStatus', $paymentDetails->status);
        $context->set('amount', $paymentDetails->amount);
        $context->set('currency', $paymentDetails->currency);

        // Route based on status
        match ($paymentDetails->status) {
            StripeStatusMapper::STATUS_CAPTURED,
            StripeStatusMapper::STATUS_AUTHORIZED =>
                $this->handleSuccess($context, $paymentDetails, $paymentIntentId),

            StripeStatusMapper::STATUS_PENDING =>
                $this->handlePending($context, $paymentDetails, $paymentIntentId),

            default =>
                $this->handleFailure($context, $paymentDetails),
        };

        $this->logEvent('StripePaymentStatusHandler::handle() END', [
            'redirectTarget' => $context->get('redirectTarget'),
        ]);
    }

    private function handleSuccess(
        EventContext $context,
        PaymentDetailsResponse $paymentDetails,
        string $paymentIntentId
    ): void {
        // Dispatch PaymentAuthorizedEvent to trigger condition fulfillment
        $paymentAuthorizedEvent = new PaymentAuthorizedEvent(
            context: $context,
            authorizationId: $paymentIntentId,
            providerOrderId: $paymentIntentId,
            amount: $paymentDetails->amount,
            currency: $paymentDetails->currency
        );

        $this->eventDispatcher->dispatch($paymentAuthorizedEvent);

        // After event chain completes, check if order was created
        if ($context->get('orderId') !== null) {
            $context->set('redirectTarget', 'thankyou');
        }
    }

    private function handlePending(
        EventContext $context,
        PaymentDetailsResponse $paymentDetails,
        string $paymentIntentId
    ): void {
        $stripeStatus = $paymentDetails->providerData['status'] ?? '';

        if (
            in_array($stripeStatus, [
                StripeStatusMapper::STRIPE_REQUIRES_ACTION,
                StripeStatusMapper::STRIPE_REQUIRES_CONFIRMATION
            ], true)
        ) {
            // 3D Secure required — set context data for the frontend directly
            $context->set('requires3DS', true);
            $context->set('clientSecret', $paymentDetails->providerData['client_secret'] ?? null);
            $context->set('redirectTarget', 'order');
            return;
        }

        // Other pending state - treat as failure
        $this->handleFailure($context, $paymentDetails);
    }

    private function handleFailure(
        EventContext $context,
        PaymentDetailsResponse $paymentDetails
    ): void {
        $this->logEvent('StripePaymentStatusHandler: handleFailure', [
            'status' => $paymentDetails->status,
        ]);
        $context->set('error', 'Payment failed: ' . $paymentDetails->status);
        $context->set('redirectTarget', 'payment');
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
