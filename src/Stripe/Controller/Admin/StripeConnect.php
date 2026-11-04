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
use OxidEsales\PaymentBase\GraphQL\Admin\Service\PaymentRightsProvider;
use OxidEsales\PaymentBase\Payment\AdminAccessDeniedException;
use OxidEsales\PaymentBase\Payment\AdminChannelInterface;
use OxidEsales\Payments\Stripe\Core\StripeDefinitions;
use OxidEsales\Payments\Stripe\Module;

/**
 * Admin controller for Stripe Connect OAuth onboarding.
 *
 * This controller handles only the post-OAuth landing page: it receives the
 * access_token + publishable_key returned by the Stripe Connect flow and
 * persists them into module settings.
 *
 * VR-24 H4 (S-5): the keys are accepted from a POST body only (a GET query lands in access logs and browser
 * history), only for an admin holding the payment CONFIG right, and only when their prefixes match the mode.
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
    private AdminChannelInterface $adminChannel;

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

        /** @var AdminChannelInterface $adminChannel */
        $adminChannel = $container->get('stripe.admin_channel');

        $this->initializeCollaborators($moduleSettings, $adminChannel);
    }

    /**
     * Init seam — test subclasses bypass parent::init() and call
     * this directly with mocked collaborators.
     */
    protected function initializeCollaborators(
        ModuleSettingBridgeInterface $moduleSettingService,
        AdminChannelInterface $adminChannel
    ): void {
        $this->moduleSettingService = $moduleSettingService;
        $this->adminChannel = $adminChannel;
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

        $blSuccess = $this->mayOnboard() && $this->persistPostedCredentials();

        $viewData = $this->getViewData();
        $viewData['blIsSuccess']    = $blSuccess;
        $viewData['backToAdminUrl'] = $this->getViewConfig()->getSslSelfLink();
        $this->setViewData($viewData);
    }

    private function mayOnboard(): bool
    {
        if ($this->requestMethod() !== 'POST') {
            return false;
        }

        try {
            $this->adminChannel->requireRight(PaymentRightsProvider::CONFIG);
        } catch (AdminAccessDeniedException) {
            return false;
        }

        return true;
    }

    private function persistPostedCredentials(): bool
    {
        $accessToken    = $this->postedParameter('access_token');
        $publishableKey = $this->postedParameter('publishable_key');
        $mode           = $this->postedParameter('shop_param');

        if (!$this->isValidOnboardingPayload($accessToken, $publishableKey, $mode)) {
            return false;
        }

        $this->persistCredentials($mode, $accessToken, $publishableKey);

        return true;
    }

    /**
     * Protected seam: the HTTP method of the request.
     */
    protected function requestMethod(): string
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? '';

        return is_string($method) ? strtoupper($method) : '';
    }

    /**
     * Protected seam: a field of the POST body. The merged request (GET + POST) is not read, so a key in the
     * query string is never taken.
     */
    protected function postedParameter(string $name): string
    {
        $value = $_POST[$name] ?? '';

        return is_string($value) ? trim($value) : '';
    }

    private function isValidOnboardingPayload(string $accessToken, string $publishableKey, string $mode): bool
    {
        if ($mode !== StripeDefinitions::MODE_TEST && $mode !== StripeDefinitions::MODE_LIVE) {
            return false;
        }

        return $this->isSecretKeyOfMode($accessToken, $mode)
            && str_starts_with($publishableKey, 'pk_' . $mode . '_');
    }

    /**
     * A secret (`sk_`) or restricted (`rk_`) key of the mode, never a webhook secret or the other mode's key.
     */
    private function isSecretKeyOfMode(string $accessToken, string $mode): bool
    {
        return str_starts_with($accessToken, 'sk_' . $mode . '_')
            || str_starts_with($accessToken, 'rk_' . $mode . '_');
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
