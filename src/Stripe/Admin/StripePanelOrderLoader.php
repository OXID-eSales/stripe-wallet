<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Admin;

use OxidEsales\Eshop\Application\Model\Order;

/**
 * Sprint I — thin OXID-bounded seam used by {@see StripePaymentPanelProvider}
 * to load an {@see Order} by id. Kept separate so the provider's own logic
 * is unit-testable without the shop bootstrap (tests inject a fake loader).
 */
class StripePanelOrderLoader
{
    public function loadById(string $orderId): ?Order
    {
        if ($orderId === '') {
            return null;
        }
        /** @var Order $order */
        $order = oxNew(Order::class);
        return $order->load($orderId) ? $order : null;
    }
}
