<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Stripe\EventSystem\Handler;

use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Handler\HandlerInterface;
use OxidSolutionCatalysts\Payments\Component\EventSystem\EventDispatcherInterface;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\Payment\PaymentAuthorizedEvent;
use OxidSolutionCatalysts\Payments\Component\Repository\ContractRepositoryInterface;
use OxidSolutionCatalysts\Payments\Stripe\Service\Factory\StripeAdapterFactoryInterface;
use OxidSolutionCatalysts\Payments\Stripe\EventSystem\Event\StripeCheckoutReturnEvent;

/**
 * Handles return from Stripe Checkout page.
 *
 * This handler:
 * 1. Retrieves the Checkout Session from Stripe
 * 2. Verifies payment_status is 'paid'
 * 3. Loads the contract using contract_id from metadata
 * 4. Dispatches PaymentAuthorizedEvent to trigger condition fulfillment
 *
 * The PaymentAuthorizedEvent triggers the following chain:
 * - PaymentAuthorizationConditionHandler fulfills 'payment_authorized' condition
 * - If all conditions met → ContractReadyToCommitEvent
 * - OrderCreationHandler creates the order
 *
 * Key difference from Bartek's OrderController::checkoutSuccess():
 * - Uses contract_id from metadata instead of order_id
 * - Order is created AFTER payment verification via event chain
 *
 * NOTE: EventDispatcher is fetched lazily to avoid circular dependency
 * with EventListenerProvider during container initialization.
 */
class StripeCheckoutReturnHandler implements HandlerInterface
{
    public function __construct(
        private ContractRepositoryInterface $contractRepository,
        private StripeAdapterFactoryInterface $adapterFactory
    ) {
    }

    protected function getEventDispatcher(): EventDispatcherInterface
    {
        return ContainerFactory::getInstance()
            ->getContainer()
            ->get(EventDispatcherInterface::class);
    }

    public static function getHandledEventClass(): string
    {
        return StripeCheckoutReturnEvent::class;
    }

    public function handle(object $event): void
    {
        if (!$event instanceof StripeCheckoutReturnEvent) {
            return;
        }

        $context = $event->getContext();
        $sessionId = $event->getCheckoutSessionId();

        if ($sessionId === null) {
            $context->set('error', 'Checkout session ID is missing');
            $context->set('redirectTarget', 'payment');
            return;
        }

        // Retrieve Checkout Session from Stripe
        $stripeClient = $this->adapterFactory->getStripeClient();
        $checkoutSession = $stripeClient->checkout->sessions->retrieve($sessionId, [
            'expand' => ['payment_intent'],
        ]);

        // Verify payment was successful
        if ($checkoutSession->payment_status !== 'paid') {
            $context->set('error', 'Payment not completed: ' . $checkoutSession->payment_status);
            $context->set('redirectTarget', 'payment');
            return;
        }

        // Get contract ID from metadata
        $contractId = $checkoutSession->metadata->contract_id ?? null;

        if ($contractId === null) {
            $context->set('error', 'Contract ID not found in checkout session metadata');
            $context->set('redirectTarget', 'payment');
            return;
        }

        // Load contract
        $contract = $this->contractRepository->findById($contractId);

        if ($contract === null) {
            $context->set('error', 'Contract not found: ' . $contractId);
            $context->set('redirectTarget', 'payment');
            return;
        }

        // Set contract in context
        $context->setContract($contract);
        $context->set('contractId', $contractId);

        // Get PaymentIntent details
        $paymentIntent = $checkoutSession->payment_intent;
        $paymentIntentId = is_string($paymentIntent) ? $paymentIntent : ($paymentIntent->id ?? '');

        $context->set('paymentIntentId', $paymentIntentId);
        $context->set('amount', $checkoutSession->amount_total / 100);
        $context->set('currency', $checkoutSession->currency);

        // Dispatch PaymentAuthorizedEvent
        // This triggers the condition fulfillment chain:
        // PaymentAuthorizationConditionHandler → ContractReadyToCommitEvent → OrderCreationHandler
        $paymentAuthorizedEvent = new PaymentAuthorizedEvent(
            context: $context,
            authorizationId: $paymentIntentId,
            providerOrderId: $sessionId,
            amount: $checkoutSession->amount_total / 100,
            currency: $checkoutSession->currency
        );

        $this->getEventDispatcher()->dispatch($paymentAuthorizedEvent);

        // After event chain completes, check if order was created
        if ($context->get('orderId') !== null) {
            $context->set('redirectTarget', 'thankyou');
        }
    }
}
