<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Adapter;

use OxidEsales\Payments\Stripe\Service\ModuleConfigurationServiceInterface;
use Stripe\StripeClient;

/**
 * Factory for creating configured Stripe SDK clients.
 *
 * Handles Stripe SDK initialization with proper API keys and configuration.
 *
 * Implements StripeClientProviderInterface so it can be injected into
 * StripeWebhookEndpointApi, which needs to construct a versioned client
 * from a platform API key that differs from the shop's default secret key.
 *
 * @since 1.0.0
 */
class StripeClientFactory implements StripeClientProviderInterface
{
    private string $secretKey;
    private bool $testMode;

    /**
     * @param ModuleConfigurationServiceInterface $configurationService Module configuration service
     */
    public function __construct(
        private readonly ModuleConfigurationServiceInterface $configurationService
    ) {
        $this->secretKey = $this->configurationService->getToken();
        $this->testMode = $this->configurationService->isTestMode();
    }

    /**
     * Create a configured Stripe SDK client.
     *
     * @return StripeClient Configured Stripe client
     */
    public function create(): ?StripeClient
    {
        return !empty($this->secretKey) ? new StripeClient([
            'api_key' => $this->secretKey,
            'stripe_version' => '2024-11-20.acacia', // Use latest API version
        ]) : null;
    }

    /**
     * Check if factory is configured for test mode.
     *
     * @return bool True if test mode
     */
    public function isTestMode(): bool
    {
        return $this->testMode;
    }

    /**
     * Create a versioned Stripe SDK client for the given API key.
     *
     * Pins the same stripe_version as create() so webhook-registration
     * API calls use the same API contract as the rest of the module.
     *
     * Used by StripeWebhookEndpointApi which receives a platform secret
     * key that may differ from the shop's default key.
     */
    public function forKey(string $apiKey): StripeClient
    {
        return new StripeClient([
            'api_key'        => $apiKey,
            'stripe_version' => '2024-11-20.acacia',
        ]);
    }

    /**
     * Validate that the secret key format is correct.
     *
     * @return bool True if key format is valid
     */
    public function isValidSecretKey(): bool
    {
        if ($this->testMode) {
            return str_starts_with($this->secretKey, 'sk_test_');
        }

        return str_starts_with($this->secretKey, 'sk_live_');
    }
}
