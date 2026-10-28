<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\GraphQL\Admin\Service;

use OxidEsales\Payments\Stripe\Core\StripeDefinitions;

/**
 * The Stripe settings as the admin API shows them. A secret is never part of it: only whether one is set.
 * `version()` is the optimistic-lock token of the settings (module settings have no row timestamp).
 */
final readonly class StripeConfigSnapshot
{
    public function __construct(
        public string $mode,
        public string $captureMode,
        public bool $removeByBillingCountry,
        public bool $removeByBasketCurrency,
        public bool $provideCustomerEmail,
        public string $logLevel,
        public bool $logWebhooks,
        public string $testPublishableKey,
        public string $livePublishableKey,
        public string $webhookEndpointUrl,
        public bool $testTokenSet,
        public bool $liveTokenSet,
        public bool $testPlatformKeySet,
        public bool $livePlatformKeySet,
        public bool $webhookSecretSet,
        public bool $testConnected,
        public bool $liveConnected,
        public ?string $keyValidationError,
    ) {
    }

    public function publishableKey(string $mode): string
    {
        return $mode === StripeDefinitions::MODE_LIVE ? $this->livePublishableKey : $this->testPublishableKey;
    }

    public function tokenSet(string $mode): bool
    {
        return $mode === StripeDefinitions::MODE_LIVE ? $this->liveTokenSet : $this->testTokenSet;
    }

    public function platformKeySet(string $mode): bool
    {
        return $mode === StripeDefinitions::MODE_LIVE ? $this->livePlatformKeySet : $this->testPlatformKeySet;
    }

    public function version(): string
    {
        return hash('sha256', json_encode(get_object_vars($this), JSON_THROW_ON_ERROR));
    }
}
