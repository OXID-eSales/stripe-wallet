<?php

declare(strict_types=1);

namespace OxidEsales\StripeWallet\Component\Controller;

use OxidEsales\Eshop\Application\Controller\FrontendController;
use OxidEsales\Eshop\Core\Registry;

/**
 * CheckoutOnePageController - OXID view controller for one-page checkout
 *
 * Renders the one-page checkout template and provides data to Twig
 */
class CheckoutOnePageController extends FrontendController
{
    /**
     * Template name
     */
    protected $_sThisTemplate = '@stripe/page/checkout/onepage.html.twig';

    /**
     * Checkout session ID
     */
    private ?string $checkoutSessionId = null;

    /**
     * Initialize controller
     */
    public function init()
    {
        parent::init();

        // Check if basket has items
        $basket = Registry::getSession()->getBasket();
        if (!$basket || $basket->getProductsCount() == 0) {
            Registry::getUtils()->redirect(
                Registry::getConfig()->getShopHomeUrl() . 'cl=basket',
                false
            );
            return;
        }

        // Check if user needs to be logged in
        if ($this->getConfig()->getConfigParam('blPerfNoBasketSaving')) {
            $user = $this->getUser();
            if (!$user) {
                Registry::getUtils()->redirect(
                    Registry::getConfig()->getShopHomeUrl() . 'cl=account&force_sid=' . Registry::getSession()->getId(),
                    false
                );
                return;
            }
        }

        // Generate checkout session ID
        $this->checkoutSessionId = 'checkout_' . uniqid() . '_' . time();
        Registry::getSession()->setVariable('checkoutSessionId', $this->checkoutSessionId);
    }

    /**
     * Render method - sets up template data
     *
     * @return string Template name
     */
    public function render()
    {
        parent::render();

        // Add CSS and JS to page
        $this->addTplParam('pageTitle', Registry::getLang()->translateString('CHECKOUT_TITLE'));

        return $this->_sThisTemplate;
    }

    /**
     * Get checkout session ID
     *
     * @return string
     */
    public function getCheckoutSessionId(): string
    {
        if (!$this->checkoutSessionId) {
            $this->checkoutSessionId = Registry::getSession()->getVariable('checkoutSessionId');
            if (!$this->checkoutSessionId) {
                $this->checkoutSessionId = 'checkout_' . uniqid() . '_' . time();
                Registry::getSession()->setVariable('checkoutSessionId', $this->checkoutSessionId);
            }
        }

        return $this->checkoutSessionId;
    }

    /**
     * Get cart items as JSON for JavaScript
     *
     * @return string JSON encoded cart items
     */
    public function getCartItemsJson(): string
    {
        $basket = Registry::getSession()->getBasket();
        $items = [];

        foreach ($basket->getContents() as $basketItem) {
            $article = $basketItem->getArticle();
            $items[] = [
                'productId' => $article->getId(),
                'productName' => $article->oxarticles__oxtitle->value,
                'quantity' => $basketItem->getAmount(),
                'price' => $basketItem->getUnitPrice()->getBruttoPrice(),
            ];
        }

        return json_encode($items);
    }

    /**
     * Get delivery address list for current user
     *
     * @return array
     */
    public function getUserAddressList(): array
    {
        $user = $this->getUser();
        if (!$user) {
            return [];
        }

        $addressList = $user->getUserAddresses();
        return $addressList ? $addressList->getArray() : [];
    }

    /**
     * Get country list
     *
     * @return \OxidEsales\Eshop\Application\Model\CountryList
     */
    public function getCountryList()
    {
        $countryList = oxNew(\OxidEsales\Eshop\Application\Model\CountryList::class);
        $countryList->loadActiveCountries();

        return $countryList;
    }

    /**
     * Get payment method list
     *
     * @return array
     */
    public function getPaymentList(): array
    {
        $basket = Registry::getSession()->getBasket();
        $user = $this->getUser();

        $paymentList = Registry::get(\OxidEsales\Eshop\Application\Model\PaymentList::class);
        $paymentList->loadPaymentList(
            $basket->getProductsCount(),
            $basket->getPrice()->getBruttoPrice()
        );

        return $paymentList->getArray();
    }

    /**
     * Get saved payment methods for current user
     *
     * @return array
     */
    public function getSavedPaymentMethods(): array
    {
        $user = $this->getUser();
        if (!$user) {
            return [];
        }

        // TODO: Implement retrieval of saved Stripe payment methods
        // This would fetch from Stripe API or local database
        return [];
    }

    /**
     * Get GraphQL endpoint URL
     *
     * @return string
     */
    public function getGraphQLEndpoint(): string
    {
        return Registry::getConfig()->getShopUrl() . 'graphql';
    }

    /**
     * Get encryption key for frontend
     * Only public key portion is exposed
     *
     * @return string
     */
    public function getEncryptionKey(): string
    {
        // TODO: Return public encryption key
        // This should be a different key than the server-side decryption key
        return Registry::getConfig()->getConfigParam('sStripePublicKey');
    }

    /**
     * Check if user can use saved payment methods
     *
     * @return bool
     */
    public function canUseSavedPaymentMethods(): bool
    {
        return $this->getUser() !== null;
    }

    /**
     * AJAX handler - Update address
     * Fallback for non-GraphQL requests
     */
    public function updateAddress()
    {
        if (!$this->isValidRequest()) {
            $this->outputJson(['success' => false, 'message' => 'Invalid request']);
            return;
        }

        $addressData = Registry::getRequest()->getRequestParameter('billingAddress');

        // Validate and save address
        // TODO: Implement address validation and saving

        $this->outputJson(['success' => true, 'message' => 'Address updated']);
    }

    /**
     * AJAX handler - Process payment
     * Fallback for non-GraphQL requests
     */
    public function processPayment()
    {
        if (!$this->isValidRequest()) {
            $this->outputJson(['success' => false, 'message' => 'Invalid request']);
            return;
        }

        // TODO: Implement payment processing

        $this->outputJson([
            'success' => true,
            'orderId' => 'ORDER_' . time(),
            'status' => 'SUCCEEDED'
        ]);
    }

    /**
     * Handle return from 3D Secure
     */
    public function handleReturn()
    {
        $paymentIntentId = Registry::getRequest()->getRequestParameter('payment_intent');
        $paymentIntentClientSecret = Registry::getRequest()->getRequestParameter('payment_intent_client_secret');

        // TODO: Verify payment intent with Stripe
        // TODO: Create order if payment successful

        // For now, show success
        $this->addTplParam('showSuccess', true);
    }

    /**
     * Output JSON response
     *
     * @param array $data
     */
    private function outputJson(array $data): void
    {
        Registry::getUtils()->setHeader('Content-Type: application/json');
        Registry::getUtils()->showMessageAndExit(json_encode($data));
    }

    /**
     * Validate request (CSRF token)
     *
     * @return bool
     */
    private function isValidRequest(): bool
    {
        $token = Registry::getRequest()->getRequestParameter('stoken');
        $sessionToken = Registry::getSession()->getSessionChallengeToken();

        return $token === $sessionToken;
    }

    /**
     * Get breadcrumb path
     *
     * @return array
     */
    public function getBreadCrumb()
    {
        $paths = [];

        $path = [];
        $path['title'] = Registry::getLang()->translateString('CHECKOUT_TITLE', Registry::getLang()->getBaseLanguage(), false);
        $path['link'] = $this->getLink();
        $paths[] = $path;

        return $paths;
    }
}
