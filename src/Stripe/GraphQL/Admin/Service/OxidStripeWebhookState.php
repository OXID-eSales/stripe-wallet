<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\GraphQL\Admin\Service;

use OxidEsales\EshopCommunity\Internal\Framework\Module\Facade\ModuleSettingServiceInterface;
use OxidEsales\Payments\Stripe\Core\StripeDefinitions;
use OxidEsales\Payments\Stripe\Module;

/**
 * Per-mode endpoint id and signing secret in the module's oxconfig variables (`sStripeWebhookEndpointId{Test,Live}`,
 * `sStripeWebhookEndpointSecret{Test,Live}`), with the legacy mode-agnostic module setting as the fallback, under
 * the rule of ModuleConfigurationService::getWebhookSecret().
 */
final class OxidStripeWebhookState implements StripeWebhookStateInterface
{
    private const LEGACY_SECRET_SETTING = 'sStripeWebhookEndpointSecret';

    public function __construct(
        private readonly ModuleConfVarsInterface $vars,
        private readonly ModuleSettingServiceInterface $settings,
    ) {
    }

    public function endpointId(string $mode): string
    {
        return $this->vars->read($this->endpointIdName($mode));
    }

    public function hasSecret(string $mode): bool
    {
        if ($this->vars->read($this->secretName($mode)) !== '') {
            return true;
        }
        $legacy = $this->settings->getString(self::LEGACY_SECRET_SETTING, Module::MODULE_ID)->toString();

        return $legacy !== '' && $this->vars->read($this->secretName($this->otherMode($mode))) === '';
    }

    private function endpointIdName(string $mode): string
    {
        return $mode === StripeDefinitions::MODE_LIVE ? 'sStripeWebhookEndpointIdLive' : 'sStripeWebhookEndpointIdTest';
    }

    private function secretName(string $mode): string
    {
        return $mode === StripeDefinitions::MODE_LIVE
            ? 'sStripeWebhookEndpointSecretLive'
            : 'sStripeWebhookEndpointSecretTest';
    }

    private function otherMode(string $mode): string
    {
        return $mode === StripeDefinitions::MODE_LIVE ? StripeDefinitions::MODE_TEST : StripeDefinitions::MODE_LIVE;
    }
}
