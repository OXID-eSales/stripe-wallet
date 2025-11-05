<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Stripe\Service;

use OxidEsales\Eshop\Core\Config;
use OxidSolutionCatalysts\Payments\Component\Service\ServiceInterface;

/**
 * Service for managing Stripe module configuration settings
 * Handles test/live mode switching and retrieval of API credentials
 */
class ModuleConfigurationService implements ServiceInterface
{
    public function __construct(
        private Config $config
    ) {
    }

    /**
     * Check if the module is in test mode
     */
    public function isTestMode(): bool
    {
        return (bool) $this->config->getConfigParam('osc_stripe_test_mode');
    }

    /**
     * Get the publishable key based on current mode (test/live)
     */
    public function getPublishableKey(): string
    {
        if ($this->isTestMode()) {
            return (string) $this->config->getConfigParam('osc_stripe_test_publishable_key');
        }

        return (string) $this->config->getConfigParam('osc_stripe_live_publishable_key');
    }

    /**
     * Get the secret key based on current mode (test/live)
     */
    public function getSecretKey(): string
    {
        if ($this->isTestMode()) {
            return (string) $this->config->getConfigParam('osc_stripe_test_secret_key');
        }

        return (string) $this->config->getConfigParam('osc_stripe_live_secret_key');
    }

    /**
     * Get the webhook secret based on current mode (test/live)
     */
    public function getWebhookSecret(): string
    {
        if ($this->isTestMode()) {
            return (string) $this->config->getConfigParam('osc_stripe_test_webhook_secret');
        }

        return (string) $this->config->getConfigParam('osc_stripe_live_webhook_secret');
    }

    /**
     * Get the list of enabled payment methods
     *
     * @return array<string>
     */
    public function getPaymentMethods(): array
    {
        return (array) $this->config->getConfigParam('osc_stripe_payment_methods');
    }

    /**
     * Get the capture method (automatic or manual)
     */
    public function getCaptureMethod(): string
    {
        return (string) $this->config->getConfigParam('osc_stripe_capture_method');
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
        return !empty($this->getSecretKey()) && !empty($this->getWebhookSecret());
    }
}
