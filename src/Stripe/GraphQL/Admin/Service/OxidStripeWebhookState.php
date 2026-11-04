<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\GraphQL\Admin\Service;

use OxidEsales\Payments\Stripe\Core\WebhookEndpointKeys;

/**
 * Per-mode endpoint id and signing secret in the module's oxconfig variables (`sStripeWebhookEndpointId{Test,Live}`,
 * `sStripeWebhookEndpointSecret{Test,Live}`), the same values ModuleConfigurationService::getWebhookSecret() reads.
 */
final class OxidStripeWebhookState implements StripeWebhookStateInterface
{
    public function __construct(
        private readonly ModuleConfVarsInterface $vars,
    ) {
    }

    public function endpointId(string $mode): string
    {
        return $this->vars->read(WebhookEndpointKeys::endpointId($mode));
    }

    public function hasSecret(string $mode): bool
    {
        return $this->vars->read(WebhookEndpointKeys::secret($mode)) !== '';
    }
}
