<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\ClientConfig;

use OxidEsales\PaymentBase\ClientConfig\Contract\ClientConfigSecretPatternProviderInterface;

/**
 * VR-24 H6: the shapes of Stripe secrets (secret key, restricted key, webhook signing secret).
 * payment-base's ClientConfigGuard refuses a client-config value that matches one.
 */
final class StripeClientConfigSecretPatternProvider implements ClientConfigSecretPatternProviderInterface
{
    public function secretValuePatterns(): array
    {
        return ['/^sk_/', '/^rk_/', '/^whsec_/'];
    }
}
