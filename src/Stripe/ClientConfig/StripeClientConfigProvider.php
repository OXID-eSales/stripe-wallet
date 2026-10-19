<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\ClientConfig;

use OxidEsales\PaymentBase\ClientConfig\Contract\ClientConfigProviderInterface;
use OxidEsales\Payments\Stripe\Service\PublishableKeyProvider;

/**
 * Stripe's contribution to payment-base's sealed `paymentClientConfig` query.
 *
 * An allow-list of exactly one value: the publishable key of the current
 * test/live mode. Without a key the result is empty, so the storefront falls
 * back to the hosted page (PublishableKeyProvider already logs the gap).
 *
 * @since 3.5.0
 */
final class StripeClientConfigProvider implements ClientConfigProviderInterface
{
    private const PROVIDER_ID = 'stripe';

    /** Stripe publishable keys always start with this; a setting filled with a secret key must not pass. */
    private const PUBLISHABLE_KEY_PREFIX = 'pk_';

    public function __construct(private readonly PublishableKeyProvider $publishableKeys)
    {
    }

    public function providerId(): string
    {
        return self::PROVIDER_ID;
    }

    public function publicValues(): array
    {
        $key = $this->publishableKeys->resolve();

        if ($key === null || !str_starts_with($key, self::PUBLISHABLE_KEY_PREFIX)) {
            return [];
        }

        return ['publishableKey' => $key];
    }
}
