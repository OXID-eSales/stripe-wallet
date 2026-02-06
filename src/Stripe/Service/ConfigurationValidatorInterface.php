<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

/**
 * Interface for Stripe API configuration validation.
 *
 * Validates API credentials, key formats, and connectivity.
 *
 * @since Sprint 43
 */
interface ConfigurationValidatorInterface
{
    /**
     * Validate complete module configuration.
     *
     * @param bool $isTestMode Whether the module is in test mode
     * @param string $secretKey The Stripe secret key to validate
     * @param string $webhookSecret The webhook secret to validate
     * @return array<string, string> Array of validation errors (empty if valid)
     */
    public function validateConfiguration(
        bool $isTestMode,
        string $secretKey,
        string $webhookSecret
    ): array;

    /**
     * Validate API key format matches the expected mode.
     *
     * @param string $apiKey The API key to validate
     * @param bool $isTestMode Whether test mode is enabled
     * @return bool True if format is valid for the mode
     */
    public function validateApiKeyFormat(string $apiKey, bool $isTestMode): bool;

    /**
     * Test connection to Stripe API using configured credentials.
     *
     * @return bool True if connection successful, false otherwise
     */
    public function testConnection(): bool;

    /**
     * Validate that publishable key and secret key are from the same Stripe account.
     *
     * @return bool True if keys are from the same account
     */
    public function validateKeyPair(): bool;

    /**
     * Get validation error message for API key configuration.
     *
     * @return string|null Error message or null if configuration is valid
     */
    public function getKeyValidationError(): ?string;
}
