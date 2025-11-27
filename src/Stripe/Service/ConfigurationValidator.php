<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Stripe\Service;

use OxidSolutionCatalysts\Payments\Component\Service\ServiceInterface;
use Stripe\StripeClient;

/**
 * Validator for Stripe API configuration
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
 * Usage:
 * Called during module configuration in admin panel and before payment initialization
 * to prevent configuration errors that would cause runtime failures.
 *
 * @package OxidSolutionCatalysts\Payments\Stripe\Service
 * @author OXID eSales AG
 * @since 1.0.0
 */
class ConfigurationValidator implements ServiceInterface
{
    private const TEST_KEY_PREFIX = 'sk_test_';
    private const LIVE_KEY_PREFIX = 'sk_live_';
    private const WEBHOOK_SECRET_PREFIX = 'whsec_';

    /**
     * Validate complete module configuration
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
     * Validate API key format matches the expected mode
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
     * Test connection to Stripe API
     *
     * @param string $secretKey The Stripe secret key to test
     * @return bool True if connection successful, false otherwise
     */
    public function testConnection(string $secretKey): bool
    {
        try {
            $stripe = new StripeClient($secretKey);
            $stripe->balance->retrieve();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}
