<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Controller;

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
     * @return void
     */
    public function init(): void
    {
        parent::init();

        // Check if basket has items
        $basket = Registry::getSession()->getBasket();
        if ($basket->getProductsCount() == 0) {
            Registry::getUtils()->redirect(
                Registry::getConfig()->getShopHomeUrl() . 'cl=basket',
                false
            );
            return;
        }

        // Check if user needs to be logged in
        $config = Registry::getConfig();
        if ($config->getConfigParam('blPerfNoBasketSaving')) {
            $user = $this->getUser();
            if ($user === null) { // @phpstan-ignore identical.alwaysFalse
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
            /** @var \OxidEsales\Eshop\Application\Model\BasketItem $basketItem */
            $article = $basketItem->getArticle();
            if ($article === null) { // @phpstan-ignore identical.alwaysFalse
                continue;
            }
            $unitPrice = $basketItem->getUnitPrice();
            $items[] = [
                'productId' => $article->getId(),
                'productName' => $article->oxarticles__oxtitle->value ?? '',
                'quantity' => $basketItem->getAmount(),
                'price' => $unitPrice ? $unitPrice->getBruttoPrice() : 0.0, // @phpstan-ignore ternary.alwaysTrue
            ];
        }

        $json = json_encode($items);
        return $json !== false ? $json : '[]';
    }

    /**
     * Get delivery address list for current user
     *
     * @return array<string, mixed>
     */
    public function getUserAddressList(): array
    {
        $user = $this->getUser();
        if ($user === null) { // @phpstan-ignore identical.alwaysFalse
            return [];
        }

        $addressList = $user->getUserAddresses();
        if ($addressList === null) { // @phpstan-ignore identical.alwaysFalse
            return [];
        }
        /** @var array<string, mixed> $result */
        $result = is_array($addressList) ? $addressList : $addressList->getArray(); // @phpstan-ignore function.alreadyNarrowedType
        return $result;
    }

    /**
     * Get country list
     *
     * @return \OxidEsales\Eshop\Application\Model\CountryList
     */
    public function getCountryList(): \OxidEsales\Eshop\Application\Model\CountryList
    {
        /** @var \OxidEsales\Eshop\Application\Model\CountryList $countryList */
        $countryList = oxNew(\OxidEsales\Eshop\Application\Model\CountryList::class);
        $countryList->loadActiveCountries();

        return $countryList;
    }

    /**
     * Get payment method list
     *
     * @return array<string, mixed>
     */
    public function getPaymentList(): array
    {
        $basket = Registry::getSession()->getBasket();

        /** @var \OxidEsales\Eshop\Application\Model\PaymentList $paymentList */
        $paymentList = Registry::get(\OxidEsales\Eshop\Application\Model\PaymentList::class);
        $price = $basket->getPrice();
        // @phpstan-ignore method.notFound
        $paymentList->loadPaymentList(
            $basket->getProductsCount(),
            $price ? $price->getBruttoPrice() : 0.0 // @phpstan-ignore ternary.alwaysTrue
        );

        /** @var array<string, mixed> $result */
        $result = $paymentList->getArray();
        return $result;
    }

    /**
     * Get saved payment methods for current user
     *
     * @return array<string, mixed>
     */
    public function getSavedPaymentMethods(): array
    {
        $user = $this->getUser();
        if ($user === null) { // @phpstan-ignore identical.alwaysFalse
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
        return $this->getUser() !== null; // @phpstan-ignore notIdentical.alwaysTrue
    }

    /**
     * AJAX handler - Update address
     * Fallback for non-GraphQL requests
     * @return void
     */
    public function updateAddress(): void
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
     * @return void
     */
    public function processPayment(): void
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
     * @return void
     */
    public function handleReturn(): void
    {
        $paymentIntentId = Registry::getRequest()->getRequestParameter('payment_intent');
        $paymentIntentClientSecret = Registry::getRequest()->getRequestParameter('payment_intent_client_secret');

        // TODO: Verify payment intent with Stripe
        // TODO: Create order if payment successful

        // For now, show success
        $this->addTplParam('showSuccess', true);
    }

    /**
     * Add product to basket and redirect to checkout
     * Handler for "Buy Now" button
     *
     * This method adds a single product directly to the basket and redirects
     * to the one-page checkout, providing a streamlined purchase flow.
     */
    public function addProductAndCheckout(): void
    {
        // Validate CSRF token
        if (!$this->isValidRequest()) {
            Registry::getUtils()->showMessageAndExit('Invalid request token');
            return;
        }

        $request = Registry::getRequest();
        $basket = Registry::getSession()->getBasket();

        // Get product data from request
        $productId = $request->getRequestParameter('aid');
        $productNid = $request->getRequestParameter('anid');
        $amount = (float) $request->getRequestParameter('am', '1');
        $selectionList = $request->getRequestParameter('sel');
        $persistentParams = $request->getRequestParameter('persparam');

        // Clear basket for "Buy Now" behavior (optional - can be configured)
        // Comment out the next line if you want to add to existing basket instead
        $basket->deleteBasket();

        try {
            // Add product to basket
            $basket->addToBasket(
                $productId,
                $amount,
                $selectionList,
                $persistentParams
            );

            // Calculate basket
            $basket->calculateBasket(true);

            // Set flag to indicate this is a "Buy Now" purchase
            Registry::getSession()->setVariable('isBuyNowCheckout', true);

            // Redirect to one-page checkout
            $checkoutUrl = Registry::getConfig()->getShopUrl() . 'cl=stripe_checkout_onepage';
            Registry::getUtils()->redirect($checkoutUrl, false);
        } catch (\Exception $e) {
            // Log error
            Registry::getLogger()->error('Buy Now failed', [
                'productId' => $productId,
                'error' => $e->getMessage()
            ]);

            // Show error message and redirect back to product
            Registry::getUtilsView()->addErrorToDisplay($e->getMessage());
            Registry::getUtils()->redirect(
                Registry::getConfig()->getShopUrl() . 'cl=details&anid=' . $productNid,
                false
            );
        }
    }

    /**
     * Output JSON response
     *
     * @param array<string, mixed> $data
     */
    private function outputJson(array $data): void
    {
        Registry::getUtils()->setHeader('Content-Type: application/json');
        $json = json_encode($data);
        Registry::getUtils()->showMessageAndExit($json !== false ? $json : '{}');
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
     * @return array<int, array{title: string, link: string}>|null
     */
    public function getBreadCrumb(): ?array
    {
        $paths = [];

        $result = Registry::getLang()->translateString('CHECKOUT_TITLE', (int) Registry::getLang()->getBaseLanguage(), false);
        $path = [];
        $path['title'] = is_string($result) ? $result : 'Checkout';
        $path['link'] = $this->getLink();
        $paths[] = $path;

        return $paths;
    }
}
