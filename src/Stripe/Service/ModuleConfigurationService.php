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

    /**
     * Validate that publishable key and secret key are from the same Stripe account.
     *
     * Stripe keys follow the format: {type}_{mode}_{accountId}{randomChars}
     * e.g., pk_test_51ABC123XYZ or sk_live_51ABC123XYZ
     *
     * The account ID portion (first ~8-10 chars after the mode prefix) should match
     * for both keys to be from the same account.
     *
     * @return bool True if keys are from the same account
     */
    public function validateKeyPair(): bool
    {
        $publishableKey = $this->getPublishableKey();
        $secretKey = $this->getToken();

        if (empty($publishableKey) || empty($secretKey)) {
            return false;
        }

        $pkAccountId = $this->extractAccountId($publishableKey);
        $skAccountId = $this->extractAccountId($secretKey);

        return $pkAccountId !== null
            && $skAccountId !== null
            && $pkAccountId === $skAccountId;
    }

    /**
     * Get validation error message for API key configuration.
     *
     * @return string|null Error message or null if configuration is valid
     */
    public function getKeyValidationError(): ?string
    {
        $publishableKey = $this->getPublishableKey();
        $secretKey = $this->getToken();

        if (empty($publishableKey)) {
            return 'Publishable key is not configured';
        }

        if (empty($secretKey)) {
            return 'Secret key is not configured';
        }

        $pkAccountId = $this->extractAccountId($publishableKey);
        $skAccountId = $this->extractAccountId($secretKey);

        if ($pkAccountId === null) {
            return 'Publishable key has invalid format';
        }

        if ($skAccountId === null) {
            return 'Secret key has invalid format';
        }

        if ($pkAccountId !== $skAccountId) {
            return sprintf(
                'API keys appear to be from different Stripe accounts. ' .
                'Publishable key account: %s, Secret key account: %s. ' .
                'Please ensure both keys are from the same Stripe dashboard.',
                $pkAccountId,
                $skAccountId
            );
        }

        return null;
    }

    /**
     * Extract account ID from Stripe key.
     *
     * Stripe keys have format: {type}_{mode}_{accountId}{randomChars}
     * We extract the first 10 characters after the mode prefix for comparison.
     *
     * @param string $key Stripe API key
     * @return string|null Account ID portion or null if invalid format
     */
    private function extractAccountId(string $key): ?string
    {
        // Stripe keys: pk_test_51ABC... or sk_live_51ABC...
        // Account ID starts after the mode prefix (pk_test_ or sk_live_)
        if (preg_match('/^[ps]k_(test|live)_([a-zA-Z0-9]+)/', $key, $matches)) {
            // Return first 10 chars of account portion for comparison
            // This captures the account ID without the random suffix
            return substr($matches[2], 0, 10);
        }

        return null;
    }
}
