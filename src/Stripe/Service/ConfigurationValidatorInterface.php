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
 * @since Sprint 43
 */
interface ConfigurationValidatorInterface
{
    /**
     * Get validation error message for API key configuration.
     *
     * @return string|null Error message or null if configuration is valid
     */
    public function getKeyValidationError(): ?string;
}
