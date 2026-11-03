<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

use OxidEsales\Payments\Stripe\Adapter\StripeWebhookEndpointApiInterface;

/**
 * VR-23 T3: the shop's Stripe account, stored per mode when the webhook is registered. A shop registered before the
 * account was stored asks Stripe once, with its own key, and keeps the answer.
 */
final class ShopStripeAccount implements ShopStripeAccountInterface
{
    public function __construct(
        private readonly ModuleConfigurationServiceInterface $config,
        private readonly StripeWebhookEndpointApiInterface $api,
        private readonly ShopStripeAccountStoreInterface $store
    ) {
    }

    public function id(): string
    {
        $stored = $this->store->get($this->config->getMode());
        if ($stored !== '') {
            return $stored;
        }

        return $this->askStripeAndKeep();
    }

    public function remember(): void
    {
        $this->askStripeAndKeep();
    }

    private function askStripeAndKeep(): string
    {
        $accountId = $this->api->accountId($this->config->getToken());
        $this->store->save($this->config->getMode(), $accountId);

        return $accountId;
    }
}
