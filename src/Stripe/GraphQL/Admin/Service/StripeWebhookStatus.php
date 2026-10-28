<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\GraphQL\Admin\Service;

/**
 * Where the shop's webhook stands in the mode in use. `configured` means deliveries can be verified (a signing
 * secret is stored); the secret itself is not part of it.
 */
final readonly class StripeWebhookStatus
{
    public function __construct(
        public string $mode,
        public bool $configured,
        public string $endpointId,
        public string $url,
    ) {
    }
}
