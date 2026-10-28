<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

/**
 * Judges a publishable key and a secret key that are not (yet) the configured ones, e.g. the pair a settings change
 * would produce. Split from {@see ConfigurationValidatorInterface}, which judges the pair in use.
 */
interface KeyPairValidatorInterface
{
    /**
     * @return string|null Error message or null if the two keys belong together
     */
    public function getKeyPairError(string $publishableKey, string $secretKey): ?string;
}
