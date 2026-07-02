<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

/**
 * Validator for Stripe API configuration.
 *
 * Validates that publishable and secret keys are from the same Stripe account,
 * providing a human-readable error message when they diverge.
 *
 * @since 2.0.0
 */
class ConfigurationValidator implements ConfigurationValidatorInterface
{
    public function __construct(
        private readonly ModuleConfigurationServiceInterface $config
    ) {
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
