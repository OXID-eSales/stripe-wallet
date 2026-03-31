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
use OxidEsales\Payments\Stripe\Service\RetryCleanupService;
use OxidEsales\Payments\Stripe\Traits\ServiceContainer;

/**
 * Extended payment controller for Stripe integration
 * Adds Stripe-specific logic to payment method selection page
 */
class PaymentController extends CorePaymentController
{
    use ServiceContainer;

    private function getStripeConfig(): ModuleConfigurationServiceInterface
    {
        return $this->getServiceFromContainer(ModuleConfigurationServiceInterface::class);
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
    public function render()
    {
        $this->cleanupStaleCheckoutAttempt();

        return parent::render();
    }

    /**
     * Clean up a stale Stripe checkout attempt if one exists in the session.
     *
     * When a user navigates back from Stripe Checkout without completing
     * payment, the early-created NOT_FINISHED order still holds vouchers
     * as "used". This method detects that state and runs the cleanup,
     * releasing the vouchers so they can be reused.
     *
     * @since 2.0.0 STRP-105
     */
    private function cleanupStaleCheckoutAttempt(): void
    {
        $session = Registry::getSession();
        /** @var string|null $contractId */
        $contractId = $session->getVariable('stripe_contract_id');

        if ($contractId === null) {
            return;
        }

        try {
            $cleanupService = $this->getServiceFromContainer(RetryCleanupService::class);
            $cleanupService->cleanupPreviousAttempt($contractId);
        } catch (\Throwable $e) {
            Registry::getLogger()->error('STRP-105: Payment page cleanup failed', [
                'error' => $e->getMessage(),
            ]);
        }

        $session->deleteVariable('stripe_contract_id');
        $session->deleteVariable('stripe_checkout_session_id');
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
        /** @var string|null $selectedPayment OXID PHPDoc says string but returns null when no payment set */
        $selectedPayment = Registry::getSession()->getBasket()->getPaymentId(); // @phpstan-ignore variable.phpDocType
        // Check for any Stripe payment method (oe_payments_stripe_* prefix)
        return is_string($selectedPayment) && str_starts_with($selectedPayment, 'oe_payments_stripe_');
    }
}
