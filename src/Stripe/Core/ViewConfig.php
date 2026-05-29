<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Core;

use OxidEsales\Eshop\Core\Registry;
use OxidEsales\Payments\Stripe\Traits\ServiceContainer;
use OxidEsales\Payments\Stripe\Service\ModuleConfigurationServiceInterface;
use Throwable;

/**
 * ViewConfig extension for Stripe module
 *
 * Provides helper methods for templates
 * @phpstan-ignore class.notFound
 */
class ViewConfig extends ViewConfig_parent
{
    use ServiceContainer;

    private ?ModuleConfigurationServiceInterface $stripeConfig = null;

    /**
     * Get the ModuleConfigurationServiceInterface lazily.
     * Returns null if service is not available (e.g., during module deactivation).
     */
    private function getStripeConfig(): ?ModuleConfigurationServiceInterface
    {
        if ($this->stripeConfig === null) {
            try {
                $this->stripeConfig = $this->getServiceFromContainer(ModuleConfigurationServiceInterface::class);
            } catch (Throwable $e) {
                // Service not available (module being deactivated)
                return null;
            }
        }
        return $this->stripeConfig;
    }

    /**
     * Check if module is in development mode
     *
     * Development mode can be enabled by:
     * 1. Setting STRIPE_DEV_MODE=1 in .env
     * 2. Checking if OXID is in debug mode
     *
     * @return bool
     */
    public function isStripeDevelopmentMode(): bool
    {
        // Check environment variable first
        $envDevMode = getenv('STRIPE_DEV_MODE');
        if ($envDevMode === '1' || $envDevMode === 'true') {
            return true;
        }

        // Check OXID config
        $config = Registry::getConfig();

        // Check if OXID is in debug mode
        if ($config->getConfigParam('iDebug') > 0) {
            return true;
        }

        // Check if we're on localhost/development domain
        // Sprint 70a (M2): Strict suffix matching — prevents false positives
        // like "attacker.localhost.com" matching "localhost"
        $serverName = $this->getServerName();
        $devDomains = ['localhost', '.local', '.dev', '.test', 'oxiddev.de'];
        foreach ($devDomains as $domain) {
            if ($serverName === $domain || str_ends_with($serverName, $domain)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get Stripe JavaScript file path based on mode
     *
     * @return string
     */
    public function getStripeJsPath(): string
    {
        if ($this->isStripeDevelopmentMode()) {
            return 'js/stripe-frontend.js';
        }

        return 'js/stripe-frontend.min.js';
    }

    /**
     * Check if detailed logging should be enabled
     *
     * @return bool
     */
    public function isStripeDebugEnabled(): bool
    {
        return $this->isStripeDevelopmentMode();
    }

    /**
     * Get Stripe module version (for cache busting)
     *
     * @return string
     */
    public function getStripeModuleVersion(): string
    {
        if ($this->isStripeDevelopmentMode()) {
            // Use timestamp in dev mode for cache busting
            return (string) time();
        }

        // Production: module version + bundle mtime so a rebuilt asset busts
        // the browser cache automatically (the version alone is static).
        return '1.0.0-' . $this->getBundleFingerprint();
    }

    /**
     * Modification time of the served JS bundle, used as a cache-bust suffix.
     */
    private function getBundleFingerprint(): string
    {
        $bundle = __DIR__ . '/../../../assets/' . $this->getStripeJsPath();
        $mtime = is_file($bundle) ? filemtime($bundle) : false;

        return $mtime === false ? '0' : (string) $mtime;
    }


    /**
     * Check if Stripe Checkout is active and ready to present to the customer.
     *
     * Currently checks only for a non-empty publishable key. A fully-configured
     * module also requires a webhook secret — if stricter checks are needed in
     * future, extend to call `$config->isConfigured()` instead.
     *
     * @return bool
     */
    public function isStripeCheckoutActive(): bool
    {
        $config = $this->getStripeConfig();
        return $config !== null && !empty($config->getPublishableKey());
    }

    public function getStripeWalletConfig(): ?ModuleConfigurationServiceInterface
    {
        return $this->getStripeConfig();
    }

    /**
     * Get Stripe publishable key for JavaScript integration
     *
     * @return string
     */
    public function getStripePublishableKey(): string
    {
        $config = $this->getStripeConfig();
        return $config !== null ? $config->getPublishableKey() : '';
    }

    protected function getServerName(): string
    {
        $serverName = $_SERVER['SERVER_NAME'] ?? '';
        return is_string($serverName) ? $serverName : '';
    }
}
