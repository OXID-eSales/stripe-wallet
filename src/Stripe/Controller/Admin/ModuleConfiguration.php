<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Controller\Admin;

use OxidEsales\Eshop\Core\Registry;
use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\Payments\Stripe\Module;
use OxidEsales\Payments\Stripe\Service\ConfigurationValidatorInterface;
use OxidEsales\Payments\Stripe\Service\ModuleConfigurationServiceInterface;

/**
 * Extended admin ModuleConfiguration controller for Stripe module settings.
 *
 * Note: OXID class extensions cannot use standard constructor DI because
 * the parent class (ModuleConfiguration_parent) is a virtual class created
 * at runtime by OXID's class chain system. We use ContainerFactory for
 * lazy service retrieval instead.
 */
class ModuleConfiguration extends ModuleConfiguration_parent
{
    private ?ModuleConfigurationServiceInterface $moduleConfig = null;

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
        return $this->getModuleConfig()->isTestMode();
    }

    /**
     * Check if test- or api-key is configured
     */
    public function stripeHasApiKeys(): bool
    {
        return !empty($this->getModuleConfig()->getToken());
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
        /** @var ConfigurationValidatorInterface $validator */
        $validator = ContainerFactory::getInstance()
            ->getContainer()
            ->get(ConfigurationValidatorInterface::class);
        return $validator->getKeyValidationError();
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
