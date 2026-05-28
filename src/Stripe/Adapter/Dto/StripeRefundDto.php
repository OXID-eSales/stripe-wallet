<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Adapter\Dto;

/**
 * Neutral value object representing a Stripe Refund at the adapter boundary.
 *
 * All amount fields carry Stripe minor units (int) — callers convert via AmountConverter.
 * This keeps the DTO currency-agnostic (JPY, EUR, GBP all store integers here).
 *
 * Sprint 114.10b: seals the \Stripe\Refund type inside src/Stripe/Adapter/.
 *
 * @since 2.0.0
 */
final readonly class StripeRefundDto
{
    /**
     * @param string      $id        Refund ID (rf_...)
     * @param int         $amount    Refunded amount in Stripe minor units
     * @param string      $currency  ISO-4217 currency code, lowercase
     * @param string      $status    Refund status (succeeded, pending, failed, …)
     * @param string|null $reason    Refund reason or null if not set
     * @param int         $createdAt Unix timestamp of refund creation
     */
    public function __construct(
        public string $id,
        public int $amount,
        public string $currency,
        public string $status,
        public ?string $reason,
        public int $createdAt,
    ) {
    }
}
