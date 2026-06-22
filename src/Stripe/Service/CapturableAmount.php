<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

use OxidEsales\PaymentBase\Math\Money\Money;

/**
 * Pure capture-amount arithmetic extracted from CaptureService.
 *
 * Owns the "how much is still capturable" math and the over-capture guard so
 * the boundary conditions (sub-cent tolerance, already-captured deduction) can
 * be unit-tested without booting the service's adapter/repository collaborators.
 * See report 20260622/reports/02-floating-point-math-code-review.md §5.4.
 *
 * Static API: pure function, no state, no swappable dependency (YAGNI).
 *
 * @since 2.0.0
 */
final class CapturableAmount
{
    /**
     * Amount still capturable = authorized − already captured.
     *
     * May be negative on reconciliation skew; callers clamp with max(0.0, …)
     * for display. The raw value is returned here so the over-capture guard
     * can compare against it exactly.
     */
    public static function remaining(float $authorized, ?float $captured): float
    {
        return $authorized - ($captured ?? 0.0);
    }

    /**
     * True when a requested partial capture exceeds the remaining capturable
     * amount beyond the half-cent tolerance (a real over-capture, not drift).
     */
    public static function isExceededBy(float $requested, float $authorized, ?float $captured): bool
    {
        return Money::greaterThan($requested, self::remaining($authorized, $captured));
    }
}
