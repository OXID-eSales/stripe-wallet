<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Component\Widget;

use OxidEsales\Eshop\Application\Component\Widget\WidgetController;
use OxidEsales\Eshop\Core\Registry;
use OxidEsales\Payments\Stripe\Module;
use OxidEsales\Payments\Stripe\Traits\ServiceContainer;

/**
 * Stripe Checkout Footer Widget for one-page-checkout.
 *
 * Renders a footer with:
 * - Stripe Payment Element container (card form)
 * - stripe-checkout-footer Stimulus controller
 * - Submit button that triggers confirm + placeOrder
 *
 * @since Sprint 80
 */
class StripeCheckoutFooter extends WidgetController
{
    use ServiceContainer;

    protected $_sThisTemplate = '@' . Module::MODULE_ID . '/widget/checkout/stripe-footer.html.twig';

    public function render()
    {
        parent::render();

        $checkoutData = $this->getCheckoutData();
        $this->addTplParam('checkoutData', $checkoutData);

        $stripeConfig = $this->getStripeConfig();
        $this->addTplParam('stripeConfig', $stripeConfig);

        return $this->_sThisTemplate;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getCheckoutData(): array
    {
        return [
            'paymentMethodId' => (string) $this->getViewParameter('paymentMethodId'),
            'totalPrice' => (float) $this->getViewParameter('totalPrice'),
            'currency' => (string) ($this->getViewParameter('currency') ?: 'EUR'),
            'csrfToken' => (string) $this->getViewParameter('csrfToken'),
            'validationUrl' => $this->getShopUrl() . 'index.php?cl=oepaymentvalidationapi&fnc=validate',
            'pluginModuleId' => Module::MODULE_ID,
        ];
    }

    /**
     * Returns the shop base URL for building the validation endpoint URL.
     *
     * Extracted as a protected method so tests can override it without
     * bootstrapping the full OXID Registry.
     */
    protected function getShopUrl(): string
    {
        return Registry::getConfig()->getShopUrl();
    }

    /**
     * @return array<string, string>
     */
    protected function getStripeConfig(): array
    {
        try {
            $configService = $this->getServiceFromContainer(
                \OxidEsales\Payments\Stripe\Service\ModuleConfigurationServiceInterface::class
            );

            return [
                'publishableKey' => $configService->getPublishableKey(),
            ];
        } catch (\Throwable $e) {
            Registry::getLogger()->error('[StripeCheckoutFooter] Failed to get config', [
                'error' => $e->getMessage(),
            ]);

            return [
                'publishableKey' => '',
            ];
        }
    }
}
