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
use OxidEsales\Eshop\Core\UtilsObject;
use OxidEsales\Eshop\Core\DatabaseProvider;
use OxidSolutionCatalysts\Payments\Stripe\Service\ModuleConfigurationService;
use OxidSolutionCatalysts\Payments\Stripe\Service\StripeCustomerService;
use OxidSolutionCatalysts\Payments\Component\Service\Factory\PaymentAdapterFactory;
use OxidSolutionCatalysts\Payments\Component\Adapter\Request\CreatePaymentRequest;
use OxidSolutionCatalysts\Payments\Component\Adapter\Exception\PaymentAdapterException;
use OxidSolutionCatalysts\Payments\Component\Repository\TransactionRepositoryInterface;
use OxidSolutionCatalysts\Payments\Component\Transaction\Transaction;
use OxidSolutionCatalysts\Payments\Component\EventSystem\EventDispatcherInterface;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\EventContext;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\Payment\OrderCreatedEvent;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\Payment\PaymentCapturedEvent;

/**
 * Extended order controller for Stripe payment processing
 * ✅ Uses StripeAdapter via PaymentAdapterFactory (SDK-Adapter pattern)
 * ✅ Uses standard Order::finalizeOrder() method for compatibility
 * ✅ Follows Single Responsibility Principle
 */
class OrderController extends CoreOrderController
{
    public function __construct(
        private readonly PaymentAdapterFactory $adapterFactory,
        private readonly ModuleConfigurationService $config,
        private readonly StripeCustomerService $customerService,
        private readonly TransactionRepositoryInterface $transactionRepository,
        private readonly ?EventDispatcherInterface $eventDispatcher = null
    )
    {
        parent::__construct();
    }

    /**
     * Render order confirmation page
     * Creates PaymentIntent for Stripe if Stripe payment is selected
     *
     * @return string Template name
     */
    public function render()
    {
        $template = parent::render();

        // Debug: Log payment selection
        $session = Registry::getSession();
        $basket = $session->getBasket();
        $currentPaymentId = $basket ? $basket->getPaymentId() : null;

        Registry::getLogger()->debug('OrderController render() - Payment check', [
            'payment_id' => $currentPaymentId,
            'is_stripe' => $this->isStripePayment(),
            'basket_exists' => (bool)$basket
        ]);

        // Only process if Stripe payment is selected
        if ($this->isStripePayment()) {
            $user = $basket->getBasketUser();

            Registry::getLogger()->debug('Stripe payment detected, checking user', [
                'user_exists' => (bool)$user,
                'user_id' => $user ? $user->getId() : null
            ]);

            if ($user && $user->getId()) {
                try {
                    // Create adapter
                    $adapter = $this->adapterFactory->createDefaultAdapter();

                    $createRequest = new CreatePaymentRequest(
                        amount: 100.00,
                        currency: 'EUR',
                        orderId: '123',
                        shopId: '1',
                        paymentMethod: 'card',
                        directCapture: false,
                        metadata: ['test_3ds' => 'initiation']
                    );

                    $response = $adapter->createPayment($createRequest);

            /*        // Check if we already have a PaymentIntent in session
                    $existingIntentId = $session->getVariable('stripe_payment_intent_id');

                    if ($existingIntentId) {
                        // Try to retrieve existing PaymentIntent
                        try {
                            $paymentDetails = $adapter->getPaymentDetails($existingIntentId);

                            // Verify amount matches current basket
                            $basketAmount = $basket->getPrice()->getBruttoPrice();
                            if (abs($paymentDetails->amount - $basketAmount) > 0.01) {
                                // Amount changed, create new PaymentIntent
                                $response = $this->createPaymentViaAdapter($adapter, $basket, $user);
                                $session->setVariable('stripe_payment_intent_id', $response->providerPaymentId);
                                $this->addTplParam('stripeClientSecret', $response->clientSecret);

                                Registry::getLogger()->info('Created new PaymentIntent due to amount change', [
                                    'payment_intent_id' => $response->providerPaymentId,
                                    'old_amount' => $paymentDetails->amount,
                                    'new_amount' => $basketAmount
                                ]);
                            } else {
                                // Existing PaymentIntent is valid
                                $this->addTplParam('stripeClientSecret', $paymentDetails->providerData['client_secret'] ?? null);
                            }
                        } catch (PaymentAdapterException $e) {
                            // PaymentIntent not found or invalid, create new one
                            $response = $this->createPaymentViaAdapter($adapter, $basket, $user);
                            $session->setVariable('stripe_payment_intent_id', $response->providerPaymentId);
                            $this->addTplParam('stripeClientSecret', $response->clientSecret);
                        }
                    } else {*/
                        // Create new PaymentIntent
                       // $response = $this->createPaymentViaAdapter($adapter, $basket, $user);
                        $session->setVariable('stripe_payment_intent_id', $response->providerPaymentId);
                        $this->addTplParam('stripeClientSecret', $response->clientSecret);

                        Registry::getLogger()->info('PaymentIntent created for order page', [
                            'payment_intent_id' => $response->providerPaymentId,
                            'amount' => $response->amount,
                            'status' => $response->status
                        ]);
                  //  }

                } catch (PaymentAdapterException $e) {
                    Registry::getLogger()->error('Failed to create/retrieve Stripe PaymentIntent', [
                        'error' => $e->getMessage(),
                        'error_code' => $e->getErrorCode(),
                        'provider' => $e->getProvider()
                    ]);

                    $this->addTplParam('stripeError', $this->getUserFriendlyError($e));
                }
            } else {
                Registry::getLogger()->warning('Stripe payment selected but user not available', [
                    'has_user' => (bool)$user,
                    'has_user_id' => $user ? (bool)$user->getId() : false
                ]);
            }
        } else {
            // Not a Stripe payment - this is expected for other payment methods
            if ($currentPaymentId) {
                Registry::getLogger()->debug('Non-Stripe payment method selected', [
                    'payment_id' => $currentPaymentId
                ]);
            }
        }

        return $template;
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
            // Retrieve and verify payment intent via adapter
            $adapter = $this->adapterFactory->createDefaultAdapter();
            $paymentDetails = $adapter->getPaymentDetails($paymentIntentId);

            // Handle different payment statuses
            switch ($paymentDetails->status) {
                case 'succeeded':
                case 'captured':
                    return $this->handleSuccessfulPayment($paymentIntentId);

                case 'requires_action':
                case 'requires_confirmation':
                    return $this->handle3DSecure($paymentIntentId, $paymentDetails);

                case 'processing':
                    return $this->handleProcessingPayment($paymentIntentId);

                case 'authorized':
                    // Payment authorized but not captured yet
                    // This is valid for manual capture mode
                    return $this->handleSuccessfulPayment($paymentIntentId);

                case 'requires_payment_method':
                case 'cancelled':
                case 'canceled':
                case 'failed':
                default:
                    return $this->handleFailedPayment($paymentDetails);
            }

        } catch (PaymentAdapterException | \RuntimeException $e) {
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
     * ✅ Uses adapter for payment retrieval
     * ✅ Uses repository for transaction storage
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

            // 1. Get payment details via adapter
            $adapter = $this->adapterFactory->createDefaultAdapter();
            $paymentDetails = $adapter->getPaymentDetails($paymentIntentId);

            // 2. Verify payment succeeded
            if (!in_array($paymentDetails->status, ['captured', 'succeeded', 'authorized'])) {
                throw new \RuntimeException('Payment not successful: ' . $paymentDetails->status);
            }

            // 3. Create order using STANDARD OXID METHOD
            $basket->setPayment('osc_stripe_card');
            $order = oxNew(Order::class);
            $orderState = $order->finalizeOrder($basket, $user);

            if ($orderState !== Order::ORDER_STATE_OK) {
                throw new \RuntimeException('Order creation failed with state: ' . $orderState);
            }

            // 4. Store transaction and Stripe-specific details
            $this->storeTransactionAndDetails($order, $paymentDetails);

            // 5. Dispatch events
            $this->dispatchOrderCreatedEvent($order, $paymentIntentId);
            if ($paymentDetails->isCaptured) {
                $this->dispatchPaymentCapturedEvent($order, $paymentDetails);
            }

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

        } catch (\RuntimeException | PaymentAdapterException $e) {
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
     * @param \OxidSolutionCatalysts\Payments\Component\Adapter\Response\PaymentDetailsResponse $paymentDetails
     * @return string
     */
    private function handle3DSecure(string $paymentIntentId, $paymentDetails): string
    {
        // Store payment intent ID for after 3DS redirect
        Registry::getSession()->setVariable('stripe_payment_intent_id', $paymentIntentId);

        // Pass 3DS data to template
        $this->addTplParam('stripe3DSRequired', true);
        $this->addTplParam('stripeClientSecret', $paymentDetails->providerData['client_secret'] ?? null);
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
     * @param \OxidSolutionCatalysts\Payments\Component\Adapter\Response\PaymentDetailsResponse $paymentDetails
     * @return string
     */
    private function handleFailedPayment($paymentDetails): string
    {
        $errorMessage = 'Payment failed';

        switch ($paymentDetails->status) {
            case 'requires_payment_method':
                $errorMessage = 'Payment method declined. Please try a different card.';
                break;
            case 'canceled':
            case 'cancelled':
                $errorMessage = 'Payment was canceled. Please try again.';
                break;
            case 'failed':
                $errorMessage = 'Payment failed. Please check your card details.';
                break;
        }

        Registry::getUtilsView()->addErrorToDisplay($errorMessage);

        Registry::getLogger()->warning('Stripe payment failed', [
            'status' => $paymentDetails->status,
            'payment_intent_id' => $paymentDetails->providerPaymentId,
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

    // ==========================================
    // HELPER METHODS (SINGLE RESPONSIBILITY)
    // ==========================================

    /**
     * Create payment via adapter (avoids code duplication)
     *
     * @param \OxidSolutionCatalysts\Payments\Component\Adapter\PaymentAdapterInterface $adapter
     * @param \OxidEsales\Eshop\Application\Model\Basket $basket
     * @param \OxidEsales\Eshop\Application\Model\User $user
     * @return \OxidSolutionCatalysts\Payments\Component\Adapter\Response\PaymentResponse
     * @throws PaymentAdapterException
     */
    private function createPaymentViaAdapter($adapter, $basket, $user)
    {
        $session = Registry::getSession();

        // Get or create Stripe customer
        $customerId = $this->customerService->getOrCreateStripeCustomer($user);

        // Build request
        $request = new CreatePaymentRequest(
            amount: $basket->getPrice()->getBruttoPrice(),
            currency: $basket->getBasketCurrency()->name,
            orderId: $session->getVariable('sess_challenge') ?? 'temp-' . uniqid(),
            shopId: (string) Registry::getConfig()->getShopId(),
            paymentMethod: 'card',
            directCapture: $this->config->getCaptureMode() === 'automatic',
            customerId: $customerId,
            returnUrl: $this->buildReturnUrl(),
            cancelUrl: $this->buildCancelUrl(),
            metadata: [
                'user_id' => $user->getId(),
                'user_email' => $user->getFieldData('oxusername'),
            ]
        );

        // Call adapter
        return $adapter->createPayment($request);
    }

    /**
     * Build return URL for Stripe redirects (3DS, etc.)
     *
     * @return string
     */
    private function buildReturnUrl(): string
    {
        $shopUrl = Registry::getConfig()->getShopUrl();
        return $shopUrl . 'index.php?cl=order&fnc=stripeReturn';
    }

    /**
     * Build cancel URL for payment cancellation
     *
     * @return string
     */
    private function buildCancelUrl(): string
    {
        $shopUrl = Registry::getConfig()->getShopUrl();
        return $shopUrl . 'index.php?cl=payment';
    }

    /**
     * Convert adapter exception to user-friendly message
     *
     * @param PaymentAdapterException $e
     * @return string
     */
    private function getUserFriendlyError(PaymentAdapterException $e): string
    {
        if ($e->isCardDeclined()) {
            return 'Payment method declined. Please try a different card.';
        }

        if ($e->isNetworkError()) {
            return 'Connection error. Please try again in a moment.';
        }

        if ($e->isAuthenticationRequired()) {
            return 'Additional authentication required. Please complete verification.';
        }

        return 'Payment initialization failed. Please try again or contact support.';
    }

    /**
     * Store transaction and Stripe-specific details
     * Uses repository for transaction, direct SQL for Stripe details
     *
     * @param Order $order
     * @param \OxidSolutionCatalysts\Payments\Component\Adapter\Response\PaymentDetailsResponse $paymentDetails
     * @return void
     */
    private function storeTransactionAndDetails(Order $order, $paymentDetails): void
    {
        // 1. Create and save transaction via repository
        $transaction = new Transaction(
            id: UtilsObject::getInstance()->generateUId(),
            shopId: (int) Registry::getConfig()->getShopId(),
            orderId: $order->getId(),
            contractId: null,
            provider: 'stripe',
            type: $paymentDetails->isCaptured ? 'capture' : 'authorization',
            status: $paymentDetails->status,
            amount: $paymentDetails->amount,
            currency: $paymentDetails->currency
        );

        $transaction->setProviderOrderId($paymentDetails->providerPaymentId);
        $transaction->setPaymentMethodId('osc_stripe_card');

        // Extract transaction ID from provider data
        if (isset($paymentDetails->providerData['charges']['data'][0]['id'])) {
            $transaction->setTransactionId($paymentDetails->providerData['charges']['data'][0]['id']);
        }

        // Extract payment method type
        if (isset($paymentDetails->providerData['charges']['data'][0]['payment_method_details']['type'])) {
            $transaction->setPaymentMethodType(
                $paymentDetails->providerData['charges']['data'][0]['payment_method_details']['type']
            );
        }

        $this->transactionRepository->save($transaction);

        // 2. Store Stripe-specific details in separate table
        $this->storeStripeSpecificDetails($transaction->getId(), $paymentDetails);

        // 3. Update payment order state
        $this->updatePaymentOrderState($order->getId(), $paymentDetails);
    }

    /**
     * Store Stripe-specific payment details
     *
     * @param string $transactionId
     * @param \OxidSolutionCatalysts\Payments\Component\Adapter\Response\PaymentDetailsResponse $paymentDetails
     * @return void
     */
    private function storeStripeSpecificDetails(string $transactionId, $paymentDetails): void
    {
        $charge = $paymentDetails->providerData['charges']['data'][0] ?? null;

        if (!$charge) {
            return;
        }

        $db = DatabaseProvider::getDb();
        $card = $charge['payment_method_details']['card'] ?? null;
        $threeDSecure = $card['three_d_secure'] ?? null;

        $sql = "INSERT INTO osc_stripe_payment_details
                (OXID, OXTRANSACTIONID, OXCARDLAST4, OXCARDBRAND, OXCARDEXPMONTH, OXCARDEXPYEAR,
                 OXCARDFUNDING, OXCARDCOUNTRY, OX3DSECURE, OX3DSVERSION, OX3DSAUTHENTICATED,
                 OXRISKSCORE, OXRISKLEVEL, OXCREATED)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

        $db->execute($sql, [
            UtilsObject::getInstance()->generateUId(),
            $transactionId,
            $card['last4'] ?? null,
            $card['brand'] ?? null,
            $card['exp_month'] ?? null,
            $card['exp_year'] ?? null,
            $card['funding'] ?? null,
            $card['country'] ?? null,
            $threeDSecure ? 1 : 0,
            $threeDSecure['version'] ?? null,
            $threeDSecure['authenticated'] ?? null,
            $charge['outcome']['risk_score'] ?? null,
            $charge['outcome']['risk_level'] ?? null,
        ]);
    }

    /**
     * Update payment order state table
     *
     * @param string $orderId
     * @param \OxidSolutionCatalysts\Payments\Component\Adapter\Response\PaymentDetailsResponse $paymentDetails
     * @return void
     */
    private function updatePaymentOrderState(string $orderId, $paymentDetails): void
    {
        $db = DatabaseProvider::getDb();

        $sql = "INSERT INTO osc_payment_order_state
                (OXID, OXORDERID, OXPAYMENTSTATE, OXPAYMENTMETHOD, OXCAPTURED,
                 OXCAPTUREDAMOUNT, OXCAPTUREDAT, OXCREATED)
                VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                OXPAYMENTSTATE = VALUES(OXPAYMENTSTATE),
                OXCAPTURED = VALUES(OXCAPTURED),
                OXCAPTUREDAMOUNT = VALUES(OXCAPTUREDAMOUNT),
                OXCAPTUREDAT = VALUES(OXCAPTUREDAT),
                OXUPDATED = NOW()";

        $db->execute($sql, [
            UtilsObject::getInstance()->generateUId(),
            $orderId,
            'paid',
            'stripe',
            $paymentDetails->isCaptured ? 1 : 0,
            $paymentDetails->amountCaptured ?? $paymentDetails->amount,
        ]);
    }

    /**
     * Dispatch OrderCreatedEvent
     *
     * @param Order $order
     * @param string $paymentIntentId
     * @return void
     */
    private function dispatchOrderCreatedEvent(Order $order, string $paymentIntentId): void
    {
        if (!$this->eventDispatcher) {
            return;
        }

        $session = Registry::getSession();
        $basket = $session->getBasket();
        $user = $basket->getBasketUser();

        $context = new EventContext([
            'basket' => $basket,
            'user' => $user,
            'orderId' => $order->getId(),
            'paymentIntentId' => $paymentIntentId,
        ]);

        $event = new OrderCreatedEvent(
            context: $context,
            orderId: $order->getId(),
            contractId: '' // Standard checkout doesn't use contracts
        );

        $this->eventDispatcher->dispatch($event);
    }

    /**
     * Dispatch PaymentCapturedEvent
     *
     * @param Order $order
     * @param \OxidSolutionCatalysts\Payments\Component\Adapter\Response\PaymentDetailsResponse $paymentDetails
     * @return void
     */
    private function dispatchPaymentCapturedEvent(Order $order, $paymentDetails): void
    {
        if (!$this->eventDispatcher) {
            return;
        }

        $charge = $paymentDetails->providerData['charges']['data'][0] ?? null;

        if (!$charge) {
            return;
        }

        $context = new EventContext([
            'orderId' => $order->getId(),
            'paymentIntentId' => $paymentDetails->providerPaymentId,
        ]);

        $event = new PaymentCapturedEvent(
            context: $context,
            authorizationId: $paymentDetails->providerPaymentId,
            captureId: $charge['id'],
            capturedAmount: $paymentDetails->amountCaptured ?? $paymentDetails->amount,
            currency: $paymentDetails->currency
        );

        $this->eventDispatcher->dispatch($event);
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
            // Retrieve payment intent via adapter to check status
            $adapter = $this->adapterFactory->createDefaultAdapter();
            $paymentDetails = $adapter->getPaymentDetails($paymentIntentId);

            Registry::getLogger()->info('Payment Intent status on return', [
                'payment_intent_id' => $paymentIntentId,
                'status' => $paymentDetails->status
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

        } catch (PaymentAdapterException | \Exception $e) {
            Registry::getLogger()->error('Error processing Stripe return', [
                'error' => $e->getMessage(),
                'payment_intent_id' => $paymentIntentId
            ]);

            Registry::getUtilsView()->addErrorToDisplay('Payment processing error. Please contact support.');
            return 'payment';
        }
    }
}
