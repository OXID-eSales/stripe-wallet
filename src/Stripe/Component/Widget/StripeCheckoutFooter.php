<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Component\Widget;

use OxidEsales\Eshop\Application\Component\Widget\WidgetController;
use OxidEsales\Payments\Stripe\Service\ModuleConfigurationServiceInterface;
use OxidEsales\Payments\Stripe\Traits\ServiceContainer;

/**
 * Stripe Checkout Footer Widget
 *
 * Renders custom footer for Stripe payments with:
 * - Custom terms checkbox with Stripe-specific disclaimers
 * - Custom submit button with Stripe branding
 * - Additional disclaimers (PCI compliance, data protection)
 * - Stripe branding and secure payment messaging
 *
 * This widget integrates with the one-page checkout footer widget system.
 * It receives standard checkout data via view parameters and adds Stripe-specific
 * configuration and branding.
 *
 * @see docs/FOOTER_WIDGET_ARCHITECTURE.md in one-page-checkout module
 */
class StripeCheckoutFooter extends WidgetController
{
    use ServiceContainer;

    /**
     * Current class template name.
     * Path is relative to module's views/twig directory
     */
    protected $_sThisTemplate = 'widget/checkout/stripe-footer';

    private ?ModuleConfigurationServiceInterface $stripeConfig = null;

    /**
     * Get Stripe configuration service
     *
     * @return ModuleConfigurationServiceInterface|null
     */
    private function getStripeConfig(): ?ModuleConfigurationServiceInterface
    {
        if ($this->stripeConfig === null) {
            try {
                $this->stripeConfig = $this->getServiceFromContainer(
                    ModuleConfigurationServiceInterface::class
                );
            } catch (\Throwable $e) {
                // Service not available
                return null;
            }
        }
        return $this->stripeConfig;
    }

    /**
     * Render widget
     *
     * Collects checkout data from view parameters and adds Stripe-specific
     * configuration for the template.
     *
     * @return string Template path
     */
    public function render()
    {
        parent::render();

        // Get standard checkout data from one-page checkout
        $checkoutData = $this->getCheckoutData();
        $this->addTplParam('checkoutData', $checkoutData);

        // Add Stripe-specific configuration
        $stripeConfig = $this->getStripeConfiguration();
        $this->addTplParam('stripeConfig', $stripeConfig);

        // Add debug flag
        $this->addTplParam('isDebugMode', $this->isDebugMode());

        return $this->_sThisTemplate;
    }

    /**
     * Get checkout data from view parameters
     *
     * Standard data passed by one-page checkout module:
     * - basketId: Current basket ID
     * - paymentMethodId: Selected payment method (e.g., 'oxidstripe')
     * - totalPrice: Total order amount
     * - currency: Currency code (e.g., 'EUR')
     * - csrfToken: CSRF token for API calls
     * - userId: User ID if logged in
     * - userEmail: User email
     *
     * @return array<string, mixed>
     */
    protected function getCheckoutData(): array
    {
        return [
            'basketId' => $this->getViewParameter('basketId') ?? '',
            'paymentMethodId' => $this->getViewParameter('paymentMethodId') ?? '',
            'totalPrice' => $this->getViewParameter('totalPrice') ?? 0.0,
            'currency' => $this->getViewParameter('currency') ?? 'EUR',
            'csrfToken' => $this->getViewParameter('csrfToken') ?? '',
            'userId' => $this->getViewParameter('userId'),
            'userEmail' => $this->getViewParameter('userEmail'),
            'returnUrl' => $this->getViewParameter('returnUrl') ?? '',
            'cancelUrl' => $this->getViewParameter('cancelUrl') ?? '',
        ];
    }

    /**
     * Get Stripe-specific configuration
     *
     * @return array<string, mixed>
     */
    protected function getStripeConfiguration(): array
    {
        $config = $this->getStripeConfig();

        if (!$config) {
            return [
                'mode' => 'test',
                'publishableKey' => '',
                'termsUrl' => $this->getStripeTermsUrl(),
                'privacyUrl' => $this->getStripePrivacyUrl(),
                'isConfigured' => false,
            ];
        }

        return [
            'mode' => $config->getMode(),
            'publishableKey' => $config->getPublishableKey(),
            'termsUrl' => $this->getStripeTermsUrl(),
            'privacyUrl' => $this->getStripePrivacyUrl(),
            'isConfigured' => !empty($config->getPublishableKey()),
        ];
    }

    /**
     * Get Stripe Terms of Service URL
     *
     * @return string
     */
    protected function getStripeTermsUrl(): string
    {
        return 'https://stripe.com/legal/consumer';
    }

    /**
     * Get Stripe Privacy Policy URL
     *
     * @return string
     */
    protected function getStripePrivacyUrl(): string
    {
        return 'https://stripe.com/privacy';
    }

    /**
     * Check if debug mode is enabled
     *
     * @return bool
     */
    protected function isDebugMode(): bool
    {
        // Check if OXID is in debug mode
        $config = \OxidEsales\Eshop\Core\Registry::getConfig();
        if ($config->getConfigParam('iDebug') > 0) {
            return true;
        }

        // Check environment variable
        $envDevMode = getenv('STRIPE_DEV_MODE');
        return $envDevMode === '1' || $envDevMode === 'true';
    }
}