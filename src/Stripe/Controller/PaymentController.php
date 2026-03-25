<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Controller;

use OxidEsales\Eshop\Application\Controller\PaymentController as CorePaymentController;
use OxidEsales\Eshop\Core\Registry;
use OxidEsales\Payments\Stripe\Service\ModuleConfigurationServiceInterface;
use OxidEsales\Payments\Stripe\Module;

/**
 * Extended payment controller for Stripe integration
 * Adds Stripe-specific logic to payment method selection page
 */
class PaymentController extends CorePaymentController
{
    use ServiceContainer;

    private ModuleConfigurationServiceInterface $stripeConfig;

    public function __construct(
        ModuleConfigurationServiceInterface $stripeConfig
    ) {
        parent::__construct();

        $this->stripeConfig = $stripeConfig;
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
