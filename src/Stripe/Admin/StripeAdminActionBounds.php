<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Admin;

use OxidEsales\Eshop\Application\Model\Order;
use OxidEsales\Payments\Stripe\Controller\Admin\OrderRefundViewDataProvider;

/**
 * Delegates to the existing OrderRefundViewDataProvider — the exact same
 * PI/charge-derived sources the panel's `capturableRaw` and
 * `remainingRefundableRaw` view data (and the form `max` attributes) use.
 *
 * Sprint 121 Phase B (STRP-129).
 */
class StripeAdminActionBounds implements AdminActionBoundsInterface
{
    public function __construct(private readonly OrderRefundViewDataProvider $viewDataProvider)
    {
    }

    public function captureBound(Order $order): float
    {
        return $this->viewDataProvider->getCaptureableRaw($order);
    }

    public function refundBound(Order $order): float
    {
        return $this->viewDataProvider->getRemainingRefundableRaw($order);
    }
}
