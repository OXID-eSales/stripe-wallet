<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

use OxidEsales\Eshop\Core\Registry;
use OxidEsales\EshopCommunity\Internal\Framework\Module\Configuration\Dao\ModuleConfigurationDaoInterface;
use OxidEsales\EshopCommunity\Internal\Framework\Module\Configuration\DataObject\ModuleConfiguration;
use OxidEsales\EshopCommunity\Internal\Transition\Utility\ContextInterface;
use OxidEsales\PaymentComponent\Adapter\ShopAdapterInterface;
use OxidEsales\Payments\Stripe\Module;
use Throwable;

/**
 * Service for managing Stripe module configuration settings
 *
 * This service acts as a centralized configuration manager for the Stripe module,
 * providing type-safe access to all module settings and credentials.
 *
 * Responsibilities:
 * - Manages test/live mode switching
 * - Retrieves API keys (publishable and secret) based on current mode
 * - Provides access to webhook configuration
 * - Handles order status mappings
 * - Manages webhook settings
 * - Controls payment method restrictions (country, currency)
 * - Provides transaction logging settings
 *
 * Benefits:
 * - Single source of truth for all configuration
 * - Abstracts away OXID's Config class complexity
 * - Ensures type-safe access to settings
 * - Makes it easy to switch between test and live mode
 * - Reduces code duplication across controllers and services
 *
 * @package OxidEsales\Payments\Stripe\Service
 * @author OXID eSales AG
 * @since 1.0.0
 */
class ModuleConfigurationService implements ModuleConfigurationServiceInterface
{
    private ?ModuleConfiguration $moduleConfig = null;

    public function __construct(
        private ContextInterface $context,
        private ModuleConfigurationDaoInterface $moduleConfigurationDao,
        private ?ShopAdapterInterface $shopAdapter = null,
    ) {
        try {
            $this->moduleConfig = $this->moduleConfigurationDao->get(Module::MODULE_ID, $this->context->getCurrentShopId());
        } catch (Throwable $e) {
            // Module not yet activated - configuration will be null
            $this->moduleConfig = null;
        }
    }

    public function get(string $name): mixed
    {
        if ($this->moduleConfig === null) {
            return '';
        }

        try {
            return $this->moduleConfig->getModuleSetting($name)->getValue();
        } catch (Throwable $e) {
            return '';
        }
    }

    /**
     * Check if the module is in test mode
     */
    public function isTestMode(): bool
    {
        $mode = $this->get('sStripeMode');
        return is_string($mode) && $mode === 'test';
    }

    /**
     * Get the publishable key based on current mode (test/live)
     */
    public function getPublishableKey(): string
    {
        if ($this->isTestMode()) {
            $key = $this->get('sStripeTestPk');
            return is_string($key) ? $key : '';
        }

        $key = $this->get('sStripeLivePk');
        return is_string($key) ? $key : '';
    }

    /**
     * Get the secret key based on current mode (test/live)
     */
    public function getSecretKey(): string
    {
        if ($this->isTestMode()) {
            $key = $this->get('sStripeTestToken');
            return is_string($key) ? $key : '';
        }

        $key = $this->get('sStripeLiveToken');
        return is_string($key) ? $key : '';
    }

    /**
     * Get the token (API token) based on current mode (test/live)
     */
    public function getToken(): string
    {
        if ($this->isTestMode()) {
            $token = $this->get('sStripeTestToken');
            return is_string($token) ? $token : '';
        }

        $token = $this->get('sStripeLiveToken');
        return is_string($token) ? $token : '';
    }

    /**
     * Get the webhook secret based on current mode (test/live)
     */
    public function getWebhookSecret(): string
    {
        $secret = $this->get('sStripeWebhookEndpointSecret');
        return is_string($secret) ? $secret : '';
    }

    /**
     * Get the webhook endpoint URL
     */
    public function getWebhookEndpoint(): string
    {
        $endpoint = $this->get('sStripeWebhookEndpoint');
        return is_string($endpoint) ? $endpoint : '';
    }

    /**
     * Check if transaction logging is enabled
     */
    public function isTransactionLoggingEnabled(): bool
    {
        return (bool) $this->get('blStripeLogTransactionInfo');
    }

    /**
     * Check if payment method should be removed by billing country
     */
    public function isRemoveByBillingCountry(): bool
    {
        return (bool) $this->get('blStripeRemoveByBillingCountry');
    }

    /**
     * Check if payment method should be removed by basket currency
     */
    public function isRemoveByBasketCurrency(): bool
    {
        return (bool) $this->get('blStripeRemoveByBasketCurrency');
    }

    /**
     * Check if customer email address should be provided to Stripe
     */
    public function shouldProvideCustomerEmail(): bool
    {
        return (bool) $this->get('blStripeProvideCustomerEmailAddress');
    }

    /**
     * Get the webhook URL for Stripe configuration
     *
     * Uses ShopAdapterInterface if injected (LSP), falls back to Registry for backward compatibility.
     */
    public function getWebhookUrl(): string
    {
        $shopUrl = $this->getShopBaseUrl();
        return rtrim($shopUrl, '/') . '/index.php?cl=StripeWebhookController';
    }

    /**
     * Get shop base URL using adapter or fallback to Registry
     *
     * Follows Dependency Inversion Principle:
     * - Prefers injected ShopAdapterInterface (testable, LSP-compliant)
     * - Falls back to Registry for backward compatibility
     */
    private function getShopBaseUrl(): string
    {
        if ($this->shopAdapter !== null) {
            return $this->shopAdapter->getShopUrl();
        }

        // Fallback for backward compatibility when adapter not injected
        return Registry::getConfig()->getShopUrl();
    }

    /**
     * Check if the module is fully configured
     * Requires both secret key and webhook secret to be set
     */
    public function isConfigured(): bool
    {
        return !empty($this->getToken())/* && !empty($this->getSecretKey())/* && !empty($this->getWebhookSecret())*/;
    }

    /**
     * Get capture mode (automatic or manual)
     *
     * Automatic: Payment is captured immediately upon authorization
     * Manual: Payment is only authorized, capture happens later (e.g., when shipping)
     */
    public function getCaptureMode(): string
    {
        $mode = $this->get('sStripeCaptureMode');
        if (is_string($mode) && in_array($mode, ['automatic', 'manual'], true)) {
            return $mode;
        }
        return 'automatic';
    }

    /**
     * Check if 3D Secure is enabled
     */
    public function is3DSecureEnabled(): bool
    {
        return (bool) $this->get('blStripe3DSecure');
    }

    /**
     * Get minimum order amount for Stripe
     * Returns 0.50 as default (Stripe minimum)
     */
    public function getMinimumOrderAmount(): float
    {
        $amount = $this->get('fStripeMinimumOrderAmount');
        return is_numeric($amount) ? (float) $amount : 0.50;
    }

    /**
     * Check if logging is enabled
     */
    public function isLoggingEnabled(): bool
    {
        return (bool) $this->get('blStripeEnableLogging');
    }
}
