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
        $mode = (string) $this->config->getConfigParam('sStripeMode');
        return $mode === 'test';
    }

    /**
     * Get the publishable key based on current mode (test/live)
     */
    public function getPublishableKey(): string
    {
        if ($this->isTestMode()) {
            return (string) $this->config->getConfigParam('sStripeTestPk');
        }

        return (string) $this->config->getConfigParam('sStripeLivePk');
    }

    /**
     * Get the secret key based on current mode (test/live)
     */
    public function getSecretKey(): string
    {
        if ($this->isTestMode()) {
            return (string) $this->config->getConfigParam('sStripeTestKey');
        }

        return (string) $this->config->getConfigParam('sStripeLiveKey');
    }

    /**
     * Get the token (API token) based on current mode (test/live)
     */
    public function getToken(): string
    {
        if ($this->isTestMode()) {
            return (string) $this->config->getConfigParam('sStripeTestToken');
        }

        return (string) $this->config->getConfigParam('sStripeLiveToken');
    }

    /**
     * Get the webhook secret based on current mode (test/live)
     */
    public function getWebhookSecret(): string
    {
        return (string) $this->config->getConfigParam('sStripeWebhookEndpointSecret');
    }

    /**
     * Get the webhook endpoint URL
     */
    public function getWebhookEndpoint(): string
    {
        return (string) $this->config->getConfigParam('sStripeWebhookEndpoint');
    }

    /**
     * Check if transaction logging is enabled
     */
    public function isTransactionLoggingEnabled(): bool
    {
        return (bool) $this->config->getConfigParam('blStripeLogTransactionInfo');
    }

    /**
     * Get status mapping for pending orders
     */
    public function getStatusPending(): string
    {
        return (string) $this->config->getConfigParam('sStripeStatusPending');
    }

    /**
     * Get status mapping for processing orders
     */
    public function getStatusProcessing(): string
    {
        return (string) $this->config->getConfigParam('sStripeStatusProcessing');
    }

    /**
     * Get status mapping for cancelled orders
     */
    public function getStatusCancelled(): string
    {
        return (string) $this->config->getConfigParam('sStripeStatusCancelled');
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
