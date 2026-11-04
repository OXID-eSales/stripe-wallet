<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\GraphQL\Admin\Service;

use OxidEsales\EshopCommunity\Internal\Framework\Module\Facade\ModuleSettingServiceInterface;
use OxidEsales\Payments\Stripe\Core\WebhookEndpointKeys;
use OxidEsales\Payments\Stripe\Module;

/**
 * Per-mode endpoint id and signing secret in the module's oxconfig variables (`sStripeWebhookEndpointId{Test,Live}`,
 * `sStripeWebhookEndpointSecret{Test,Live}`), with the legacy mode-agnostic module setting as the fallback, under
 * the rule of ModuleConfigurationService::getWebhookSecret().
 */
final class OxidStripeWebhookState implements StripeWebhookStateInterface
{
    public function __construct(
        private readonly ModuleConfVarsInterface $vars,
        private readonly ModuleSettingServiceInterface $settings,
    ) {
    }

    public function endpointId(string $mode): string
    {
        return $this->vars->read(WebhookEndpointKeys::endpointId($mode));
    }

    public function hasSecret(string $mode): bool
    {
        if ($this->vars->read(WebhookEndpointKeys::secret($mode)) !== '') {
            return true;
        }
        $legacy = $this->settings->getString(WebhookEndpointKeys::LEGACY_SECRET, Module::MODULE_ID)->toString();

        return $legacy !== '' && $this->vars->read(WebhookEndpointKeys::secret(WebhookEndpointKeys::otherMode($mode))) === '';
    }
}
