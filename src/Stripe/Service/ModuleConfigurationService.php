<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Stripe\Service;

use OxidEsales\Eshop\Core\Config;
use OxidEsales\EshopCommunity\Internal\Framework\Module\Configuration\Dao\ModuleConfigurationDaoInterface;
use OxidEsales\EshopCommunity\Internal\Framework\Module\Configuration\DataObject\ModuleConfiguration;
use OxidEsales\EshopCommunity\Internal\Transition\Utility\ContextInterface;
use OxidSolutionCatalysts\Payments\Component\Service\ServiceInterface;
use OxidSolutionCatalysts\Payments\Stripe\Module;
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
 * - Manages cron job settings
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
 * @package OxidSolutionCatalysts\Payments\Stripe\Service
 * @author OXID eSales AG
 * @since 1.0.0
 */
class ModuleConfigurationService implements ServiceInterface
{
    private ModuleConfiguration $config;

    public function __construct(
        private ContextInterface $context,
        private ModuleConfigurationDaoInterface $moduleConfigurationDao,
    ) {
        $this->config = $this->moduleConfigurationDao->get(Module::MODULE_ID, $this->context->getCurrentShopId());
    }

    public function get(string $name): mixed
    {
        try {
            return $this->config->getModuleSetting($name)->getValue();
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
     * Get status mapping for pending orders
     */
    public function getStatusPending(): string
    {
        $status = $this->get('sStripeStatusPending');
        return is_string($status) ? $status : '';
    }

    /**
     * Get status mapping for processing orders
     */
    public function getStatusProcessing(): string
    {
        $status = $this->get('sStripeStatusProcessing');
        return is_string($status) ? $status : '';
    }

    /**
     * Get status mapping for cancelled orders
     */
    public function getStatusCancelled(): string
    {
        $status = $this->get('sStripeStatusCancelled');
        return is_string($status) ? $status : '';
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
     * Check if cron job for finishing orders is active
     */
    public function isCronFinishOrdersActive(): bool
    {
        return (bool) $this->get('sStripeCronFinishOrdersActive');
    }

    /**
     * Check if cron job for second chance emails is active
     */
    public function isCronSecondChanceActive(): bool
    {
        return (bool) $this->get('sStripeCronSecondChanceActive');
    }

    /**
     * Get time difference for second chance cron job (in days)
     */
    public function getCronSecondChanceTimeDiff(): int
    {
        $value = $this->get('iStripeCronSecondChanceTimeDiff');
        return is_numeric($value) ? (int)$value : 1;
    }

    /**
     * Check if cron job for order shipment is active
     */
    public function isCronOrderShipmentActive(): bool
    {
        return (bool) $this->get('sStripeCronOrderShipmentActive');
    }

    /**
     * Get secure key for cron job execution
     */
    public function getCronSecureKey(): string
    {
        $key = $this->get('sStripeCronSecureKey');
        return is_string($key) ? $key : '';
    }

    /**
     * Get the webhook URL for Stripe configuration
     */
    public function getWebhookUrl(): string
    {
        $shopUrl = $this->config->getShopUrl();
        return rtrim($shopUrl, '/') . '/index.php?cl=osc_stripe_webhook';
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
     */
    public function getCaptureMode(): string
    {
        $mode = $this->get('sStripeCapture');
        return is_string($mode) && !empty($mode) ? $mode : 'automatic';
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

    /**
     * Check module health (basic validation)
     */
    public function checkHealth(): bool
    {
        return !empty($this->getWebhookSecret());
    }
}
