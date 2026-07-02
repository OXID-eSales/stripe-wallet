<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Adapter\Dto;

/**
 * Neutral value object representing a Stripe Customer at the adapter boundary.
 *
 * Sprint 114.10b: seals the \Stripe\Customer type inside src/Stripe/Adapter/.
 *
 * @since 2.0.0
 */
readonly class StripeCustomerDto
{
    /**
     * @param string              $id       Customer ID (cus_...)
     * @param string|null         $email    Customer email address, or null if not set
     * @param array<string,mixed> $metadata Customer metadata key-value pairs
     */
    public function __construct(
        public string $id,
        public ?string $email,
        public array $metadata,
    ) {
    }
}
