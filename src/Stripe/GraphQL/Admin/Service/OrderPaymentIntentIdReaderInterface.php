<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\GraphQL\Admin\Service;

/**
 * The PaymentIntent id the shop keeps on an order (`oxtransid`).
 */
interface OrderPaymentIntentIdReaderInterface
{
    /**
     * @return string the id, or '' when the order has none
     */
    public function intentIdOf(string $orderId): string;
}
