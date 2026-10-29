<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Controller;

use OxidEsales\Payments\Stripe\Core\ShopCurrency;
use OxidEsales\Eshop\Core\Registry;
use OxidEsales\Payments\Stripe\Core\StripeDefinitions;
use OxidEsales\Payments\Stripe\Service\ContractTokenService;
use OxidEsales\Payments\Stripe\Service\LanguageResolverInterface;
use OxidEsales\Payments\Stripe\Service\ModuleConfigurationServiceInterface;
use OxidEsales\Payments\Stripe\Traits\ServiceContainer;

/**
 * Extended payment controller for Stripe integration.
 *
 * Adds Stripe-specific logic to payment method selection page.
 * Session access is fully delegated to ControllerRequestHelper (D6).
 */
class PaymentController extends PaymentController_parent
{
    use ServiceContainer;

    private function getStripeConfig(): ModuleConfigurationServiceInterface
    {
        return $this->getServiceFromContainer(ModuleConfigurationServiceInterface::class);
    }

    /**
     * Testability seam: returns the ControllerRequestHelper for session/request access.
     *
     * Overridden in test subclasses to inject a stub without Registry.
     */
    protected function getRequestHelper(): ControllerRequestHelper
    {
        return new ControllerRequestHelper(
            $this->getServiceFromContainer(ContractTokenService::class),
            $this->getServiceFromContainer(ModuleConfigurationServiceInterface::class),
            $this->getServiceFromContainer(LanguageResolverInterface::class),
        );
    }

    /**
     * Render payment selection page.
     *
     * STRP-105: Clean up stale checkout attempts when user navigates back
     * to the payment page. This covers all back-navigation scenarios
     * (Stripe back arrow, browser back, direct URL) and ensures vouchers
     * consumed by NOT_FINISHED orders are released.
     *
     * @return string Template name
     */
    public function render(): string
    {
        $this->cleanupStaleCheckoutAttempt();

        return parent::render();
    }

    /**
     * Clear the Stripe session keys of an attempt the customer comes back from.
     *
     * OPC-175: the attempt itself is not cancelled any more. One order, one
     * contract: the next start continues the same contract and order with the
     * payment method the customer picks now (payment-base ContractService).
     *
     * Delegates all session reads and clears to ControllerRequestHelper so that
     * the full key set (stripe_payment_intent_id, stripe_client_secret,
     * stripe_checkout_session_id, stripe_contract_id, stripe_skip_addr_check)
     * is always cleared — not a partial subset (D6).
     *
     * Protected so testable subclasses can call this directly without triggering
     * OXID's __call dispatch.
     *
     * @since 2.0.0 STRP-105
     */
    protected function cleanupStaleCheckoutAttempt(): void
    {
        $helper = $this->getRequestHelper();
        if ($helper->getContractIdFromSession() === null) {
            return;
        }

        $helper->clearStripeSessionVariables();
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
            if (!$this->getStripeConfig()->isConfigured()) {
                Registry::getUtilsView()->addErrorToDisplay(
                    'Payment method temporarily unavailable'
                );
                return 'payment';
            }

            // Validate minimum order amount
            $basket = Registry::getSession()->getBasket();
            $total = $basket->getPrice()->getBruttoPrice();
            $minimumAmount = $this->getStripeConfig()->getMinimumOrderAmount();

            if ($total < $minimumAmount) {
                // Sprint 133 (F7): display-only, so an unknown currency shows no
                // code rather than a wrong one.
                $currencyName = ShopCurrency::nameOrEmpty($basket->getBasketCurrency());
                Registry::getUtilsView()->addErrorToDisplay(
                    rtrim(sprintf('Minimum order amount is %.2f %s', $minimumAmount, $currencyName))
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
        /** @var string|null $selectedPayment OXID PHPDoc says string but returns null when no payment set */
        $selectedPayment = Registry::getSession()->getBasket()->getPaymentId(); // @phpstan-ignore variable.phpDocType
        return is_string($selectedPayment) && StripeDefinitions::isStripePaymentMethod($selectedPayment);
    }
}
