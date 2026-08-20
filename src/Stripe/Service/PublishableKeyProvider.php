<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Resolves the Stripe publishable key for the frontend, or reports why it cannot.
 *
 * Sprint 133 · Story 19 (F19): ViewConfig mapped every failure to an empty
 * string. The redirect checkout controller does guard an empty key (it shows a
 * translated configuration error), but nothing was ever written on the *server*,
 * so a merchant whose key is unset sees a dead checkout with no log line to
 * explain it — and the embedded/footer path passed the empty string straight
 * into `window.Stripe()`.
 *
 * Returning null rather than '' forces the caller to decide, and the missing-key
 * case is logged once with the mode, so the setting to fill is obvious.
 *
 * @since 2.0.0
 */
class PublishableKeyProvider
{
    private readonly LoggerInterface $logger;

    private bool $reported = false;

    public function __construct(
        private readonly ModuleConfigurationServiceInterface $config,
        ?LoggerInterface $logger = null
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * @return string|null The publishable key, or null when it is not configured.
     */
    public function resolve(): ?string
    {
        $key = $this->config->getPublishableKey();

        if ($key !== '') {
            return $key;
        }

        // Logged once per request: the frontend asks for this on every render.
        if (!$this->reported) {
            $this->reported = true;
            $this->logger->error(
                'Stripe publishable key is not configured; checkout cannot be rendered',
                [
                    'mode' => $this->config->getMode(),
                    'expected_setting' => $this->config->isTestMode() ? 'sStripeTestPk' : 'sStripeLivePk',
                ]
            );
        }

        return null;
    }

    public function isAvailable(): bool
    {
        return $this->resolve() !== null;
    }
}
