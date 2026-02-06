<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Adapter;

use Stripe\Checkout\Session;

/**
 * Stripe Checkout Session operations.
 *
 * Sprint 46: ISP split from StripeAdapterInterface.
 *
 * @since 2.0.0
 */
interface StripeCheckoutAdapterInterface
{
    /**
     * @param array<string> $expand
     */
    public function retrieveCheckoutSession(string $sessionId, array $expand = []): Session;

    /**
     * @param array<string, mixed> $params
     */
    public function createCheckoutSession(array $params): Session;
}
