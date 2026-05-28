<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Controller\Admin;

use OxidEsales\Eshop\Application\Controller\Admin\AdminController;
use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\EshopCommunity\Internal\Framework\Module\Configuration\Bridge\ModuleSettingBridgeInterface;
use OxidEsales\Eshop\Core\Registry;
use OxidEsales\Payments\Stripe\Core\StripeDefinitions;
use OxidEsales\Payments\Stripe\Module;

/**
 * Admin controller for Stripe Connect OAuth onboarding.
 *
 * This controller handles only the post-OAuth landing page: it receives the
 * access_token + publishable_key returned by the Stripe Connect flow and
 * persists them into module settings.
 *
 * Webhook registration (the "Create webhooks" button) lives in
 * ModuleConfiguration because its trigger is a button on the module_config
 * admin form next to the Webhook Endpoint field.
 */
class StripeConnect extends AdminController
{
    /** @var string */
    protected $_sThisTemplate = '@oe_payments_stripe_wallet/admin/stripe_connect.html.twig';

    private ModuleSettingBridgeInterface $moduleSettingService;

    /**
     * Resolve collaborators once from the DI container.
     *
     * WHY init() and not __construct(): OXID admin controllers extend a virtual
     * parent class built at runtime; constructor DI is not available. init()
     * is the earliest safe resolution point per R-4.2.
     * Test subclasses bypass init() entirely and call initializeCollaborators()
     * directly with mocked dependencies.
     */
    public function init(): void
    {
        parent::init();

        $container = ContainerFactory::getInstance()->getContainer();

        /** @var ModuleSettingBridgeInterface $moduleSettings */
        $moduleSettings = $container->get(ModuleSettingBridgeInterface::class);

        $this->initializeCollaborators($moduleSettings);
    }

    /**
     * Init seam — test subclasses bypass parent::init() and call
     * this directly with mocked collaborators.
     */
    protected function initializeCollaborators(
        ModuleSettingBridgeInterface $moduleSettingService
    ): void {
        $this->moduleSettingService = $moduleSettingService;
    }

    /**
     * Landing point when returning from Stripe OnBoarding process.
     *
     * @return false|void
     */
    public function stripeFinishOnBoarding()
    {
        if (!Registry::getSession()->checkSessionChallenge()) {
            return false;
        }

        $accessToken    = $this->readRequestString('access_token');
        $publishableKey = $this->readRequestString('publishable_key');
        $mode           = $this->readRequestString('shop_param');

        $blSuccess = false;

        if ($this->isValidOnboardingPayload($accessToken, $mode)) {
            $this->persistCredentials($mode, $accessToken, $publishableKey);
            $blSuccess = true;
        }

        $viewData = $this->getViewData();
        $viewData['blIsSuccess']    = $blSuccess;
        $viewData['backToAdminUrl'] = $this->getViewConfig()->getSslSelfLink();
        $this->setViewData($viewData);
    }

    private function readRequestString(string $parameter): string
    {
        $value = Registry::getRequest()->getRequestEscapedParameter($parameter);
        return is_string($value) ? $value : '';
    }

    private function isValidOnboardingPayload(string $accessToken, string $mode): bool
    {
        if ($accessToken === '') {
            return false;
        }
        return $mode === StripeDefinitions::MODE_TEST || $mode === StripeDefinitions::MODE_LIVE;
    }

    private function persistCredentials(string $mode, string $accessToken, string $publishableKey): void
    {
        $this->moduleSettingService->save($this->tokenKey($mode), $accessToken, Module::MODULE_ID);
        $this->moduleSettingService->save($this->publishableKeyKey($mode), $publishableKey, Module::MODULE_ID);
    }

    private function tokenKey(string $mode): string
    {
        return $mode === StripeDefinitions::MODE_LIVE ? 'sStripeLiveToken' : 'sStripeTestToken';
    }

    private function publishableKeyKey(string $mode): string
    {
        return $mode === StripeDefinitions::MODE_LIVE ? 'sStripeLivePk' : 'sStripeTestPk';
    }
}
