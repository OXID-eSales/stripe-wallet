<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Admin;

use OxidEsales\Payments\Stripe\Service\FieldValidationFailure;

/**
 * Feedback channel for admin Payment-tab validation failures.
 *
 * The panel's action handler (StripePaymentPanelProvider) is void and the
 * tab re-renders after the POST — failures must survive exactly one render
 * cycle. store() on the gate path, consume() (read-and-clear) on render.
 *
 * Sprint 120 Phase B (STRP-129).
 */
interface AdminValidationFeedbackInterface
{
    /**
     * @param FieldValidationFailure[] $failures
     */
    public function store(string $orderId, string $action, array $failures): void;

    /**
     * Reads and clears the stored failures for the order in one call so a
     * subsequent render never shows stale errors.
     *
     * @return list<array{field: string, code: string, char: ?string, action: string}>
     */
    public function consume(string $orderId): array;
}
