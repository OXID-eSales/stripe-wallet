<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\GraphQL\Admin\Service;

use OxidEsales\Payments\Stripe\Core\StripeDefinitions;
use SensitiveParameter;

/**
 * What an admin asks to change. A field that is null stays as it is; a secret that is null or empty stays as it
 * is (write-only: there is no way to read one back, so "empty" cannot mean "clear it").
 */
final readonly class StripeConfigChange
{
    private const FIELDS = [
        'mode', 'captureMode', 'removeByBillingCountry', 'removeByBasketCurrency', 'provideCustomerEmail', 'logLevel',
        'logWebhooks', 'testPublishableKey', 'livePublishableKey', 'testToken', 'liveToken', 'testPlatformKey',
        'livePlatformKey', 'webhookSecret',
    ];
    private const SECRET_FIELDS = ['testToken', 'liveToken', 'testPlatformKey', 'livePlatformKey', 'webhookSecret'];

    public function __construct(
        public string $expectedVersion,
        public ?string $mode = null,
        public ?string $captureMode = null,
        public ?bool $removeByBillingCountry = null,
        public ?bool $removeByBasketCurrency = null,
        public ?bool $provideCustomerEmail = null,
        public ?string $logLevel = null,
        public ?bool $logWebhooks = null,
        public ?string $testPublishableKey = null,
        public ?string $livePublishableKey = null,
        #[SensitiveParameter] public ?string $testToken = null,
        #[SensitiveParameter] public ?string $liveToken = null,
        #[SensitiveParameter] public ?string $testPlatformKey = null,
        #[SensitiveParameter] public ?string $livePlatformKey = null,
        #[SensitiveParameter] public ?string $webhookSecret = null,
    ) {
    }

    /**
     * The new secret key of the connected account for a mode, or null when this change leaves it alone.
     */
    public function tokenFor(string $mode): ?string
    {
        return self::given($mode === StripeDefinitions::MODE_LIVE ? $this->liveToken : $this->testToken);
    }

    public function platformKeyFor(string $mode): ?string
    {
        return self::given($mode === StripeDefinitions::MODE_LIVE ? $this->livePlatformKey : $this->testPlatformKey);
    }

    public function webhookSecret(): ?string
    {
        return self::given($this->webhookSecret);
    }

    public function publishableKeyFor(string $mode): ?string
    {
        return $mode === StripeDefinitions::MODE_LIVE ? $this->livePublishableKey : $this->testPublishableKey;
    }

    public function writesSecret(): bool
    {
        return $this->firstSecretField() !== null;
    }

    /**
     * The first secret field this change carries (a non-empty value), in the fixed order of the secrets; null when
     * it carries none. A name, never a value.
     */
    public function firstSecretField(): ?string
    {
        foreach (self::SECRET_FIELDS as $field) {
            if ($this->isGiven($field)) {
                return $field;
            }
        }

        return null;
    }

    /**
     * The pair of keys the key-mismatch check judges: the publishable key and the secret key of the account.
     */
    public function touchesKeys(string $mode): bool
    {
        return $this->publishableKeyFor($mode) !== null || $this->tokenFor($mode) !== null;
    }

    /**
     * Names of the fields this change carries; never a value (the audit and logs use this).
     *
     * @return list<string>
     */
    public function fieldNames(): array
    {
        $names = [];
        foreach (self::FIELDS as $field) {
            if ($this->isGiven($field)) {
                $names[] = $field;
            }
        }

        return $names;
    }

    private static function given(?string $secret): ?string
    {
        return $secret === null || $secret === '' ? null : $secret;
    }

    private function isGiven(string $field): bool
    {
        $value = $this->$field;
        if (!in_array($field, self::SECRET_FIELDS, true)) {
            return $value !== null;
        }

        return is_string($value) && $value !== '';
    }
}
