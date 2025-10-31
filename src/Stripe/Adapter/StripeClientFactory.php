<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Stripe\Adapter;

use Stripe\StripeClient;

/**
 * Factory for creating configured Stripe SDK clients.
 *
 * Handles Stripe SDK initialization with proper API keys and configuration.
 *
 * @since 1.0.0
 */
final class StripeClientFactory
{
    /**
     * @param string $secretKey Stripe secret key (test or live)
     * @param bool $testMode Whether to use test mode
     */
    public function __construct(
        private readonly string $secretKey,
        private readonly bool $testMode = true
    ) {
    }

    /**
     * Create a configured Stripe SDK client.
     *
     * @return StripeClient Configured Stripe client
     */
    public function create(): StripeClient
    {
        return new StripeClient([
            'api_key' => $this->secretKey,
            'stripe_version' => '2024-11-20.acacia', // Use latest API version
        ]);
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
