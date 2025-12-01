<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Stripe\Controller\Admin;

use OxidEsales\Eshop\Application\Controller\Admin\AdminController;
use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\EshopCommunity\Internal\Framework\Module\Configuration\Bridge\ModuleSettingBridge;
use OxidEsales\EshopCommunity\Internal\Framework\Module\Configuration\Bridge\ModuleSettingBridgeInterface;
use OxidEsales\Eshop\Core\Registry;
use OxidSolutionCatalysts\Payments\Stripe\Module;

class StripeConnect extends AdminController
{
    /** @var string */
    protected $_sThisTemplate = '@osc_stripe_wallet/admin/stripe_connect.html.twig';

    /** @var ModuleSettingBridge */
    private ModuleSettingBridge $moduleSettingService;

    public function __construct()
    {
        parent::__construct();

        $this->moduleSettingService = ContainerFactory::getInstance()->getContainer()->get(ModuleSettingBridgeInterface::class);
    }

    /**
     * Landing point when returning from Stripe OnBoarding process
     *
     * @return false|void
     */
    public function stripeFinishOnBoarding()
    {
        if (!Registry::getSession()->checkSessionChallenge()) {
            return false;
        }
        $sAccessToken = Registry::getRequest()->getRequestEscapedParameter('access_token');
        $sPublishableKey = Registry::getRequest()->getRequestEscapedParameter('publishable_key');
        $sMode = Registry::getRequest()->getRequestEscapedParameter('shop_param');

        $blSuccess = true;
        if (empty($sAccessToken) || empty($sMode) || ($sMode != 'test' && $sMode != 'live')) {
            $blSuccess = false;
        } else {
            if ($sMode == 'live') {
                $this->moduleSettingService->save('sStripeLiveToken', $sAccessToken, Module::MODULE_ID);
                $this->moduleSettingService->save('sStripeLivePk', $sPublishableKey, Module::MODULE_ID);
            } else {
                $this->moduleSettingService->save('sStripeTestToken', $sAccessToken, Module::MODULE_ID);
                $this->moduleSettingService->save('sStripeTestPk', $sPublishableKey, Module::MODULE_ID);
            }
        }

        $aViewData = $this->getViewData();
        $aViewData['blIsSuccess'] = $blSuccess;
        $aViewData['backToAdminUrl'] = $this->getViewConfig()->getSslSelfLink();
        $this->setViewData($aViewData);
    }
}
