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
use OxidEsales\PaymentBase\Adapter\ShopAdapterInterface;
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
        return $this->getMode() === 'test';
    }

    /**
     * Returns the current mode string: 'test' or 'live'.
     * Defaults to 'test' when not configured.
     */
    public function getMode(): string
    {
        $mode = $this->get('sStripeMode');
        if (is_string($mode) && $mode === 'live') {
            return 'live';
        }
        return 'test';
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
     * Get the webhook secret based on current mode (test/live).
     *
     * Per-mode secrets are stored in oxconfig (not module settings) so they do not
     * surface as editable form fields in the module_config admin form. The legacy
     * single-valued module setting is kept as a fallback for existing installs that
     * pasted a secret manually before auto-registration was available.
     */
    public function getWebhookSecret(): string
    {
        $modeSpecific = $this->readOxConfigVar($this->getWebhookSecretKey());
        if ($modeSpecific !== '') {
            return $modeSpecific;
        }

        $legacy = $this->get('sStripeWebhookEndpointSecret');
        return is_string($legacy) ? $legacy : '';
    }

    private function getWebhookSecretKey(): string
    {
        return $this->isTestMode()
            ? 'sStripeWebhookEndpointSecretTest'
            : 'sStripeWebhookEndpointSecretLive';
    }

    /**
     * Reads an internal value from oxconfig (module-namespaced).
     *
     * Internal state that must NOT appear in the module_config form (e.g. per-mode
     * webhook endpoint ID and signing secret) is stored here rather than in the
     * module settings YAML.
     *
     * Overridable in test subclasses for unit testing without touching Registry.
     */
    protected function readOxConfigVar(string $key): string
    {
        // OXID's getShopConfVar() PHPDoc says @return object but the actual return
        // value is mixed (string, bool, array, or null depending on oxvartype).
        // Cast to mixed so PHPStan accepts the is_string() guard below.
        /** @var mixed $value */
        $value = Registry::getConfig()->getShopConfVar(
            $key,
            null,
            'module:' . Module::MODULE_ID,
        );

        return is_string($value) ? $value : '';
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
     * Get the webhook URL for Stripe configuration.
     *
     * Always emits an https:// URL. Stripe rejects http endpoints at
     * WebhookEndpoint::create time, and getCurrentShopUrl()/getShopUrl() will
     * return whatever scheme the current request used — unreliable for an
     * outbound URL stripe will dial back into.
     */
    public function getWebhookUrl(): string
    {
        $shopUrl = $this->getSslShopBaseUrl();
        return rtrim($shopUrl, '/') . '/index.php?cl=StripeWebhookController';
    }

    /**
     * Get shop base URL using adapter or fallback to Registry.
     *
     * Returns whatever scheme the current request uses; for places that need
     * a guaranteed-https URL (e.g. {@see getWebhookUrl()}), use
     * {@see getSslShopBaseUrl()} instead.
     */
    protected function getShopBaseUrl(): string
    {
        if ($this->shopAdapter !== null) {
            return $this->shopAdapter->getShopUrl();
        }

        return Registry::getConfig()->getShopUrl();
    }

    /**
     * Get the SSL form of the shop URL. Used for outbound URLs that third
     * parties dial back into (Stripe webhooks, Connect callbacks).
     */
    protected function getSslShopBaseUrl(): string
    {
        return Registry::getConfig()->getSslShopUrl();
    }

    /**
     * Get the platform secret key for the current mode.
     *
     * This is distinct from the connected-account access_token (sStripeTestToken /
     * sStripeLiveToken). The platform key is pasted manually from the Stripe Dashboard
     * and is required to register Connect webhooks on the platform account.
     */
    public function getPlatformKey(): string
    {
        $settingName = $this->isTestMode() ? 'sStripeTestKey' : 'sStripeLiveKey';
        $key = $this->get($settingName);
        return is_string($key) ? $key : '';
    }

    /**
     * Returns metadata.php's `description.en` for this module.
     *
     * Falls back to the first available translation, then to an empty string when
     * the module is not yet activated (so the registrar can pass an empty string
     * to Stripe rather than crashing).
     */
    public function getModuleDescription(): string
    {
        if ($this->moduleConfig === null) {
            return '';
        }
        $descriptions = $this->moduleConfig->getDescription();
        if (isset($descriptions['en']) && is_string($descriptions['en'])) {
            return $descriptions['en'];
        }
        $first = reset($descriptions);
        return is_string($first) ? $first : '';
    }

    /**
     * Check if the module is fully configured
     * Requires both secret key and webhook secret to be set
     */
    public function isConfigured(): bool
    {
        return !empty($this->getToken()) && !empty($this->getWebhookSecret());
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
