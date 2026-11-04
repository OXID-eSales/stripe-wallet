<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

use OxidEsales\Eshop\Core\Registry;
use OxidEsales\EshopCommunity\Internal\Framework\Module\Configuration\Dao\ModuleConfigurationDaoInterface;
use OxidEsales\EshopCommunity\Internal\Transition\Utility\ContextInterface;
use OxidEsales\PaymentBase\Settings\AbstractModuleConfigurationService;
use OxidEsales\Payments\Stripe\Core\StripeDefinitions;
use OxidEsales\Payments\Stripe\Core\WebhookEndpointKeys;
use OxidEsales\Payments\Stripe\Module;
use Psr\Log\LoggerInterface;

/**
 * Service for managing Stripe module configuration settings.
 *
 * Single responsibility: typed setting access (read/cast OXID module settings).
 *
 * Sprint 114.11b (S2): URL construction delegated to StripeUrlBuilder;
 * module-description extraction delegated to ModuleDescriptionProvider.
 * This service keeps only the typed setting getters + isConfigured().
 *
 * @package OxidEsales\Payments\Stripe\Service
 * @since 1.0.0
 */
class ModuleConfigurationService extends AbstractModuleConfigurationService implements ModuleConfigurationServiceInterface
{
    public function __construct(
        ContextInterface $context,
        ModuleConfigurationDaoInterface $moduleConfigurationDao,
        private readonly StripeUrlBuilder $urlBuilder,
        private readonly ModuleDescriptionProvider $descriptionProvider,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($context, $moduleConfigurationDao, $logger);
    }

    protected function moduleId(): string
    {
        return Module::MODULE_ID;
    }

    /**
     * Check if the module is in test mode
     */
    public function isTestMode(): bool
    {
        return $this->getMode() === StripeDefinitions::MODE_TEST;
    }

    /**
     * Returns the current mode string: 'test' or 'live'.
     * Defaults to 'test' when the mode setting is unset; an unreadable configuration throws instead
     * ({@see AbstractModuleConfigurationService::assertReadable()}), so test keys are never used by accident.
     */
    public function getMode(): string
    {
        $this->assertReadable();
        $mode = $this->get('sStripeMode');
        if (is_string($mode) && $mode === StripeDefinitions::MODE_LIVE) {
            return StripeDefinitions::MODE_LIVE;
        }
        return StripeDefinitions::MODE_TEST;
    }

    /**
     * Get the publishable key based on current mode (test/live)
     */
    public function getPublishableKey(): string
    {
        if ($this->isTestMode()) {
            $key = $this->get('sStripeTestPk');
            return is_string($key) ? $key : '';
        }

        $key = $this->get('sStripeLivePk');
        return is_string($key) ? $key : '';
    }

    /**
     * Get the secret API token based on current mode (test/live).
     *
     * This is the single source-of-truth accessor for the Stripe secret key.
     * All callers should use getToken(); getSecretKey() is a backward-compat alias.
     */
    public function getToken(): string
    {
        if ($this->isTestMode()) {
            $token = $this->get('sStripeTestToken');
            return is_string($token) ? $token : '';
        }

        $token = $this->get('sStripeLiveToken');
        return is_string($token) ? $token : '';
    }

    /**
     * Get the webhook secret based on current mode (test/live).
     *
     * Per-mode secrets are stored in oxconfig (not module settings) so they do not
     * surface as editable form fields in the module_config admin form. The legacy
     * single-valued module setting is kept as a fallback for existing installs that
     * pasted a secret manually before auto-registration was available.
     */
    public function getWebhookSecret(): string
    {
        $modeSpecific = $this->readOxConfigVar(WebhookEndpointKeys::secret($this->getMode()));
        if ($modeSpecific !== '') {
            return $modeSpecific;
        }

        $legacy = $this->get(WebhookEndpointKeys::LEGACY_SECRET);
        $legacy = is_string($legacy) ? $legacy : '';
        if ($legacy === '') {
            return '';
        }

        // Sprint 133 · Story 13 (F13): the legacy setting is mode-agnostic, while
        // the per-mode key is chosen by isTestMode(). If the *other* mode already
        // has its own secret, this shop has auto-registered before and the legacy
        // value belongs to that earlier era — using it here would verify, say,
        // live webhooks with a test secret. Every webhook would then 400, Stripe
        // would retry and give up, and the symptom would not be an error in the
        // shop but orders that silently never become paid. Refuse it loudly
        // instead. Shops that never auto-registered keep working unchanged.
        if ($this->readOxConfigVar(WebhookEndpointKeys::secret(WebhookEndpointKeys::otherMode($this->getMode()))) !== '') {
            $this->logger->error(
                'Refusing the legacy mode-agnostic webhook secret: the other mode has its own secret, '
                . 'so this value cannot be trusted for the current mode. Configure the per-mode secret.',
                [
                    'mode' => $this->getMode(),
                    'expected_setting' => WebhookEndpointKeys::secret($this->getMode()),
                ]
            );

            return '';
        }

        return $legacy;
    }

    /**
     * Reads an internal value from oxconfig (module-namespaced).
     *
     * Internal state that must NOT appear in the module_config form (e.g. per-mode
     * webhook endpoint ID and signing secret) is stored here rather than in the
     * module settings YAML.
     *
     * Overridable in test subclasses for unit testing without touching Registry.
     */
    protected function readOxConfigVar(string $key): string
    {
        // OXID's getShopConfVar() PHPDoc says @return object but the actual return
        // value is mixed (string, bool, array, or null depending on oxvartype).
        // Cast to mixed so PHPStan accepts the is_string() guard below.
        /** @var mixed $value */
        $value = Registry::getConfig()->getShopConfVar(
            $key,
            null,
            'module:' . Module::MODULE_ID,
        );

        return is_string($value) ? $value : '';
    }

    /**
     * Get the webhook endpoint URL
     */
    public function getWebhookEndpoint(): string
    {
        $endpoint = $this->get(WebhookEndpointKeys::LEGACY_URL);
        return is_string($endpoint) ? $endpoint : '';
    }

    /**
     * Check if customer email address should be provided to Stripe
     */
    public function shouldProvideCustomerEmail(): bool
    {
        return (bool) $this->get('blStripeProvideCustomerEmailAddress');
    }

    /**
     * Get the webhook URL for Stripe configuration.
     *
     * Delegates to StripeUrlBuilder (Sprint 114.11b S2 extraction).
     */
    public function getWebhookUrl(): string
    {
        return $this->urlBuilder->getWebhookUrl();
    }

    public function getClassicSettingsUrl(): string
    {
        return $this->urlBuilder->getClassicSettingsUrl();
    }

    /**
     * Get the platform secret key for the current mode.
     *
     * This is distinct from the connected-account access_token (sStripeTestToken /
     * sStripeLiveToken). The platform key is pasted manually from the Stripe Dashboard
     * and is required to register Connect webhooks on the platform account.
     */
    public function getPlatformKey(): string
    {
        $settingName = $this->isTestMode() ? 'sStripeTestKey' : 'sStripeLiveKey';
        $key = $this->get($settingName);
        return is_string($key) ? $key : '';
    }

    /**
     * Returns metadata.php's `description.en` for this module.
     *
     * Delegates to ModuleDescriptionProvider (Sprint 114.11b S2 extraction).
     */
    public function getModuleDescription(): string
    {
        return $this->descriptionProvider->getModuleDescription();
    }

    /**
     * Check if the module is fully configured
     * Requires both secret key and webhook secret to be set
     */
    public function isConfigured(): bool
    {
        return !empty($this->getToken()) && !empty($this->getWebhookSecret());
    }

    /**
     * Get capture mode (automatic or manual)
     *
     * Automatic: Payment is captured immediately upon authorization
     * Manual: Payment is only authorized, capture happens later (e.g., when shipping)
     */
    public function getCaptureMode(): string
    {
        $mode = $this->get('sStripeCaptureMode');
        if (is_string($mode) && in_array($mode, [StripeDefinitions::CAPTURE_MODE_AUTOMATIC, StripeDefinitions::CAPTURE_MODE_MANUAL], true)) {
            return $mode;
        }
        return StripeDefinitions::CAPTURE_MODE_AUTOMATIC;
    }

    /**
     * Get minimum order amount for Stripe
     * Returns 0.50 as default (Stripe minimum)
     */
    public function getMinimumOrderAmount(): float
    {
        $amount = $this->get('fStripeMinimumOrderAmount');
        return is_numeric($amount) ? (float) $amount : 0.50;
    }

    /**
     * Resolve the effective log level: off | errors | normal | debug.
     *
     * A known sStripeLogLevel is returned as is; unset, empty or unknown values give the
     * default 'normal'.
     */
    public function getLogLevel(): string
    {
        $explicit = $this->get('sStripeLogLevel');

        return $this->validateLevel(is_string($explicit) ? $explicit : '');
    }

    /**
     * Requests channel: level ∈ {errors, normal, debug}.
     *
     * At 'errors' level exceptions are still logged; full request/response at normal+.
     * This gate controls the channel; individual call severity is not filtered here.
     */
    public function isRequestLoggingEnabled(): bool
    {
        return $this->isAtLeast('errors');
    }

    /**
     * Reconciliation channel: level ∈ {normal, debug}.
     */
    public function isReconciliationLoggingEnabled(): bool
    {
        return $this->isAtLeast('normal');
    }

    /**
     * Events channel: level == debug only.
     */
    public function isEventLoggingEnabled(): bool
    {
        return $this->getLogLevel() === 'debug';
    }

    /**
     * Webhook channel: blStripeLogWebhooks on AND level ∈ {normal, debug}.
     */
    public function isWebhookLoggingEnabled(): bool
    {
        if (!(bool) $this->get('blStripeLogWebhooks')) {
            return false;
        }

        return $this->isAtLeast('normal');
    }

    /**
     * Frontend debug: level == debug. Wired in Phase 5; resolver lives here for DRY.
     */
    public function isFrontendDebugEnabled(): bool
    {
        return $this->getLogLevel() === 'debug';
    }

    // -------------------------------------------------------------------------
    // Private level-resolution helpers
    // -------------------------------------------------------------------------

    /** Ordered severity rank — used by isAtLeast(). */
    private const LEVEL_RANK = ['off' => 0, 'errors' => 1, 'normal' => 2, 'debug' => 3];

    /**
     * Returns the candidate when it is a known level; otherwise 'normal'.
     */
    private function validateLevel(string $candidate): string
    {
        return isset(self::LEVEL_RANK[$candidate]) ? $candidate : 'normal';
    }

    /**
     * Returns true when the current log level is at or above $minimum in the
     * severity order: off < errors < normal < debug.
     */
    private function isAtLeast(string $minimum): bool
    {
        $currentRank = self::LEVEL_RANK[$this->getLogLevel()] ?? 0;
        $minimumRank = self::LEVEL_RANK[$minimum] ?? 0;

        return $currentRank >= $minimumRank;
    }
}
