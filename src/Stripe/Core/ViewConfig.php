<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Stripe\Core;

use OxidEsales\Eshop\Core\Registry;
use OxidSolutionCatalysts\Payments\Component\Traits\ServiceContainer;
use OxidSolutionCatalysts\Payments\Stripe\Service\ModuleConfigurationService;

/**
 * ViewConfig extension for Stripe module
 *
 * Provides helper methods for templates
 */
class ViewConfig extends ViewConfig_parent
{

    use ServiceContainer;

    private ModuleConfigurationService $stripeConfig;

    public function __construct()
    {
        parent::__construct();


        $this->stripeConfig =$this->getServiceFromContainer(ModuleConfigurationService::class);
    }

    /**
     * Check if module is in development mode
     *
     * Development mode can be enabled by:
     * 1. Setting STRIPE_DEV_MODE=1 in .env
     * 2. Setting sStripeDevMode config in admin
     * 3. Checking if OXID is in debug mode
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

        // Check module-specific setting
        $moduleDevMode = $config->getConfigParam('sStripeDevMode');
        if ($moduleDevMode) {
            return true;
        }

        // Check if OXID is in debug mode
        if ($config->getConfigParam('iDebug') > 0) {
            return true;
        }

        // Check if we're on localhost/development domain
        $serverName = $_SERVER['SERVER_NAME'] ?? '';
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
        return !empty($this->stripeConfig->getPublishableKey());
    }
    public function getStripeWalletConfig(): ModuleConfigurationService
    {
        return $this->stripeConfig;
    }
}
