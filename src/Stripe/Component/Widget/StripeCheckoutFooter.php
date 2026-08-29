<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Component\Widget;

use OxidEsales\Eshop\Application\Component\Widget\WidgetController;
use OxidEsales\Eshop\Core\Registry;
use OxidEsales\Payments\Stripe\Core\ShopCurrency;
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
            'currency' => $this->resolveCurrency(),
            'csrfToken' => (string) $this->getViewParameter('csrfToken'),
            'validationUrl' => $this->getShopUrl() . 'index.php?cl=oepaymentvalidationapi&fnc=validate',
            'pluginModuleId' => Module::MODULE_ID,
            'fieldAllowed' => $this->getValidationFieldAllowed(),
        ];
    }

    /**
     * Sprint 133 (F7): the caller's view parameter, else the shop's actual
     * currency, else nothing. Never a hardcoded 'EUR', which mislabels the
     * checkout footer on any non-EUR shop.
     */
    protected function resolveCurrency(): string
    {
        $fromView = (string) $this->getViewParameter('currency');
        if ($fromView !== '') {
            return $fromView;
        }

        return ShopCurrency::nameOrEmpty(Registry::getConfig()->getActShopCurrencyObject());
    }

    /**
     * field => human-readable allowed-symbols string (e.g. "letters, spaces, ' - .").
     * Consumed by the OPC widget to put `allowed` in the per-field
     * `oe:payment:error:field` event. Returns [] if unavailable (never throws).
     *
     * @return array<string, string>
     */
    protected function getValidationFieldAllowed(): array
    {
        try {
            $provider = $this->getServiceFromContainer(
                \OxidEsales\Payments\Stripe\Service\ValidationRulesProvider::class
            );
            $describer = $this->getServiceFromContainer(
                \OxidEsales\Payments\Stripe\Service\AllowedSymbolsDescriber::class
            );

            $allowed = [];
            foreach (array_keys($provider->getFieldAllowMap()) as $field) {
                $allowed[$field] = $describer->describe($field);
            }

            return $allowed;
        } catch (\Throwable $e) {
            Registry::getLogger()->error('[StripeCheckoutFooter] Failed to build allowed-symbols map', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }
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
        return [
            'publishableKey' => $this->resolvePublishableKey(),
            'renderMode' => $this->resolveRenderMode(),
            'mountMode' => $this->resolveMountMode(),
        ];
    }

    /**
     * When the embedded sheet appears: on the Pay button, or on its own.
     *
     * Falls back to `manual` on any failure, and that direction is deliberate.
     * Every mount creates a Stripe checkout session, which freezes an amount —
     * so the safe default when the setting cannot be read is the mode where a
     * session only exists because the shopper asked for one.
     */
    private function resolveMountMode(): string
    {
        try {
            $configService = $this->getServiceFromContainer(
                \OxidEsales\Payments\Stripe\Service\ModuleConfigurationServiceInterface::class
            );

            return $configService->get('sStripeEmbeddedMountMode') === 'auto' ? 'auto' : 'manual';
        } catch (\Throwable $e) {
            return 'manual';
        }
    }

    private function resolvePublishableKey(): string
    {
        try {
            $configService = $this->getServiceFromContainer(
                \OxidEsales\Payments\Stripe\Service\ModuleConfigurationServiceInterface::class
            );

            return $configService->getPublishableKey();
        } catch (\Throwable $e) {
            Registry::getLogger()->error('[StripeCheckoutFooter] Failed to get config', [
                'error' => $e->getMessage(),
            ]);

            return '';
        }
    }

    /**
     * Provider-agnostic render mode: 'iframe' when the payment-base
     * "Use iframe instead of checkout button" flag is on, else 'redirect'.
     * Defaults to 'redirect' if the flag cannot be read (never throws).
     */
    private function resolveRenderMode(): string
    {
        try {
            $iframeSettings = $this->getServiceFromContainer(
                \OxidEsales\PaymentBase\Service\IframeCheckoutSettingsInterface::class
            );

            return $iframeSettings->isEnabled() ? 'iframe' : 'redirect';
        } catch (\Throwable $e) {
            return 'redirect';
        }
    }
}
