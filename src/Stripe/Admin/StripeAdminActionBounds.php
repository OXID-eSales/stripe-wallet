<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Admin;

use OxidEsales\Eshop\Application\Model\Order;
use OxidEsales\PaymentBase\Admin\Contract\AdminActionBounds;
use OxidEsales\Payments\Stripe\Controller\Admin\OrderRefundViewDataProvider;
use Throwable;

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

    /**
     * The view-data provider swallows API failures into a 0.0, which here would read as "nothing capturable".
     * Whether the intent itself could be read tells a real zero from an unreadable one (an authorisation has no
     * charge yet, so a refundable 0 is not a failure).
     */
    public function readBounds(Order $order): AdminActionBounds
    {
        try {
            if ($this->viewDataProvider->getPaymentIntent($order) === null) {
                return AdminActionBounds::unavailable();
            }

            return new AdminActionBounds($this->captureBound($order), $this->refundBound($order));
        } catch (Throwable) {
            return AdminActionBounds::unavailable();
        }
    }
}
