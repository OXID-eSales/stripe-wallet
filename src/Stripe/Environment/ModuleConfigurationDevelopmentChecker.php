<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Environment;

use OxidEsales\Payments\Stripe\Service\ModuleConfigurationServiceInterface;

/**
 * Determines development mode based on Stripe module test/live configuration.
 *
 * Test mode = development tools available.
 * Live mode = no debug functions.
 *
 * @since Sprint 63d (STRP-99 H1)
 */
class ModuleConfigurationDevelopmentChecker implements DevelopmentEnvironmentCheckerInterface
{
    public function __construct(
        private readonly ModuleConfigurationServiceInterface $config
    ) {
    }

    public function isDevelopmentMode(): bool
    {
        return $this->config->isTestMode();
    }
}
