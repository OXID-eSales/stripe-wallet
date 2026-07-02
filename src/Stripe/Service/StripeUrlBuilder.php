<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

use OxidEsales\Eshop\Core\Registry;

/**
 * Builds outbound URLs for Stripe integration (webhook URL, shop SSL base URL).
 *
 * Sprint 114.11b (S2): extracted from ModuleConfigurationService to honor SRP.
 * Previously the service mixed URL construction with typed setting access;
 * this class owns only URL concerns.
 *
 * Callers receive this class through ModuleConfigurationService delegators, so
 * the public contract of ModuleConfigurationServiceInterface is unchanged.
 *
 * @since 2.0.0
 */
class StripeUrlBuilder
{
    /**
     * Get the webhook URL for Stripe configuration.
     *
     * Always emits an https:// URL. Stripe rejects http endpoints at
     * WebhookEndpoint::create time, and getCurrentShopUrl()/getShopUrl() will
     * return whatever scheme the current request used — unreliable for an
     * outbound URL Stripe will dial back into.
     */
    public function getWebhookUrl(): string
    {
        $shopUrl = $this->getSslShopBaseUrl();
        return rtrim($shopUrl, '/') . '/index.php?cl=StripeWebhookController';
    }

    /**
     * Get the SSL form of the shop URL. Used for outbound URLs that third
     * parties dial back into (Stripe webhooks, Connect callbacks).
     *
     * Overridable in test subclasses to avoid touching Registry.
     */
    protected function getSslShopBaseUrl(): string
    {
        // @phpstan-ignore-next-line OXID core: Registry::getConfig() is the sanctioned seam
        return Registry::getConfig()->getSslShopUrl();
    }
}
