<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Stripe\Controller;

use OxidEsales\Eshop\Application\Controller\OrderController as CoreOrderController;
use OxidEsales\Eshop\Core\Registry;
use OxidEsales\Eshop\Application\Model\Order;
use OxidSolutionCatalysts\Payments\Stripe\Service\StripePaymentService;

/**
 * Extended order controller for Stripe payment processing
 * ✅ Uses standard Order::finalizeOrder() method for compatibility
 */
class OrderController extends CoreOrderController
{
    private StripePaymentService $paymentService;

    /**
     * Initialize services
     */
    public function init(): void
    {
        parent::init();

        $this->paymentService = Registry::get(StripePaymentService::class);
    }

    /**
     * Main order execution method
     * ✅ Uses standard OXID finalizeOrder() for compatibility with other modules
     *
     * @return string Next page (thankyou, payment, or order)
     */
    public function execute()
    {
        // Check if Stripe payment
        if ($this->isStripePayment()) {
            return $this->executeStripePayment();
        }

        // Standard OXID payment flow for other payment methods
        return parent::execute();
    }

    /**
     * Execute Stripe payment flow
     * ✅ USES STANDARD finalizeOrder() METHOD
     *
     * @return string
     */
    private function executeStripePayment(): string
    {
        $session = Registry::getSession();
        $basket = $session->getBasket();

        // Validate basket
        if (!$basket || $basket->getProductsCount() == 0) {
            Registry::getUtilsView()->addErrorToDisplay('Basket is empty');
            return 'basket';
        }

        // Get payment intent ID from request or session
        $paymentIntentId = Registry::getRequest()->getRequestParameter('payment_intent_id');

        if (!$paymentIntentId) {
            $paymentIntentId = $session->getVariable('stripe_payment_intent_id');
        }

        if (!$paymentIntentId) {
            Registry::getLogger()->error('Missing payment_intent_id');
            Registry::getUtilsView()->addErrorToDisplay('Payment information missing');
            return 'payment';
        }

        try {
            // Retrieve and verify payment intent
            $paymentIntent = $this->paymentService->getPaymentIntent($paymentIntentId);

            // Handle different payment statuses
            switch ($paymentIntent['status']) {
                case 'succeeded':
                    return $this->handleSuccessfulPayment($paymentIntentId);

                case 'requires_action':
                case 'requires_confirmation':
                    return $this->handle3DSecure($paymentIntentId, $paymentIntent);

                case 'processing':
                    return $this->handleProcessingPayment($paymentIntentId);

                case 'requires_payment_method':
                case 'canceled':
                case 'failed':
                default:
                    return $this->handleFailedPayment($paymentIntent);
            }

        } catch (\RuntimeException $e) {
            Registry::getLogger()->error('Stripe payment execution failed', [
                'error' => $e->getMessage(),
                'payment_intent_id' => $paymentIntentId,
            ]);

            Registry::getUtilsView()->addErrorToDisplay(
                'Payment processing failed. Please try again.'
            );

            return 'payment';
        }
    }

    /**
     * Handle successful payment
     * ✅ USES STANDARD Order::finalizeOrder() METHOD
     *
     * @param string $paymentIntentId
     * @return string
     */
    private function handleSuccessfulPayment(string $paymentIntentId): string
    {
        try {
            $session = Registry::getSession();
            $basket = $session->getBasket();
            $user = $basket->getBasketUser();

            if (!$user || !$user->getId()) {
                throw new \RuntimeException('User not found');
            }

            // ✅ USE STANDARD OXID METHOD - Critical for compatibility!
            // This is where ALL other modules hook in!
            $order = $this->paymentService->createOrderAfterPayment(
                $basket,
                $user,
                $paymentIntentId
            );

            // Set order ID in session for thank you page
            $session->setVariable('sess_challenge', $order->getId());

            // Clear Stripe session variables
            $session->deleteVariable('stripe_payment_intent_id');
            $session->deleteVariable('stripe_client_secret');

            Registry::getLogger()->info('Stripe payment successful, order created', [
                'order_id' => $order->getId(),
                'order_number' => $order->getFieldData('oxordernr'),
                'payment_intent_id' => $paymentIntentId,
            ]);

            // Redirect to thank you page
            return 'thankyou';

        } catch (\RuntimeException $e) {
            Registry::getLogger()->error('Order creation failed after successful payment', [
                'error' => $e->getMessage(),
                'payment_intent_id' => $paymentIntentId,
            ]);

            Registry::getUtilsView()->addErrorToDisplay(
                'Order could not be created. Please contact support with payment ID: ' . $paymentIntentId
            );

            return 'payment';
        }
    }

    /**
     * Handle 3D Secure authentication requirement
     *
     * @param string $paymentIntentId
     * @param array $paymentIntent
     * @return string
     */
    private function handle3DSecure(string $paymentIntentId, array $paymentIntent): string
    {
        // Store payment intent ID for after 3DS redirect
        Registry::getSession()->setVariable('stripe_payment_intent_id', $paymentIntentId);

        // Pass 3DS data to template
        $this->addTplParam('stripe3DSRequired', true);
        $this->addTplParam('stripeClientSecret', $paymentIntent['client_secret']);
        $this->addTplParam('paymentIntentId', $paymentIntentId);

        Registry::getLogger()->info('3D Secure authentication required', [
            'payment_intent_id' => $paymentIntentId,
        ]);

        // Render 3DS page (will use Stripe.js to handle authentication)
        return 'order';
    }

    /**
     * Handle payment still processing
     *
     * @param string $paymentIntentId
     * @return string
     */
    private function handleProcessingPayment(string $paymentIntentId): string
    {
        Registry::getSession()->setVariable('stripe_payment_intent_id', $paymentIntentId);

        $this->addTplParam('stripeProcessing', true);
        $this->addTplParam('paymentIntentId', $paymentIntentId);

        Registry::getLogger()->info('Payment is processing', [
            'payment_intent_id' => $paymentIntentId,
        ]);

        return 'order';
    }

    /**
     * Handle failed payment
     *
     * @param array $paymentIntent
     * @return string
     */
    private function handleFailedPayment(array $paymentIntent): string
    {
        $errorMessage = 'Payment failed';

        switch ($paymentIntent['status']) {
            case 'requires_payment_method':
                $errorMessage = 'Payment method declined. Please try a different card.';
                break;
            case 'canceled':
                $errorMessage = 'Payment was canceled. Please try again.';
                break;
            case 'failed':
                $errorMessage = 'Payment failed. Please check your card details.';
                break;
        }

        Registry::getUtilsView()->addErrorToDisplay($errorMessage);

        Registry::getLogger()->warning('Stripe payment failed', [
            'status' => $paymentIntent['status'],
            'payment_intent_id' => $paymentIntent['id'],
        ]);

        return 'payment';
    }

    /**
     * Check if current payment is Stripe
     *
     * @return bool
     */
    private function isStripePayment(): bool
    {
        $paymentId = Registry::getSession()->getBasket()->getPaymentId();
        return $paymentId === 'osc_stripe_card';
    }

    /**
     * Handle return from 3D Secure authentication
     * Called when user returns from Stripe 3DS page
     *
     * @return string
     */
    public function return3DS(): string
    {
        $paymentIntentId = Registry::getSession()->getVariable('stripe_payment_intent_id');

        if (!$paymentIntentId) {
            Registry::getUtilsView()->addErrorToDisplay('Payment information missing');
            return 'payment';
        }

        // Re-check payment status after 3DS
        return $this->executeStripePayment();
    }

    /**
     * Handle return from Stripe Payment Element confirmation
     * Called when user is redirected back after Stripe.confirmPayment()
     * Used by Payment Element integration
     *
     * @return string
     */
    public function stripeReturn(): string
    {
        // Get payment_intent and payment_intent_client_secret from URL parameters
        $paymentIntentId = Registry::getRequest()->getRequestParameter('payment_intent');
        $clientSecret = Registry::getRequest()->getRequestParameter('payment_intent_client_secret');
        $redirectStatus = Registry::getRequest()->getRequestParameter('redirect_status');

        Registry::getLogger()->info('Stripe return callback received', [
            'payment_intent' => $paymentIntentId,
            'redirect_status' => $redirectStatus
        ]);

        if (!$paymentIntentId) {
            // Fall back to session
            $paymentIntentId = Registry::getSession()->getVariable('stripe_payment_intent_id');
        }

        if (!$paymentIntentId) {
            Registry::getLogger()->error('No payment_intent in Stripe return');
            Registry::getUtilsView()->addErrorToDisplay('Payment information missing');
            return 'payment';
        }

        // Store in session for executeStripePayment
        Registry::getSession()->setVariable('stripe_payment_intent_id', $paymentIntentId);

        try {
            // Retrieve payment intent to check status
            $paymentIntent = $this->paymentService->getPaymentIntent($paymentIntentId);

            Registry::getLogger()->info('Payment Intent status on return', [
                'payment_intent_id' => $paymentIntentId,
                'status' => $paymentIntent['status']
            ]);

            // Handle based on redirect_status if available
            if ($redirectStatus === 'succeeded') {
                return $this->handleSuccessfulPayment($paymentIntentId);
            } elseif ($redirectStatus === 'failed') {
                Registry::getUtilsView()->addErrorToDisplay('Payment failed. Please try again.');
                return 'payment';
            }

            // Otherwise check actual payment status
            return $this->executeStripePayment();

        } catch (\Exception $e) {
            Registry::getLogger()->error('Error processing Stripe return', [
                'error' => $e->getMessage(),
                'payment_intent_id' => $paymentIntentId
            ]);

            Registry::getUtilsView()->addErrorToDisplay('Payment processing error. Please contact support.');
            return 'payment';
        }
    }
}
