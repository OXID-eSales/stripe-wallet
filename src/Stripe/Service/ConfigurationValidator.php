<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

use OxidEsales\Payments\Stripe\Service\Factory\StripeAdapterFactoryInterface;

/**
 * Validator for Stripe API configuration.
 *
 * Sprint 18: Refactored to use adapter instead of direct SDK.
 *
 * This service validates Stripe API credentials and configuration settings to ensure
 * the module is properly configured before processing payments.
 *
 * Responsibilities:
 * - Validates API key formats (test vs live mode)
 * - Ensures test keys start with 'sk_test_' and live keys with 'sk_live_'
 * - Validates webhook secret format (must start with 'whsec_')
 * - Tests connection to Stripe API to verify credentials work
 * - Returns detailed validation errors for debugging
 *
 * @since 2.0.0
 */
class ConfigurationValidator implements ConfigurationValidatorInterface
{
    private const TEST_KEY_PREFIX = 'sk_test_';
    private const LIVE_KEY_PREFIX = 'sk_live_';
    private const WEBHOOK_SECRET_PREFIX = 'whsec_';

    public function __construct(
        private readonly StripeAdapterFactoryInterface $adapterFactory,
        private readonly ModuleConfigurationServiceInterface $config
    ) {
    }

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
    ): array {
        $errors = [];

        // Validate secret key
        if (empty($secretKey)) {
            $errors['secretKey'] = 'Secret key is required';
        } elseif (!$this->validateApiKeyFormat($secretKey, $isTestMode)) {
            $errors['secretKey'] = $isTestMode
                ? 'Test mode requires test key (sk_test_...)'
                : 'Live mode requires live key (sk_live_...)';
        }

        // Validate webhook secret
        if (empty($webhookSecret)) {
            $errors['webhookSecret'] = 'Webhook secret is required';
        } elseif (
            !str_starts_with($webhookSecret, self::WEBHOOK_SECRET_PREFIX) ||
                  strlen($webhookSecret) <= strlen(self::WEBHOOK_SECRET_PREFIX)
        ) {
            $errors['webhookSecret'] = 'Invalid webhook secret format (must start with whsec_)';
        }

        return $errors;
    }

    /**
     * Validate API key format matches the expected mode.
     *
     * @param string $apiKey The API key to validate
     * @param bool $isTestMode Whether test mode is enabled
     * @return bool True if format is valid for the mode
     */
    public function validateApiKeyFormat(string $apiKey, bool $isTestMode): bool
    {
        $expectedPrefix = $isTestMode ? self::TEST_KEY_PREFIX : self::LIVE_KEY_PREFIX;
        return str_starts_with($apiKey, $expectedPrefix);
    }

    /**
     * Test connection to Stripe API using configured credentials.
     *
     * Uses the adapter to verify API connectivity instead of creating
     * a StripeClient directly.
     *
     * @return bool True if connection successful, false otherwise
     */
    public function testConnection(): bool
    {
        try {
            $adapter = $this->adapterFactory->getStripeAdapter();
            return $adapter->testConnection();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Validate that publishable key and secret key are from the same Stripe account.
     *
     * Stripe keys follow the format: {type}_{mode}_{accountId}{randomChars}
     * The account ID portion should match for both keys.
     */
    public function validateKeyPair(): bool
    {
        $publishableKey = $this->config->getPublishableKey();
        $secretKey = $this->config->getToken();

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
        $publishableKey = $this->config->getPublishableKey();
        $secretKey = $this->config->getToken();

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
     * Extract account ID from Stripe key for comparison.
     */
    private function extractAccountId(string $key): ?string
    {
        if (preg_match('/^[ps]k_(test|live)_([a-zA-Z0-9]+)/', $key, $matches)) {
            return substr($matches[2], 0, 10);
        }

        return null;
    }
}
