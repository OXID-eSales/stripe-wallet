<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Stripe\Application\Controller\Admin;

use OxidEsales\Eshop\Core\Registry;
use OxidSolutionCatalysts\Payments\Component\Traits\ServiceContainer;
use OxidSolutionCatalysts\Payments\Stripe\Service\ModuleConfigurationService;

class ModuleConfiguration extends ModuleConfiguration_parent
{
    use ServiceContainer;

    /**
     * @var \OxidSolutionCatalysts\Payments\Stripe\Service\ModuleConfigurationService
     */
    private ModuleConfigurationService $moduleConfig;

    public function __construct()
    {
        $this->moduleConfig = $this->getServiceFromContainer(ModuleConfigurationService::class);
    }

    /**
     * Returns array with options for iStripeCronSecondChanceTimeDiff config option
     *
     * @return array
     */
    public function stripeSecondChanceDayDiffs(): array
    {
        $aReturn = [];
        for ($i = 1; $i <= 14; $i++) {
            $aReturn[] = $i;
        }
        return $aReturn;
    }

    /**
     * @return bool
     */
    public function stripeIsTestMode(): bool
    {
        try {
            return $this->moduleConfig->isTestMode();
        } catch (\Exception $e) {
            //@TODO log error here
            return false;
        }
    }

    /**
     * Check if test- or api-key is configured
     *
     * @return bool
     */
    public function stripeHasApiKeys(): bool
    {
        try {
            return !empty($this->moduleConfig->getToken());
        } catch (\Exception $e) {
            //@TODO log error here
            return false;
        }
    }

    /**
     * @TODO Find a more descriptive name for this method
     *
     * @return bool
     */
    public function stripeIsStripe(): bool
    {
        return $this->getEditObjectId() == 'osc_stripe_wallet';
    }

    /**
     * Generate Stripe Connect URL for onboarding
     *
     * @param string $sVarName
     * @return string
     */
    public function stripeGetConnectUrl(string $sVarName): string
    {
        $sMode = $sVarName == 'sStripeTestToken' ? 'test' : 'live';
        $redirectUrl = Registry::getConfig()->getShopUrl(0, true) . 'admin/index.php?cl=StripeConnect&fnc=stripeFinishOnBoarding';
        $redirectUrl .= '&stoken=' . Registry::getSession()->getSessionChallengeToken();
        $redirectUrl .= '&shop_param=' . $sMode;
        $redirectUrl .= '&shp=' . Registry::getConfig()->getShopId();

        if ($sMode == 'test') {
            return 'https://dev-osm.oxid-esales.com/stripe-connect?shop_redirect_url=' . rawurlencode($redirectUrl);
        }
        return 'https://osm.oxid-esales.com/stripe-connect?shop_redirect_url=' . rawurlencode($redirectUrl);
    }
}
