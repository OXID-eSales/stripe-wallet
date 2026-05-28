<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Controller\Admin;

use OxidEsales\Eshop\Core\Registry;
use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\EshopCommunity\Internal\Framework\Module\Configuration\Bridge\ModuleSettingBridgeInterface;
use OxidEsales\Payments\Stripe\Module;
use OxidEsales\Payments\Stripe\Service\ConfigurationValidatorInterface;
use OxidEsales\Payments\Stripe\Service\Exception\WebhookRegistrationException;
use OxidEsales\Payments\Stripe\Service\ModuleConfigurationServiceInterface;
use OxidEsales\Payments\Stripe\Service\WebhookEndpointRegistrarInterface;
use OxidEsales\Payments\Stripe\Service\WebhookEndpointRegistrationResult;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Extended admin ModuleConfiguration controller for Stripe module settings.
 *
 * OXID class extensions cannot use standard constructor DI because
 * ModuleConfiguration_parent is a virtual class created at runtime by OXID's
 * class-chain system; we resolve services lazily via ContainerFactory and route
 * them through a `protected initializeWebhookCollaborators()` seam for tests.
 *
 * Hosts:
 *   - "Create webhooks" AJAX action          : stripeCreateWebhookEndpoint()
 *   - "Clear all webhooks" AJAX action       : stripeClearAllWebhookEndpoints()
 *   - View helpers consumed by the module_config Twig extension.
 *
 * Storage:
 *   - Per-mode endpoint ID + signing secret (`sStripeWebhookEndpointIdTest/Live`,
 *     `sStripeWebhookEndpointSecretTest/Live`) live in oxconfig (module-namespaced).
 *     This keeps them OUT of the editable module_config form.
 *   - The legacy single-valued module settings (`sStripeWebhookEndpoint`,
 *     `sStripeWebhookEndpointSecret`) are ALSO written so the admin sees the
 *     registered URL + secret in the form on the next reload.
 */
class ModuleConfiguration extends ModuleConfiguration_parent
{
    private ?ModuleConfigurationServiceInterface $moduleConfig = null;
    private ?WebhookEndpointRegistrarInterface $webhookRegistrar = null;
    private ?LoggerInterface $webhookLogger = null;
    private ?ConfigurationValidatorInterface $configurationValidator = null;
    private ?ModuleSettingBridgeInterface $moduleSettingBridge = null;

    private function getModuleConfig(): ModuleConfigurationServiceInterface
    {
        if ($this->moduleConfig === null) {
            /** @var ModuleConfigurationServiceInterface $service */
            $service = ContainerFactory::getInstance()
                ->getContainer()
                ->get(ModuleConfigurationServiceInterface::class);
            $this->moduleConfig = $service;
        }
        return $this->moduleConfig;
    }

    private function getWebhookRegistrar(): WebhookEndpointRegistrarInterface
    {
        if ($this->webhookRegistrar === null) {
            /** @var WebhookEndpointRegistrarInterface $registrar */
            $registrar = ContainerFactory::getInstance()
                ->getContainer()
                ->get(WebhookEndpointRegistrarInterface::class);
            $this->webhookRegistrar = $registrar;
        }
        return $this->webhookRegistrar;
    }

    private function getWebhookLogger(): LoggerInterface
    {
        if ($this->webhookLogger === null) {
            $container = ContainerFactory::getInstance()->getContainer();
            $logger = $container->has(LoggerInterface::class)
                ? $container->get(LoggerInterface::class)
                : new NullLogger();
            assert($logger instanceof LoggerInterface);
            $this->webhookLogger = $logger;
        }
        return $this->webhookLogger;
    }

    protected function getConfigurationValidator(): ConfigurationValidatorInterface
    {
        if ($this->configurationValidator === null) {
            /** @var ConfigurationValidatorInterface $validator */
            $validator = ContainerFactory::getInstance()
                ->getContainer()
                ->get(ConfigurationValidatorInterface::class);
            $this->configurationValidator = $validator;
        }
        return $this->configurationValidator;
    }

    private function getModuleSettingBridge(): ModuleSettingBridgeInterface
    {
        if ($this->moduleSettingBridge === null) {
            $bridge = ContainerFactory::getInstance()
                ->getContainer()
                ->get(ModuleSettingBridgeInterface::class);
            assert($bridge instanceof ModuleSettingBridgeInterface);
            $this->moduleSettingBridge = $bridge;
        }
        return $this->moduleSettingBridge;
    }

    /**
     * Constructor seam — test subclasses bypass parent::__construct() and call
     * this directly with mocked collaborators.
     */
    protected function initializeWebhookCollaborators(
        WebhookEndpointRegistrarInterface $registrar,
        ModuleConfigurationServiceInterface $moduleConfig,
        LoggerInterface $logger
    ): void {
        $this->webhookRegistrar = $registrar;
        $this->moduleConfig     = $moduleConfig;
        $this->webhookLogger    = $logger;
    }

    /**
     * @return bool
     */
    public function stripeIsTestMode(): bool
    {
        return $this->getModuleConfig()->isTestMode();
    }

    /**
     * Check if the module is fully configured (API key + webhook secret).
     *
     * Delegates to the service's isConfigured() — the single canonical definition
     * (D9). Using the full check ensures the admin UI reflects the same state that
     * PaymentController uses when deciding whether to offer Stripe as a payment method.
     */
    public function stripeHasApiKeys(): bool
    {
        return $this->getModuleConfig()->isConfigured();
    }

    /**
     * @TODO Find a more descriptive name for this method
     *
     * @return bool
     */
    public function stripeIsStripe(): bool
    {
        return $this->getEditObjectId() == Module::MODULE_ID;
    }

    /**
     * Get API key validation error message for template display.
     *
     * Returns an error message if the publishable key and secret key
     * appear to be from different Stripe accounts, null if they match.
     *
     * @return string|null Error message or null if keys are valid
     */
    public function stripeGetKeyValidationError(): ?string
    {
        return $this->getConfigurationValidator()->getKeyValidationError();
    }

    /**
     * Generate Stripe Connect URL for onboarding
     *
     * @param string $sVarName
     * @return string
     */
    public function stripeGetConnectUrl(string $sVarName): string
    {
        $sMode = $sVarName === 'sStripeTestToken' ? 'test' : 'live';
        $redirectUrl = Registry::getConfig()->getShopUrl(0, true) . 'admin/index.php?cl=StripeConnect&fnc=stripeFinishOnBoarding';
        $redirectUrl .= '&stoken=' . Registry::getSession()->getSessionChallengeToken();
        $redirectUrl .= '&shop_param=' . $sMode;
        $redirectUrl .= '&shp=' . Registry::getConfig()->getShopId();

        if ($sMode === 'test') {
            return 'https://stripe-middleware-test.oxid-esales.com/stripe-connect?shop_redirect_url=' . rawurlencode($redirectUrl);
        }
        return 'https://osm.oxid-esales.com/stripe-connect?shop_redirect_url=' . rawurlencode($redirectUrl);
    }

    // -------------------------------------------------------------------------
    // Webhook registration AJAX action
    // -------------------------------------------------------------------------

    /**
     * AJAX action — registers a Stripe Connect webhook using the platform secret key.
     *
     * Called by the "Create webhooks" button on the module_config admin form (next to
     * the Webhook Endpoint field).
     * Returns a flat JSON envelope: {"success": bool, "endpointId"?: string, "message"?: string}.
     */
    public function stripeCreateWebhookEndpoint(): void
    {
        if (!Registry::getSession()->checkSessionChallenge()) {
            $this->respondJson(403, [
                'success' => false,
                'message' => $this->translate('STRIPE_WEBHOOK_SESSION_EXPIRED'),
            ]);
            return;
        }

        $moduleConfig = $this->getModuleConfig();
        $mode         = $moduleConfig->getMode();
        $platformKey  = $moduleConfig->getPlatformKey();

        if ($platformKey === '') {
            $this->respondJson(400, [
                'success' => false,
                'message' => $this->translate('STRIPE_WEBHOOK_PLATFORM_KEY_MISSING'),
            ]);
            return;
        }

        try {
            $existingId  = $this->readOxConfigVar($this->endpointIdKey($mode));
            $webhookUrl  = $moduleConfig->getWebhookUrl();
            $description = $moduleConfig->getModuleDescription();
            $result      = $this->getWebhookRegistrar()->register(
                $platformKey,
                $webhookUrl,
                $existingId,
                true, // Connect webhook — connected-account tokens cannot create webhooks at all.
                $description
            );
            $this->persistEndpoint($mode, $result, $webhookUrl);
            $this->respondJson(200, [
                'success'        => true,
                'endpointId'     => $result->endpointId,
                'endpointSecret' => $result->secret,
                'webhookUrl'     => $webhookUrl,
            ]);
        } catch (WebhookRegistrationException $e) {
            $this->getWebhookLogger()->warning('Stripe webhook creation failed', [
                'reason' => $e->getMessage(),
                'mode'   => $mode,
            ]);
            $this->respondJson(400, ['success' => false, 'message' => $e->getMessage()]);
        }
    }

    /**
     * AJAX action: deletes every webhook endpoint on the Stripe platform account
     * whose `url` matches THIS shop's webhook URL, then clears the locally-tracked
     * endpoint metadata.
     *
     * Endpoints pointing elsewhere (e.g. another shop sharing the same Stripe
     * key) are preserved. Used to recover from duplicates or to reset the shop's
     * webhook setup before re-creating from scratch.
     */
    public function stripeClearAllWebhookEndpoints(): void
    {
        if (!Registry::getSession()->checkSessionChallenge()) {
            $this->respondJson(403, [
                'success' => false,
                'message' => $this->translate('STRIPE_WEBHOOK_SESSION_EXPIRED'),
            ]);
            return;
        }

        $platformKey = $this->getModuleConfig()->getPlatformKey();
        if ($platformKey === '') {
            $this->respondJson(400, [
                'success' => false,
                'message' => $this->translate('STRIPE_WEBHOOK_PLATFORM_KEY_MISSING'),
            ]);
            return;
        }

        try {
            $shopWebhookUrl = $this->getModuleConfig()->getWebhookUrl();
            $deleted        = $this->getWebhookRegistrar()->clearAll($platformKey, $shopWebhookUrl);
            $this->forgetAllLocalEndpointMetadata();
            $this->respondJson(200, ['success' => true, 'deleted' => $deleted]);
        } catch (WebhookRegistrationException $e) {
            $this->getWebhookLogger()->warning('Stripe webhook clear-all failed', [
                'reason' => $e->getMessage(),
            ]);
            $this->respondJson(400, ['success' => false, 'message' => $e->getMessage()]);
        }
    }

    private function forgetAllLocalEndpointMetadata(): void
    {
        foreach (['test', 'live'] as $mode) {
            $this->saveOxConfigVar($this->endpointIdKey($mode), '');
            $this->saveOxConfigVar($this->endpointSecretKey($mode), '');
        }
        $this->saveModuleSetting('sStripeWebhookEndpoint', '');
        $this->saveModuleSetting('sStripeWebhookEndpointSecret', '');
    }

    // -------------------------------------------------------------------------
    // Template helper methods
    // -------------------------------------------------------------------------

    /**
     * Returns true when the shop has a usable webhook setup.
     *
     * "Usable" means signatures can be verified — so URL alone is not enough;
     * the signing secret must also be present (either via the per-mode oxconfig
     * entry set by the "Create webhooks" action, or via the legacy module settings
     * a previous admin may have pasted by hand).
     */
    public function stripeIsWebhookConfigured(): bool
    {
        $config     = $this->getModuleConfig();
        $mode       = $config->getMode();
        $endpointId = $this->readOxConfigVar($this->endpointIdKey($mode));
        if ($endpointId !== null) {
            return true;
        }
        return $config->getWebhookEndpoint() !== '' && $config->getWebhookSecret() !== '';
    }

    /**
     * Returns true if the platform secret key is set for the current mode.
     * Used by the template to enable/disable the "Create webhooks" button.
     */
    public function stripeIsPlatformKeyConfigured(): bool
    {
        return $this->getModuleConfig()->getPlatformKey() !== '';
    }

    /**
     * Returns the URL for the "Create webhooks" button AJAX POST.
     */
    public function stripeGetCreateWebhookUrl(): string
    {
        return '?cl=module_config&fnc=stripeCreateWebhookEndpoint&stoken='
            . rawurlencode($this->getSessionChallengeToken());
    }

    /**
     * Returns the URL for the "Clear all webhooks" button AJAX POST.
     */
    public function stripeGetClearWebhooksUrl(): string
    {
        return '?cl=module_config&fnc=stripeClearAllWebhookEndpoints&stoken='
            . rawurlencode($this->getSessionChallengeToken());
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function endpointIdKey(string $mode): string
    {
        return $mode === 'live' ? 'sStripeWebhookEndpointIdLive' : 'sStripeWebhookEndpointIdTest';
    }

    private function endpointSecretKey(string $mode): string
    {
        return $mode === 'live' ? 'sStripeWebhookEndpointSecretLive' : 'sStripeWebhookEndpointSecretTest';
    }

    private function persistEndpoint(
        string $mode,
        WebhookEndpointRegistrationResult $result,
        string $webhookUrl
    ): void {
        // Per-mode tracking in oxconfig: internal state, never surfaced in the form.
        $this->saveOxConfigVar($this->endpointIdKey($mode), $result->endpointId);
        if ($result->secret !== null) {
            $this->saveOxConfigVar($this->endpointSecretKey($mode), $result->secret);
        }

        // Legacy single-valued module settings: surfaced in the form, so the admin
        // sees the registered URL + secret immediately on the next reload.
        $this->saveModuleSetting('sStripeWebhookEndpoint', $webhookUrl);
        if ($result->secret !== null) {
            $this->saveModuleSetting('sStripeWebhookEndpointSecret', $result->secret);
        }
    }

    /**
     * Saves a value into the module's standard ModuleSettingService storage.
     *
     * Overridable in test subclasses so unit tests do not depend on the OXID DI container.
     */
    protected function saveModuleSetting(string $key, string $value): void
    {
        $this->getModuleSettingBridge()->save($key, $value, Module::MODULE_ID);
    }

    /**
     * Returns the session challenge token.
     *
     * Overridable in test subclasses to avoid touching Registry::getSession().
     */
    protected function getSessionChallengeToken(): string
    {
        return Registry::getSession()->getSessionChallengeToken();
    }

    /**
     * Reads an internal endpoint metadata value from oxconfig.
     *
     * Stored as module-namespaced shop config so it is NOT surfaced in the
     * module_config admin form (which reads from the module settings YAML only).
     *
     * Overridable in test subclasses for unit testing without touching Registry.
     */
    protected function readOxConfigVar(string $key): ?string
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

        if (is_string($value) && $value !== '') {
            return $value;
        }

        return null;
    }

    /**
     * Saves an internal endpoint metadata value to oxconfig.
     *
     * Uses module-namespaced shop config so it is NOT surfaced in the
     * module_config admin form.
     *
     * Overridable in test subclasses for unit testing without touching Registry.
     */
    protected function saveOxConfigVar(string $key, string $value): void
    {
        Registry::getConfig()->saveShopConfVar(
            'str',
            $key,
            $value,
            null,
            'module:' . Module::MODULE_ID,
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function respondJson(int $statusCode, array $payload): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode($payload);
        $this->terminate();
    }

    /**
     * Stops further OXID page rendering after an AJAX response has been emitted.
     *
     * Without this, ShopControl appends the full admin page HTML to the JSON body and the
     * client's JSON.parse() fails. Extracted as a seam so tests can override it without exit().
     */
    protected function terminate(): void
    {
        exit;
    }

    /**
     * Resolves a lang key to its translated text in the current admin language.
     *
     * Extracted as a seam so tests can override it without depending on
     * Registry::getLang() / file-based lang resolution.
     */
    protected function translate(string $ident): string
    {
        $translated = Registry::getLang()->translateString($ident);
        return is_string($translated) ? $translated : $ident;
    }
}
