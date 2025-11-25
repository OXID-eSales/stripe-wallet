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
use OxidEsales\Eshop\Application\Model\Basket;
use OxidEsales\Eshop\Application\Model\User;
use OxidSolutionCatalysts\Payments\Stripe\Service\ModuleConfigurationService;
use OxidSolutionCatalysts\Payments\Stripe\Service\StripeCustomerService;
use OxidSolutionCatalysts\Payments\Component\Service\Factory\PaymentAdapterFactory;
use OxidSolutionCatalysts\Payments\Component\Adapter\PaymentAdapterInterface;
use OxidSolutionCatalysts\Payments\Component\Adapter\Request\CreatePaymentRequest;
use OxidSolutionCatalysts\Payments\Component\Adapter\Exception\PaymentAdapterException;
use OxidSolutionCatalysts\Payments\Component\EventSystem\EventDispatcherInterface;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\EventContext;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\Payment\OrderCreatedEvent;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\Payment\PaymentCapturedEvent;
use OxidSolutionCatalysts\Payments\Component\Adapter\ShopOrderServiceInterface;
use OxidSolutionCatalysts\Payments\Component\Adapter\Request\CreateOrderRequest as OrderCreateRequest;
use OxidSolutionCatalysts\Payments\Component\Adapter\Exception\ShopOrderException;
use OxidSolutionCatalysts\Payments\Stripe\Adapter\StripeStatusMapper;

/**
 * Extended order controller for Stripe payment processing
 * ✅ Uses StripeAdapter via PaymentAdapterFactory (SDK-Adapter pattern)
 * ✅ Uses standard Order::finalizeOrder() method for compatibility
 * ✅ Uses unified OxidShopOrderService for all order operations
 * ✅ Follows Single Responsibility Principle
 */
class OrderController extends CoreOrderController
{
    public function __construct(
        private readonly PaymentAdapterFactory $adapterFactory,
        private readonly ModuleConfigurationService $config,
        private readonly StripeCustomerService $customerService,
        private readonly ShopOrderServiceInterface $orderService,
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

            // Handle different payment statuses (normalized)
            switch ($paymentDetails->status) {
                case StripeStatusMapper::STATUS_CAPTURED:
                    return $this->handleSuccessfulPayment($paymentIntentId);

                case StripeStatusMapper::STATUS_AUTHORIZED:
                    // Payment authorized but not captured yet
                    // This is valid for manual capture mode
                    return $this->handleSuccessfulPayment($paymentIntentId);

                case StripeStatusMapper::STATUS_PENDING:
                    // Check provider data to determine specific action needed
                    $stripeStatus = $paymentDetails->providerData['status'] ?? '';

                    if (in_array($stripeStatus, [
                        StripeStatusMapper::STRIPE_REQUIRES_ACTION,
                        StripeStatusMapper::STRIPE_REQUIRES_CONFIRMATION
                    ], true)) {
                        return $this->handle3DSecure($paymentIntentId, $paymentDetails);
                    }

                    if ($stripeStatus === StripeStatusMapper::STRIPE_PROCESSING) {
                        return $this->handleProcessingPayment($paymentIntentId);
                    }

                    // requires_payment_method or other pending states
                    return $this->handleFailedPayment($paymentDetails);

                case StripeStatusMapper::STATUS_CANCELLED:
                case StripeStatusMapper::STATUS_FAILED:
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
     * ✅ USES STANDARD Order::finalizeOrder() METHOD via ShopOrderService
     * ✅ Uses unified service for all order and transaction operations
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
            if (!in_array($paymentDetails->status, [
                StripeStatusMapper::STATUS_CAPTURED,
                StripeStatusMapper::STATUS_AUTHORIZED,
            ], true)) {
                throw new \RuntimeException('Payment not successful: ' . $paymentDetails->status);
            }

            // 3. Create order via unified ShopOrderService (handles finalizeOrder + order number)
            $orderRequest = new OrderCreateRequest(
                sessionId: $session->getId(),
                userId: $user->getId(),
                paymentId: $basket->getPaymentId(),
                paymentTransactionId: $paymentIntentId,
                orderRemark: $session->getVariable('ordRem'),
                metadata: [
                    'stripe_payment_intent_id' => $paymentIntentId,
                    'payment_status' => $paymentDetails->status,
                ]
            );

            $orderResponse = $this->orderService->createOrder($orderRequest);

            // 4. Get OXID Order object for event dispatching and payment details storage
            $order = oxNew(Order::class);
            if (!$order->load($orderResponse->orderId)) {
                throw new \RuntimeException('Failed to load created order: ' . $orderResponse->orderId);
            }

            // 5. Store transaction and Stripe-specific details via unified service
            $this->orderService->storePaymentDetails($order, $paymentDetails);

            // 6. Dispatch events
            $this->dispatchOrderCreatedEvent($order, $paymentIntentId);
            if ($paymentDetails->isCaptured) {
                $this->dispatchPaymentCapturedEvent($order, $paymentDetails);
            }

            // Set order ID in session for thank you page
            $session->setVariable('sess_challenge', $orderResponse->orderId);

            // Clear Stripe session variables
            $session->deleteVariable('stripe_payment_intent_id');
            $session->deleteVariable('stripe_client_secret');

            Registry::getLogger()->info('Stripe payment successful, order created', [
                'order_id' => $orderResponse->orderId,
                'order_number' => $orderResponse->orderNumber,
                'payment_intent_id' => $paymentIntentId,
            ]);

            // Redirect to thank you page
            return 'thankyou';

        } catch (ShopOrderException $e) {
            Registry::getLogger()->error('Order creation failed via ShopOrderService', [
                'error' => $e->getMessage(),
                'error_code' => $e->getErrorCode(),
                'context' => $e->getContext(),
                'payment_intent_id' => $paymentIntentId,
            ]);

            Registry::getUtilsView()->addErrorToDisplay(
                'Order could not be created. Please contact support with payment ID: ' . $paymentIntentId
            );

            return 'payment';
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
            case StripeStatusMapper::STATUS_PENDING:
                // Check if it's requires_payment_method specifically
                $stripeStatus = $paymentDetails->providerData['status'] ?? '';
                if ($stripeStatus === StripeStatusMapper::STRIPE_REQUIRES_PAYMENT_METHOD) {
                    $errorMessage = 'Payment method declined. Please try a different card.';
                }
                break;
            case StripeStatusMapper::STATUS_CANCELLED:
                $errorMessage = 'Payment was canceled. Please try again.';
                break;
            case StripeStatusMapper::STATUS_FAILED:
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
            // Note: redirect_status comes from Stripe URL parameters and uses Stripe-specific values
            if ($redirectStatus === StripeStatusMapper::STRIPE_SUCCEEDED) {
                return $this->handleSuccessfulPayment($paymentIntentId);
            } elseif ($redirectStatus === StripeStatusMapper::STATUS_FAILED) {
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

    /**
     * Create Stripe Checkout Session (AJAX endpoint)
     * Returns JSON with session ID for client-side redirect
     *
     * @return void
     */
    public function createCheckoutSession(): void
    {
        header('Content-Type: application/json');

        try {
            $session = Registry::getSession();
            $basket = $session->getBasket();

            // Validate basket
            if (!$basket || $basket->getProductsCount() == 0) {
                throw new \RuntimeException('Basket is empty');
            }

            $user = $basket->getBasketUser();
            if (!$user || !$user->getId()) {
                throw new \RuntimeException('User not found');
            }

            // Get capture mode from request (automatic or manual)
            $captureMode = Registry::getRequest()->getRequestParameter('capture') ?? 'automatic';
            $captureMode = ($captureMode === 'manual') ? 'manual' : 'automatic';

            // Build line items from basket
            $lineItems = $this->buildCheckoutLineItems($basket);

            // Get Stripe SDK client
            $stripeClient = $this->adapterFactory->getStripeClient();

            // Build success and cancel URLs
            $shopUrl = Registry::getConfig()->getShopUrl();
            $successUrl = $shopUrl . 'index.php?cl=order&fnc=checkoutSuccess&session_id={CHECKOUT_SESSION_ID}&sDeliveryAddressMD5=' . $this->getDeliveryAddressMD5();
            $cancelUrl = $shopUrl . 'index.php?cl=order';

            // Create Checkout Session
            $checkoutSession = $stripeClient->checkout->sessions->create([
                'mode' => 'payment',
                'line_items' => $lineItems,
                'success_url' => $successUrl,
                'cancel_url' => $cancelUrl,
                'locale' => $this->getStripeLocale(),
                'allow_promotion_codes' => false,
                'customer_email' => $user->getFieldData('oxusername'),
                'payment_intent_data' => [
                    'capture_method' => $captureMode,
                    'metadata' => [
                        'user_id' => $user->getId()
                    ]
                ],
                'metadata' => [
                    'user_id' => $user->getId()
                ]
            ]);

            // Store session ID for later verification
            $session->setVariable('stripe_checkout_session_id', $checkoutSession->id);

            Registry::getLogger()->info('Stripe Checkout Session created', [
                'session_id' => $checkoutSession->id,
                'amount' => $basket->getPrice()->getBruttoPrice(),
                'capture_mode' => $captureMode
            ]);

            echo json_encode([
                'id' => $checkoutSession->id,
                'capture' => $captureMode
            ]);

        } catch (\Throwable $e) {
            http_response_code(500);

            Registry::getLogger()->error('Failed to create Checkout Session', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            echo json_encode([
                'error' => 'Failed to create checkout session: ' . $e->getMessage() . ': ' . $e->getLine()
            ]);
        }

        exit;
    }

    /**
     * Handle successful Stripe Checkout return
     * Called when user completes payment on Stripe hosted page
     *
     * @return string
     */
    public function checkoutSuccess(): string
    {
        $sessionId = Registry::getRequest()->getRequestParameter('session_id');

        if (!$sessionId) {
            Registry::getLogger()->error('No session_id in Checkout success callback');
            Registry::getUtilsView()->addErrorToDisplay('Payment information missing');
            return 'payment';
        }

        try {
            // Get Stripe SDK client
            $stripeClient = $this->adapterFactory->getStripeClient();

            // Retrieve the Checkout Session
            $checkoutSession = $stripeClient->checkout->sessions->retrieve($sessionId, [
                'expand' => ['payment_intent']
            ]);

            Registry::getLogger()->info('Checkout Session retrieved', [
                'session_id' => $sessionId,
                'payment_status' => $checkoutSession->payment_status,
                'payment_intent' => $checkoutSession->payment_intent
            ]);

            // Verify payment was successful
            if ($checkoutSession->payment_status !== 'paid') {
                throw new \RuntimeException('Payment not completed: ' . $checkoutSession->payment_status);
            }

            // Get the PaymentIntent ID
            $paymentIntentId = is_string($checkoutSession->payment_intent)
                ? $checkoutSession->payment_intent
                : $checkoutSession->payment_intent->id;

            // Process the order with the PaymentIntent
            Registry::getSession()->setVariable('stripe_payment_intent_id', $paymentIntentId);

            return $this->handleSuccessfulPayment($paymentIntentId);

        } catch (\Exception $e) {
            Registry::getLogger()->error('Error processing Checkout success', [
                'error' => $e->getMessage(),
                'session_id' => $sessionId
            ]);

            Registry::getUtilsView()->addErrorToDisplay('Order processing error. Please contact support.');
            return 'payment';
        }
    }

    /**
     * Build line items for Stripe Checkout from basket
     *
     * @param \OxidEsales\Eshop\Application\Model\Basket $basket
     * @return array
     */
    private function buildCheckoutLineItems($basket): array
    {
        $lineItems = [];
        $currency = strtolower($basket->getBasketCurrency()->name);

        // Add basket products
        foreach ($basket->getContents() as $basketItem) {
            $article = $basketItem->getArticle();
            $lineItems[] = [
                'price_data' => [
                    'currency' => $currency,
                    'unit_amount' => (int) round($basketItem->getUnitPrice()->getBruttoPrice() * 100),
                    'product_data' => [
                        'name' => $article->getFieldData('oxtitle'),
                        'description' => $article->getFieldData('oxshortdesc'),
                    ],
                ],
                'quantity' => (int) $basketItem->getAmount(),
            ];
        }

        // Add delivery cost if present
        $deliveryCost = $basket->getDeliveryCost();
        if ($deliveryCost && $deliveryCost->getBruttoPrice() > 0) {
            $lineItems[] = [
                'price_data' => [
                    'currency' => $currency,
                    'unit_amount' => (int) round($deliveryCost->getBruttoPrice() * 100),
                    'product_data' => [
                        'name' => 'Shipping',
                    ],
                ],
                'quantity' => 1,
            ];
        }

        // Add payment cost if present
        $paymentCost = $basket->getPaymentCost();
        if ($paymentCost && $paymentCost->getBruttoPrice() > 0) {
            $lineItems[] = [
                'price_data' => [
                    'currency' => $currency,
                    'unit_amount' => (int) round($paymentCost->getBruttoPrice() * 100),
                    'product_data' => [
                        'name' => 'Payment Fee',
                    ],
                ],
                'quantity' => 1,
            ];
        }

        return $lineItems;
    }

    /**
     * Get Stripe locale from current shop language
     *
     * @return string
     */
    private function getStripeLocale(): string
    {
        $language = Registry::getLang()->getLanguageAbbr();

        // Map OXID language codes to Stripe locales
        $localeMap = [
            'de' => 'de',
            'en' => 'en',
            'fr' => 'fr',
            'es' => 'es',
            'it' => 'it',
            'nl' => 'nl',
            'pl' => 'pl',
        ];

        return $localeMap[$language] ?? 'auto';
    }
}
