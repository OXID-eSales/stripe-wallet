<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Config;

/**
 * Status mapping configuration for Stripe payment states to OXID order statuses.
 *
 * Maps Stripe payment lifecycle states to OXID's OXTRANSSTATUS values.
 * These are static mappings that should only change if Stripe SDK changes.
 *
 * OXID OXTRANSSTATUS values:
 * - 'NOT_FINISHED' - Order not finished
 * - 'OK' - Payment successful
 * - 'ERROR' - Payment error
 * - 'PENDING' - Payment pending
 * - 'PROBLEMS' - Payment has problems
 *
 * Sprint 29: Extracted from admin-configurable settings to code constants.
 *
 * @since 2.0.0
 */
final class StatusMappingConfig
{
    /**
     * OXID status when Stripe payment is pending authorization.
     */
    public const STRIPE_PENDING = 'PENDING';

    /**
     * OXID status when Stripe payment is processing/authorized.
     */
    public const STRIPE_PROCESSING = 'OK';

    /**
     * OXID status when Stripe payment is cancelled.
     */
    public const STRIPE_CANCELLED = 'ERROR';

    /**
     * Get all status mappings as array.
     *
     * @return array<string, string> Map of stripe state => OXID status
     */
    public static function getAll(): array
    {
        return [
            'pending' => self::STRIPE_PENDING,
            'processing' => self::STRIPE_PROCESSING,
            'cancelled' => self::STRIPE_CANCELLED,
        ];
    }

    /**
     * Get OXID status for a Stripe state.
     *
     * @param string $stripeState Stripe payment state (pending, processing, cancelled)
     * @return string|null OXID status or null if not mapped
     */
    public static function getOxidStatus(string $stripeState): ?string
    {
        return self::getAll()[$stripeState] ?? null;
    }
}
