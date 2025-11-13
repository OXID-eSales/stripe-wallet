# Controller Integration

**Extending OXID Controllers for Stripe Payment**
**Version:** 1.0.0
**Date:** 2025-11-13

---

## Overview

This document covers extending OXID's standard checkout controllers (`PaymentController` and `OrderController`) to integrate Stripe payment processing into the standard multi-step checkout flow.

---

## Controller Architecture

```
OXID Standard Checkout Controllers
         │
         ├── BasketController (/basket) → View/Edit basket
         ├── UserController (/user) → Login/Address
         ├── PaymentController (/payment) → Payment method selection
         │        │
         │        └── Extended by: Stripe PaymentController
         │             - Display Stripe payment form
         │             - Load Stripe.js SDK
         │             - Pass public key to template
         │
         └── OrderController (/order) → Review and submit
                  │
                  └── Extended by: Stripe OrderController
                       - Create PaymentIntent
                       - Confirm payment
                       - Handle 3D Secure
                       - Create order
                       - Store transaction
```

---

## PaymentController Extension

The `PaymentController` extension adds Stripe-specific logic to the payment method selection page.

### File: `src/Controller/PaymentController.php`

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Stripe\Controller;

use OxidEsales\Eshop\Application\Controller\PaymentController as CorePaymentController;
use OxidEsales\Eshop\Core\Registry;
use OxidSolutionCatalysts\Stripe\Service\StripeConfigurationService;
use OxidSolutionCatalysts\Stripe\Service\StripePaymentService;

/**
 * Extended payment controller for Stripe integration
 */
class PaymentController extends CorePaymentController
{
    private StripeConfigurationService $stripeConfig;
    private StripePaymentService $paymentService;

    /**
     * Initialize services
     */
    public function init(): void
    {
        parent::init();

        $this->stripeConfig = Registry::get(StripeConfigurationService::class);
        $this->paymentService = Registry::get(StripePaymentService::class);
    }

    /**
     * Render payment selection page
     *
     * @return string Template name
     */
    public function render()
    {
        $template = parent::render();

        // Check if Stripe payment is selected or available
        if ($this->isStripeAvailable()) {
            // Add Stripe public key for frontend
            $this->addTplParam('stripePublicKey', $this->stripeConfig->getPublicKey());
            $this->addTplParam('stripeTestMode', $this->stripeConfig->isTestMode());

            // Check if Stripe is the selected payment method
            if ($this->isStripeSelected()) {
                $this->addTplParam('stripeSelected', true);

                // Pre-create PaymentIntent if configured
                if ($this->shouldPreCreateIntent()) {
                    $this->createPaymentIntentForBasket();
                }
            }
        }

        return $template;
    }

    /**
     * Validate payment selection
     *
     * @return mixed
     */
    public function validatePayment()
    {
        $result = parent::validatePayment();

        // Additional Stripe-specific validation if needed
        if ($this->isStripeSelected()) {
            if (!$this->stripeConfig->isConfigured()) {
                Registry::getUtilsView()->addErrorToDisplay(
                    'Payment method temporarily unavailable'
                );
                return 'payment';
            }
        }

        return $result;
    }

    /**
     * Check if Stripe payment is available
     *
     * @return bool
     */
    private function isStripeAvailable(): bool
    {
        return $this->stripeConfig->isConfigured();
    }

    /**
     * Check if Stripe is the selected payment method
     *
     * @return bool
     */
    private function isStripeSelected(): bool
    {
        $selectedPayment = Registry::getSession()->getBasket()->getPaymentId();
        return $selectedPayment === 'osc_stripe_card';
    }

    /**
     * Check if PaymentIntent should be pre-created
     *
     * @return bool
     */
    private function shouldPreCreateIntent(): bool
    {
        // Pre-create intent for faster checkout (optional)
        return false;
    }

    /**
     * Pre-create PaymentIntent for basket
     */
    private function createPaymentIntentForBasket(): void
    {
        try {
            $basket = Registry::getSession()->getBasket();
            $user = $basket->getBasketUser();

            if (!$user || !$user->getId()) {
                return;
            }

            $paymentIntent = $this->paymentService->createPaymentIntent($basket, $user);

            // Store in session for later use
            Registry::getSession()->setVariable('stripe_payment_intent_id', $paymentIntent['id']);
            Registry::getSession()->setVariable('stripe_client_secret', $paymentIntent['client_secret']);

            $this->addTplParam('stripeClientSecret', $paymentIntent['client_secret']);

        } catch (\RuntimeException $e) {
            Registry::getLogger()->error('Failed to pre-create PaymentIntent', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * AJAX endpoint: Create PaymentIntent
     *
     * @return void
     */
    public function createPaymentIntent(): void
    {
        Registry::getUtils()->setHeader('Content-Type: application/json');

        try {
            $basket = Registry::getSession()->getBasket();
            $user = $basket->getBasketUser();

            if (!$user || !$user->getId()) {
                echo json_encode([
                    'error' => 'User not logged in'
                ]);
                exit;
            }

            $paymentIntent = $this->paymentService->createPaymentIntent($basket, $user);

            // Store in session
            Registry::getSession()->setVariable('stripe_payment_intent_id', $paymentIntent['id']);

            echo json_encode([
                'success' => true,
                'clientSecret' => $paymentIntent['client_secret'],
                'amount' => $paymentIntent['amount'],
                'currency' => $paymentIntent['currency'],
            ]);

        } catch (\RuntimeException $e) {
            Registry::getLogger()->error('PaymentIntent creation failed', [
                'error' => $e->getMessage(),
            ]);

            echo json_encode([
                'error' => $e->getMessage()
            ]);
        }

        exit;
    }
}
```

---

## OrderController Extension

The `OrderController` extension handles payment confirmation and order creation.

### File: `src/Controller/OrderController.php`

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Stripe\Controller;

use OxidEsales\Eshop\Application\Controller\OrderController as CoreOrderController;
use OxidEsales\Eshop\Core\Registry;
use OxidEsales\Eshop\Application\Model\Order;
use OxidSolutionCatalysts\Stripe\Service\StripePaymentService;

/**
 * Extended order controller for Stripe payment processing
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
     *
     * @return string Next page (thankyou, payment, or order)
     */
    public function execute()
    {
        // Check if Stripe payment
        if ($this->isStripePayment()) {
            return $this->executeStripePayment();
        }

        // Standard OXID payment flow
        return parent::execute();
    }

    /**
     * Execute Stripe payment flow
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

        // Get payment intent ID from request
        $paymentIntentId = Registry::getRequest()->getRequestParameter('payment_intent_id');

        if (!$paymentIntentId) {
            // Try from session
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
                    return $this->handle3DSecure($paymentIntentId);

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
     *
     * @param string $paymentIntentId
     * @return string
     */
    private function handleSuccessfulPayment(string $paymentIntentId): string
    {
        try {
            // Create order using payment service
            $order = $this->paymentService->handlePaymentSuccess($paymentIntentId);

            // Set order ID in session for thank you page
            Registry::getSession()->setVariable('sess_challenge', $order->getId());

            // Clear Stripe session variables
            Registry::getSession()->deleteVariable('stripe_payment_intent_id');
            Registry::getSession()->deleteVariable('stripe_client_secret');

            Registry::getLogger()->info('Stripe payment successful, order created', [
                'order_id' => $order->getId(),
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
     * @return string
     */
    private function handle3DSecure(string $paymentIntentId): string
    {
        try {
            $threeDSData = $this->paymentService->handle3DSecure($paymentIntentId);

            if ($threeDSData['requires_action']) {
                // Store payment intent ID for after 3DS redirect
                Registry::getSession()->setVariable('stripe_payment_intent_id', $paymentIntentId);

                // Pass 3DS data to template
                $this->addTplParam('stripe3DSRequired', true);
                $this->addTplParam('stripeClientSecret', $threeDSData['client_secret']);

                // Render 3DS page (will redirect to Stripe)
                return 'stripe_3ds';
            }

            // No action required, try processing again
            return $this->handleSuccessfulPayment($paymentIntentId);

        } catch (\RuntimeException $e) {
            Registry::getLogger()->error('3D Secure handling failed', [
                'error' => $e->getMessage(),
                'payment_intent_id' => $paymentIntentId,
            ]);

            Registry::getUtilsView()->addErrorToDisplay('Payment authentication failed');
            return 'payment';
        }
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

        return 'stripe_processing';
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
}
```

---

## WebhookController

Controller for handling Stripe webhook events.

### File: `src/Controller/WebhookController.php`

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Stripe\Controller;

use OxidEsales\Eshop\Application\Controller\FrontendController;
use OxidEsales\Eshop\Core\Registry;
use Stripe\Webhook;
use Stripe\Exception\SignatureVerificationException;
use OxidSolutionCatalysts\Stripe\Service\StripeConfigurationService;
use OxidSolutionCatalysts\Stripe\Service\WebhookProcessingService;

/**
 * Webhook endpoint controller
 *
 * URL: /index.php?cl=stripe_webhook
 */
class WebhookController extends FrontendController
{
    private StripeConfigurationService $config;
    private WebhookProcessingService $webhookService;

    /**
     * Initialize services
     */
    public function init(): void
    {
        parent::init();

        $this->config = Registry::get(StripeConfigurationService::class);
        $this->webhookService = Registry::get(WebhookProcessingService::class);
    }

    /**
     * Handle incoming webhook
     *
     * @return void
     */
    public function render()
    {
        // Set JSON header
        Registry::getUtils()->setHeader('Content-Type: application/json');

        try {
            // Get raw POST body
            $payload = file_get_contents('php://input');
            $sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

            // Verify webhook signature
            $event = Webhook::constructEvent(
                $payload,
                $sigHeader,
                $this->config->getWebhookSecret()
            );

            // Process webhook event
            $this->webhookService->processEvent($event);

            // Return success response
            echo json_encode(['received' => true]);

        } catch (SignatureVerificationException $e) {
            // Invalid signature
            Registry::getLogger()->error('Webhook signature verification failed', [
                'error' => $e->getMessage(),
            ]);

            http_response_code(400);
            echo json_encode(['error' => 'Invalid signature']);

        } catch (\Exception $e) {
            // Processing error
            Registry::getLogger()->error('Webhook processing failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            http_response_code(500);
            echo json_encode(['error' => 'Processing failed']);
        }

        exit;
    }
}
```

---

## Controller Method Flow

### Payment Page Flow

```
1. User visits /payment
         ↓
2. PaymentController::render()
         ↓
3. Check if Stripe is available (API keys configured)
         ↓
4. If Stripe selected:
   - Add public key to template
   - (Optional) Pre-create PaymentIntent
         ↓
5. Display payment form with Stripe.js
```

### Order Submission Flow

```
1. User submits order form
         ↓
2. OrderController::execute()
         ↓
3. Check if Stripe payment → executeStripePayment()
         ↓
4. Get PaymentIntent ID from request/session
         ↓
5. Retrieve PaymentIntent from Stripe
         ↓
6. Check status:
   ├─ succeeded → handleSuccessfulPayment()
   │                  ├─ Create OXID order
   │                  ├─ Store transaction
   │                  └─ Redirect to thank you page
   │
   ├─ requires_action → handle3DSecure()
   │                  ├─ Store intent ID in session
   │                  └─ Redirect to 3DS page
   │
   ├─ processing → handleProcessingPayment()
   │                  └─ Show "processing" page
   │
   └─ failed/canceled → handleFailedPayment()
                        └─ Show error, redirect to payment page
```

---

## Testing

### Manual Testing

1. **Test Payment Success:**
   - Card: 4242 4242 4242 4242
   - Expected: Order created, redirected to thank you page

2. **Test 3D Secure:**
   - Card: 4000 0027 6000 3184
   - Expected: Redirected to 3DS page, then order created

3. **Test Payment Failure:**
   - Card: 4000 0000 0000 0002
   - Expected: Error message, stayed on payment page

### Unit Test Example

```php
use PHPUnit\Framework\TestCase;

class OrderControllerTest extends TestCase
{
    public function testIsStripePayment(): void
    {
        // Mock session
        $session = $this->createMock(\OxidEsales\Eshop\Core\Session::class);
        // ... test implementation
    }
}
```

---

## Next Steps

1. Read [TEMPLATE_GUIDE.md](TEMPLATE_GUIDE.md) for frontend implementation
2. Read [WEBHOOK_HANDLING.md](WEBHOOK_HANDLING.md) for webhook processing
3. Read [ERROR_HANDLING.md](ERROR_HANDLING.md) for error scenarios

