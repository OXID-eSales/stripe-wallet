<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

/**
 * Outcome of a webhook endpoint registration call.
 *
 * `secret` is non-null only when the endpoint was just created. On update the
 * signing secret is preserved by Stripe but not re-emitted by the API, so the
 * caller must keep the previously stored value. See {@see WebhookEndpointRegistrar::register()}.
 */
final class WebhookEndpointRegistrationResult
{
    public function __construct(
        public readonly string $endpointId,
        public readonly ?string $secret
    ) {
    }
}
