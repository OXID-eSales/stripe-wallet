<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

/**
 * Whether a Stripe webhook endpoint belongs to this shop, judged by its URL.
 *
 * Since STRP-180 the webhook URL names the shop (`&shp=<id>`). An endpoint registered before that has
 * no `shp`; Stripe's call then reached the default shop, so such an endpoint belongs to shop 1 and to
 * no other shop. A subshop that holds a copy of that endpoint's id must not rewrite it.
 *
 * @since sprint 144 (AW)
 */
class WebhookUrlOwnership
{
    private const DEFAULT_SHOP_PARAMETER = '&shp=1';

    public function owns(string $endpointUrl, string $shopWebhookUrl): bool
    {
        return $endpointUrl === $shopWebhookUrl
            || $endpointUrl . self::DEFAULT_SHOP_PARAMETER === $shopWebhookUrl;
    }
}
