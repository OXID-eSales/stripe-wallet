<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Stripe\Controller;

use OxidEsales\Eshop\Application\Controller\PaymentController as CorePaymentController;
use OxidEsales\Eshop\Core\Registry;
use OxidSolutionCatalysts\Payments\Stripe\Service\ModuleConfigurationService;
use OxidSolutionCatalysts\Payments\Stripe\Module;
use OxidSolutionCatalysts\Payments\Component\Service\Factory\PaymentAdapterFactoryInterface;
use OxidSolutionCatalysts\Payments\Component\Adapter\Request\CreatePaymentRequest;
use OxidSolutionCatalysts\Payments\Component\Adapter\Exception\PaymentAdapterException;
use OxidSolutionCatalysts\Payments\Component\Adapter\ShopAdapterInterface;

/**
 * Extended payment controller for Stripe integration
 * Adds Stripe-specific logic to payment method selection page
 */
class PaymentController extends CorePaymentController
{
    private ModuleConfigurationService $stripeConfig;
    private PaymentAdapterFactoryInterface $adapterFactory;
    private ShopAdapterInterface $shopAdapter;

    /**
     * @param \OxidSolutionCatalysts\Payments\Stripe\Service\ModuleConfigurationService $stripeConfig
     * @param \OxidSolutionCatalysts\Payments\Component\Service\Factory\PaymentAdapterFactoryInterface $adapterFactory
     * @param \OxidSolutionCatalysts\Payments\Component\Adapter\ShopAdapterInterface $shopAdapter
     */
    public function __construct(
        ModuleConfigurationService $stripeConfig,
        PaymentAdapterFactoryInterface $adapterFactory,
        ShopAdapterInterface $shopAdapter
    )
    {
        parent::__construct();

        $this->stripeConfig = $stripeConfig;
        $this->adapterFactory = $adapterFactory;
        $this->shopAdapter = $shopAdapter;
    }

    /**
     * Render payment selection page
     * Creates PaymentIntent for Stripe Payment Element if Stripe is selected
     *
     * @return string Template name
     */
    public function render()
    {
        $template = parent::render();

/*        // Check if Stripe payment is available
        if ($this->stripeConfig->isConfigured()) {
            $basket = Registry::getSession()->getBasket();
            $user = $basket->getBasketUser();
            $clientSecret = '';

            // Create PaymentIntent if we have a user
            if ($user && $user->getId()) {
                try {
                    // Check if we already have a PaymentIntent in session
                    $existingIntentId = Registry::getSession()->getVariable('stripe_payment_intent_id');

                    if ($existingIntentId) {
                        // Try to retrieve existing PaymentIntent
                        try {
                            $paymentIntent = $this->paymentService->getPaymentIntent($existingIntentId);

                            // Verify amount matches current basket (convert to cents: amount * 100)
                            $basketAmount = (int) round($basket->getPrice()->getBruttoPrice() * 100);
                            if ($paymentIntent['amount'] !== $basketAmount) {
                                // Amount changed, create new PaymentIntent
                                $paymentIntent = $this->paymentService->createPaymentIntent($basket, $user);
                                Registry::getSession()->setVariable('stripe_payment_intent_id', $paymentIntent['id']);
                            }
                        } catch (\Exception $e) {
                            // PaymentIntent not found or invalid, create new one
                            $paymentIntent = $this->paymentService->createPaymentIntent($basket, $user);
                            Registry::getSession()->setVariable('stripe_payment_intent_id', $paymentIntent['id']);
                        }
                    } else {
                        // Create new PaymentIntent
                        $paymentIntent = $this->paymentService->createPaymentIntent($basket, $user);
                        Registry::getSession()->setVariable('stripe_payment_intent_id', $paymentIntent['id']);
                    }

                    $clientSecret = $paymentIntent['client_secret'] ?? '';

                } catch (\Exception $e) {
                    Registry::getLogger()->error('Failed to create Stripe PaymentIntent', [
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                }
            }*/

            // Build Stripe configuration for JavaScript and inject script
       //     $stripeConfig = $this->buildStripeConfig($clientSecret);
         //   $this->addTplParam('stripeConfigScript', $this->getStripeConfigScript($stripeConfig));
        //    $this->addTplParam('stripeCssUrl', $this->getStripeCssUrl());
   //     }

        return $template;
    }

    /**
     * Build Stripe configuration object for JavaScript
     *
     * Refactored to use ShopAdapterInterface for translations.
     * Replaces deprecated Registry::getLang() usage.
     *
     * @param string $clientSecret
     * @return array
     */
    private function buildStripeConfig(string $clientSecret): array
    {
        $viewConfig = Registry::get(\OxidSolutionCatalysts\Stripe\Core\ViewConfig::class);

        return [
            'publishableKey' => $this->stripeConfig->getPublishableKey(),
            'clientSecret' => $clientSecret,
            'returnUrl' => $viewConfig->getStripeReturnUrl(),
            'locale' => $this->shopAdapter->getCurrentLanguageAbbr(),
            'testMode' => $this->stripeConfig->isTestMode(),
            'primaryColor' => $viewConfig->getStripePrimaryColor(),
            'labels' => [
                'cardPayment' => $this->shopAdapter->translateString('OSC_STRIPE_CARD_PAYMENT'),
                'paymentDesc' => $this->shopAdapter->translateString('OSC_STRIPE_PAYMENT_DESC'),
                'processing' => $this->shopAdapter->translateString('OSC_STRIPE_PROCESSING'),
                'processingPayment' => $this->shopAdapter->translateString('OSC_STRIPE_PROCESSING_PAYMENT'),
                'securePayment' => $this->shopAdapter->translateString('OSC_STRIPE_SECURE_PAYMENT'),
                'configError' => $this->shopAdapter->translateString('OSC_STRIPE_CONFIG_ERROR'),
                'intentError' => $this->shopAdapter->translateString('OSC_STRIPE_INTENT_ERROR'),
                'unexpectedError' => $this->shopAdapter->translateString('OSC_STRIPE_UNEXPECTED_ERROR'),
            ]
        ];
    }

    /**
     * Get inline script to inject Stripe configuration
     *
     * @param array $config
     * @return string
     */
    private function getStripeConfigScript(array $config): string
    {
        $configJson = json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $jsUrl = $this->getStripeJsUrl();

        return sprintf(
            '<script>window.stripeConfig = %s;</script>' . "\n" .
            '<script src="%s"></script>',
            $configJson,
            $jsUrl
        );
    }

    /**
     * Get Stripe JavaScript file URL
     *
     * @return string
     */
    private function getStripeJsUrl(): string
    {
        $config = Registry::getConfig();
        $moduleUrl = $config->getModuleUrl(Module::MODULE_ID, 'out/src/js/stripe_payment_element.js');

        return $moduleUrl;
    }

    /**
     * Get Stripe CSS file URL
     *
     * @return string
     */
    private function getStripeCssUrl(): string
    {
        $config = Registry::getConfig();
        $cssUrl = $config->getModuleUrl(Module::MODULE_ID, 'out/src/css/stripe_payment_element.css');

        return $cssUrl;
    }

    /**
     * Validate payment selection
     *
     * @return mixed
     */
    public function validatePayment()
    {
        $result = parent::validatePayment();

        // Additional Stripe-specific validation
        if ($this->isStripeSelected()) {
            if (!$this->stripeConfig->isConfigured()) {
                Registry::getUtilsView()->addErrorToDisplay(
                    'Payment method temporarily unavailable'
                );
                return 'payment';
            }

            // Validate minimum order amount
            $basket = Registry::getSession()->getBasket();
            $total = $basket->getPrice()->getBruttoPrice();
            $minimumAmount = $this->stripeConfig->getMinimumOrderAmount();

            if ($total < $minimumAmount) {
                Registry::getUtilsView()->addErrorToDisplay(
                    sprintf('Minimum order amount is %.2f %s', $minimumAmount, $basket->getBasketCurrency()->name)
                );
                return 'payment';
            }
        }

        return $result;
    }

    /**
     * AJAX endpoint: Create PaymentIntent
     * Called from frontend JavaScript
     *
     * Use Stripe Adapter pattern for provider-agnostic payment processing.
     * See docs/payment-component/04-sdk-adapter-layer.md for architecture details.
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

            // Create adapter for Stripe provider
            $adapter = $this->adapterFactory->createDefaultAdapter();

            // Build provider-agnostic payment request
            $createPaymentRequest = new CreatePaymentRequest(
                amount: (float) $basket->getPrice()->getBruttoPrice(),
                currency: $basket->getBasketCurrency()->name,
                orderId: (string) $basket->getOrderId() ?: 'basket-' . time(),
                shopId: (string) Registry::getConfig()->getShopId(),
                paymentMethod: 'card',
                directCapture: $this->stripeConfig->getCaptureMode() === 'automatic',
                customerId: $user->getFieldData('stripe_customer_id') ?: null,
                returnUrl: $this->getReturnUrl(),
                cancelUrl: $this->getCancelUrl(),
                metadata: [
                    'oxid_user_id' => $user->getId(),
                    'customer_email' => $user->getFieldData('oxusername'),
                    'customer_name' => $user->getFieldData('oxfname') . ' ' . $user->getFieldData('oxlname'),
                    'basket_id' => Registry::getSession()->getId(),
                ]
            );

            // Use adapter to create payment (provider-agnostic)
            $paymentResponse = $adapter->createPayment($createPaymentRequest);

            // Store payment intent ID in session
            Registry::getSession()->setVariable('stripe_payment_intent_id', $paymentResponse->providerPaymentId);

            // Return normalized response
            echo json_encode([
                'success' => true,
                'clientSecret' => $paymentResponse->clientSecret,
                'amount' => (int) ($paymentResponse->amount * 100), // Convert to cents for frontend
                'currency' => $paymentResponse->currency,
                'status' => $paymentResponse->status,
                'requiresAction' => $paymentResponse->requiresAction,
            ]);

        } catch (PaymentAdapterException $e) {
            Registry::getLogger()->error('PaymentIntent creation failed via adapter', [
                'error' => $e->getMessage(),
                'error_code' => $e->getErrorCode(),
                'provider' => $e->getProviderName(),
            ]);

            echo json_encode([
                'error' => $e->getMessage(),
                'error_code' => $e->getErrorCode(),
            ]);
        } catch (\Throwable $e) {
            Registry::getLogger()->error('Unexpected error during PaymentIntent creation', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            echo json_encode([
                'error' => 'An unexpected error occurred. Please try again.',
            ]);
        }

        exit;
    }

    /**
     * Get return URL for payment processing
     *
     * @return string
     */
    private function getReturnUrl(): string
    {
        return Registry::getConfig()->getShopCurrentURL() . 'cl=order&fnc=execute';
    }

    /**
     * Get cancel URL for payment processing
     *
     * @return string
     */
    private function getCancelUrl(): string
    {
        return Registry::getConfig()->getShopCurrentURL() . 'cl=payment';
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
}
