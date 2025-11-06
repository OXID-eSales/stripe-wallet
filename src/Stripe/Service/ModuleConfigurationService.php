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
        $mode = $this->config->getConfigParam('sStripeMode');
        return is_string($mode) && $mode === 'test';
    }

    /**
     * Get the publishable key based on current mode (test/live)
     */
    public function getPublishableKey(): string
    {
        if ($this->isTestMode()) {
            $key = $this->config->getConfigParam('sStripeTestPk');
            return is_string($key) ? $key : '';
        }

        $key = $this->config->getConfigParam('sStripeLivePk');
        return is_string($key) ? $key : '';
    }

    /**
     * Get the secret key based on current mode (test/live)
     */
    public function getSecretKey(): string
    {
        if ($this->isTestMode()) {
            $key = $this->config->getConfigParam('sStripeTestKey');
            return is_string($key) ? $key : '';
        }

        $key = $this->config->getConfigParam('sStripeLiveKey');
        return is_string($key) ? $key : '';
    }

    /**
     * Get the token (API token) based on current mode (test/live)
     */
    public function getToken(): string
    {
        if ($this->isTestMode()) {
            $token = $this->config->getConfigParam('sStripeTestToken');
            return is_string($token) ? $token : '';
        }

        $token = $this->config->getConfigParam('sStripeLiveToken');
        return is_string($token) ? $token : '';
    }

    /**
     * Get the webhook secret based on current mode (test/live)
     */
    public function getWebhookSecret(): string
    {
        $secret = $this->config->getConfigParam('sStripeWebhookEndpointSecret');
        return is_string($secret) ? $secret : '';
    }

    /**
     * Get the webhook endpoint URL
     */
    public function getWebhookEndpoint(): string
    {
        $endpoint = $this->config->getConfigParam('sStripeWebhookEndpoint');
        return is_string($endpoint) ? $endpoint : '';
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
        $status = $this->config->getConfigParam('sStripeStatusPending');
        return is_string($status) ? $status : '';
    }

    /**
     * Get status mapping for processing orders
     */
    public function getStatusProcessing(): string
    {
        $status = $this->config->getConfigParam('sStripeStatusProcessing');
        return is_string($status) ? $status : '';
    }

    /**
     * Get status mapping for cancelled orders
     */
    public function getStatusCancelled(): string
    {
        $status = $this->config->getConfigParam('sStripeStatusCancelled');
        return is_string($status) ? $status : '';
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
