<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

/**
 * Single source of truth for the Stripe webhook events this module subscribes to.
 *
 * Extracted from the WebhookHandler/* classes — adding or removing an event
 * type means one edit here, not three (registration, handler dispatch, docs).
 */
final class WebhookEventCatalog
{
    /**
     * @var list<string>
     */
    private const EVENTS = [
        'payment_intent.succeeded',
        'payment_intent.payment_failed',
        'payment_intent.canceled',
        'charge.refunded',
        'checkout.session.expired',
    ];

    /**
     * @return list<string>
     */
    public function all(): array
    {
        return self::EVENTS;
    }
}
