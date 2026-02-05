<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Core;

use OxidEsales\Eshop\Core\Registry;
use OxidEsales\Payments\Stripe\Traits\ServiceContainer;
use OxidEsales\Payments\Stripe\Service\ModuleConfigurationService;
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

    private ?ModuleConfigurationService $stripeConfig = null;

    /**
     * Get the ModuleConfigurationService lazily.
     * Returns null if service is not available (e.g., during module deactivation).
     */
    private function getStripeConfig(): ?ModuleConfigurationService
    {
        if ($this->stripeConfig === null) {
            try {
                $this->stripeConfig = $this->getServiceFromContainer(ModuleConfigurationService::class);
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
        $serverName = $_SERVER['SERVER_NAME'] ?? '';
        if (!is_string($serverName)) {
            $serverName = '';
        }
        $devDomains = ['localhost', '.local', '.dev', '.test', 'oxiddev.de'];
        foreach ($devDomains as $domain) {
            if (strpos($serverName, $domain) !== false) {
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

        // Use module version in production
        return '1.0.0';
    }


    /**
     * @TODO probably needs to be enhanced, more values should be checked
     *
     * @return bool
     */
    public function isStripeCheckoutActive(): bool
    {
        $config = $this->getStripeConfig();
        return $config !== null && !empty($config->getPublishableKey());
    }

    public function getStripeWalletConfig(): ?ModuleConfigurationService
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
}
