<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\GraphQL\Admin\Service;

use OxidEsales\EshopCommunity\Internal\Framework\Module\Facade\ModuleSettingServiceInterface;
use OxidEsales\Payments\Stripe\Core\StripeDefinitions;
use OxidEsales\Payments\Stripe\Module;
use OxidEsales\Payments\Stripe\Service\KeyPairValidatorInterface;
use OxidEsales\Payments\Stripe\Service\ModuleConfigurationServiceInterface;

/**
 * The Stripe module settings through OXID's module setting service (the project configuration), the path of the
 * classic module configuration form. Read straight from it, not through ModuleConfigurationService, which caches
 * the configuration of its construction: after a write the next snapshot must show it.
 * Secrets are read only to say whether they are set and to judge a pair of keys; they never leave this class.
 */
final class OxidStripeAdminSettings implements StripeAdminSettingsInterface
{
    private const LOG_LEVELS = ['off', 'errors', 'normal', 'debug'];
    private const DEFAULT_LOG_LEVEL = 'normal';
    private const TOKEN_SETTINGS = [
        StripeDefinitions::MODE_TEST => 'sStripeTestToken',
        StripeDefinitions::MODE_LIVE => 'sStripeLiveToken',
    ];
    private const PLATFORM_KEY_SETTINGS = [
        StripeDefinitions::MODE_TEST => 'sStripeTestKey',
        StripeDefinitions::MODE_LIVE => 'sStripeLiveKey',
    ];
    private const STRING_FIELDS = [
        'mode' => 'sStripeMode',
        'captureMode' => 'sStripeCaptureMode',
        'logLevel' => 'sStripeLogLevel',
        'testPublishableKey' => 'sStripeTestPk',
        'livePublishableKey' => 'sStripeLivePk',
    ];
    private const BOOLEAN_FIELDS = [
        'removeByBillingCountry' => 'blStripeRemoveByBillingCountry',
        'removeByBasketCurrency' => 'blStripeRemoveByBasketCurrency',
        'provideCustomerEmail' => 'blStripeProvideCustomerEmailAddress',
        'logWebhooks' => 'blStripeLogWebhooks',
    ];
    private const PUBLISHABLE_KEY_SETTINGS = [
        StripeDefinitions::MODE_TEST => 'sStripeTestPk',
        StripeDefinitions::MODE_LIVE => 'sStripeLivePk',
    ];

    public function __construct(
        private readonly ModuleSettingServiceInterface $settings,
        private readonly StripeWebhookStateInterface $webhookState,
        private readonly KeyPairValidatorInterface $keyPairValidator,
        private readonly ModuleConfigurationServiceInterface $config,
    ) {
    }

    public function snapshot(): StripeConfigSnapshot
    {
        $mode = $this->mode();

        return new StripeConfigSnapshot(
            mode: $mode,
            captureMode: $this->captureMode(),
            removeByBillingCountry: $this->bool('blStripeRemoveByBillingCountry'),
            removeByBasketCurrency: $this->bool('blStripeRemoveByBasketCurrency'),
            provideCustomerEmail: $this->bool('blStripeProvideCustomerEmailAddress'),
            logLevel: $this->logLevel(),
            logWebhooks: $this->bool('blStripeLogWebhooks'),
            testPublishableKey: $this->publishableKey(StripeDefinitions::MODE_TEST),
            livePublishableKey: $this->publishableKey(StripeDefinitions::MODE_LIVE),
            webhookEndpointUrl: $this->config->getWebhookUrl(),
            testTokenSet: $this->token(StripeDefinitions::MODE_TEST) !== '',
            liveTokenSet: $this->token(StripeDefinitions::MODE_LIVE) !== '',
            testPlatformKeySet: $this->platformKey(StripeDefinitions::MODE_TEST) !== '',
            livePlatformKeySet: $this->platformKey(StripeDefinitions::MODE_LIVE) !== '',
            webhookSecretSet: $this->webhookState->hasSecret($mode),
            testConnected: $this->isConnected(StripeDefinitions::MODE_TEST),
            liveConnected: $this->isConnected(StripeDefinitions::MODE_LIVE),
            keyValidationError: $this->keyValidationError($mode),
        );
    }

    public function token(string $mode): string
    {
        return $this->string(self::TOKEN_SETTINGS[$mode] ?? self::TOKEN_SETTINGS[StripeDefinitions::MODE_TEST]);
    }

    public function apply(StripeConfigChange $change): void
    {
        foreach (self::STRING_FIELDS as $field => $setting) {
            $value = $change->$field;
            if ($value !== null) {
                $this->settings->saveString($setting, $value, Module::MODULE_ID);
            }
        }
        foreach (self::BOOLEAN_FIELDS as $field => $setting) {
            $value = $change->$field;
            if ($value !== null) {
                $this->settings->saveBoolean($setting, $value, Module::MODULE_ID);
            }
        }
        $this->applySecrets($change);
    }

    private function applySecrets(StripeConfigChange $change): void
    {
        foreach ([StripeDefinitions::MODE_TEST, StripeDefinitions::MODE_LIVE] as $mode) {
            $this->saveSecret(self::TOKEN_SETTINGS[$mode], $change->tokenFor($mode));
            $this->saveSecret(self::PLATFORM_KEY_SETTINGS[$mode], $change->platformKeyFor($mode));
        }
        $webhookSecret = $change->webhookSecret();
        if ($webhookSecret !== null) {
            // The secret belongs to the mode the shop will be in after this change.
            $this->webhookState->storeSecret($change->mode ?? $this->mode(), $webhookSecret);
        }
    }

    private function saveSecret(string $setting, #[\SensitiveParameter] ?string $secret): void
    {
        if ($secret !== null) {
            $this->settings->saveString($setting, $secret, Module::MODULE_ID);
        }
    }

    private function mode(): string
    {
        return $this->string('sStripeMode') === StripeDefinitions::MODE_LIVE
            ? StripeDefinitions::MODE_LIVE
            : StripeDefinitions::MODE_TEST;
    }

    private function captureMode(): string
    {
        return $this->string('sStripeCaptureMode') === StripeDefinitions::CAPTURE_MODE_MANUAL
            ? StripeDefinitions::CAPTURE_MODE_MANUAL
            : StripeDefinitions::CAPTURE_MODE_AUTOMATIC;
    }

    /**
     * The same resolution as ModuleConfigurationService::getLogLevel(): an explicit known level, else the legacy
     * toggle (off when it was switched off), else the default.
     */
    private function logLevel(): string
    {
        $explicit = $this->string('sStripeLogLevel');
        if (in_array($explicit, self::LOG_LEVELS, true)) {
            return $explicit;
        }
        $legacyName = 'blStripeLogTransactionInfo';
        $legacyOff = $explicit === '' && $this->settings->exists($legacyName, Module::MODULE_ID)
            && !$this->bool($legacyName);

        return $legacyOff ? 'off' : self::DEFAULT_LOG_LEVEL;
    }

    private function isConnected(string $mode): bool
    {
        return $this->token($mode) !== '' && $this->publishableKey($mode) !== '';
    }

    private function keyValidationError(string $mode): ?string
    {
        return StripeKeyPairMessage::from(
            $this->keyPairValidator->getKeyPairError($this->publishableKey($mode), $this->token($mode))
        );
    }

    private function publishableKey(string $mode): string
    {
        return $this->string(self::PUBLISHABLE_KEY_SETTINGS[$mode]);
    }

    private function platformKey(string $mode): string
    {
        return $this->string(self::PLATFORM_KEY_SETTINGS[$mode]);
    }

    private function string(string $name): string
    {
        return $this->settings->getString($name, Module::MODULE_ID)->toString();
    }

    private function bool(string $name): bool
    {
        return $this->settings->getBoolean($name, Module::MODULE_ID);
    }
}
