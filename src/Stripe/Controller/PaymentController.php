<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Controller;

use OxidEsales\Eshop\Application\Controller\PaymentController as CorePaymentController;
use OxidEsales\Eshop\Core\Registry;
use OxidEsales\Payments\Stripe\Service\ModuleConfigurationService;
use OxidEsales\Payments\Stripe\Module;
use OxidEsales\PaymentComponent\Service\Factory\PaymentAdapterFactoryInterface;
use OxidEsales\PaymentComponent\Adapter\Request\CreatePaymentRequest;
use OxidEsales\PaymentComponent\Adapter\Exception\PaymentAdapterException;
use OxidEsales\PaymentComponent\Adapter\ShopAdapterInterface;

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
     * @param \OxidEsales\Payments\Stripe\Service\ModuleConfigurationService $stripeConfig
     * @param \OxidEsales\PaymentComponent\Service\Factory\PaymentAdapterFactoryInterface $adapterFactory
     * @param \OxidEsales\PaymentComponent\Adapter\ShopAdapterInterface $shopAdapter
     */
    public function __construct(
        ModuleConfigurationService $stripeConfig,
        PaymentAdapterFactoryInterface $adapterFactory,
        ShopAdapterInterface $shopAdapter
    ) {
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
        return parent::render();
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
                /** @var string $currencyName */
                $currencyName = $basket->getBasketCurrency()->name ?? 'EUR';
                Registry::getUtilsView()->addErrorToDisplay(
                    sprintf('Minimum order amount is %.2f %s', $minimumAmount, $currencyName)
                );
                return 'payment';
            }
        }

        return $result;
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
        // Check for any Stripe payment method (oe_payments_stripe_* prefix)
        return str_starts_with($selectedPayment, 'oe_payments_stripe_');
    }
}
