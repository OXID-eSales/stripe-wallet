<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Adapter;

use Stripe\StripeClient;

/**
 * Builds a Stripe SDK client for a specific API key.
 *
 * Narrows the client-factory surface to the one operation needed by
 * StripeWebhookEndpointApi: constructing a versioned client from an
 * arbitrary secret key (platform key, not the shop's default key).
 *
 * WHY a separate interface: StripeClientFactory is final (SDK adapter
 * layer), so unit tests cannot mock it. This narrow interface is the
 * testability seam required by DIP (R-4.1). StripeClientFactory
 * implements it; tests supply a mock.
 *
 * @since 1.0.0
 */
interface StripeClientProviderInterface
{
    /**
     * Create a versioned Stripe SDK client for the given secret key.
     *
     * The implementation MUST pin the same stripe_version used by the
     * rest of the module (see StripeClientFactory::create()).
     */
    public function forKey(string $apiKey): StripeClient;
}
