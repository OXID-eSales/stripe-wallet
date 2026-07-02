<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

use OxidEsales\Payments\Stripe\Service\Exception\WebhookRegistrationException;

interface WebhookEndpointRegistrarInterface
{
    /**
     * Register (create or update) a webhook endpoint on the Stripe account
     * identified by $accessToken.
     *
     * When $existingEndpointId is null, a new endpoint is created and the
     * signing secret is returned. When it is given, the existing endpoint is
     * updated in place; Stripe does not re-emit the signing secret on update,
     * so the caller is expected to retain its previously stored value.
     *
     * Set $isConnect = true to register a platform-level Connect webhook
     * (requires a platform secret key, not a connected-account access_token).
     *
     * $description is forwarded to Stripe as the endpoint's `description` field —
     * the merchant sees it as the endpoint's label in the Stripe Dashboard. The
     * caller (admin AJAX action) is the source of truth for this string; the
     * registrar does NOT bake in a default, so its behaviour is fully determined
     * by call-site data.
     *
     * @throws WebhookRegistrationException on non-HTTPS URLs or Stripe API failure
     */
    public function register(
        string $accessToken,
        string $webhookUrl,
        ?string $existingEndpointId,
        bool $isConnect = false,
        string $description = ''
    ): WebhookEndpointRegistrationResult;

    /**
     * Deletes webhook endpoints visible to $accessToken.
     *
     * When $urlFilter is non-null, only endpoints whose URL exactly matches are
     * removed — used so a "clear" action in one shop doesn't wipe endpoints that
     * belong to other shops sharing the same Stripe key.
     *
     * @return int Number of endpoints actually deleted.
     * @throws WebhookRegistrationException on Stripe API failure (partial deletes
     *         may have occurred — caller should treat as fatal and re-list).
     */
    public function clearAll(string $accessToken, ?string $urlFilter = null): int;
}
