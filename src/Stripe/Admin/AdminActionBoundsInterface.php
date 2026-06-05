<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Admin;

use OxidEsales\Eshop\Application\Model\Order;

/**
 * Narrow seam exposing the maximum amount an admin action may move.
 *
 * Both bounds are PSP-derived (PaymentIntent / charge data) — the same
 * sources the panel's displayed amounts and the form `max` attributes use.
 * Never derived from webhook-populated order columns (OXCAPTUREDAMOUNT).
 *
 * Sprint 121 Phase B (STRP-129). ISP: the panel provider must not depend
 * on the wide OrderRefundViewDataProvider.
 */
interface AdminActionBoundsInterface
{
    /** Maximum capturable amount in major units (0.0 when nothing is capturable). */
    public function captureBound(Order $order): float;

    /** Maximum refundable amount in major units (0.0 when nothing is refundable). */
    public function refundBound(Order $order): float;
}
