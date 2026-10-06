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
class WebhookEventCatalog
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
        // GRAPH-QL / PS2: a headless client may never come back from Stripe;
        // the paid session commits the contract (payment-base
        // ContractCommitService) from this event.
        'checkout.session.completed',
        // GRAPH-QL / PS6 follow-up: with manual capture the session completes
        // "unpaid" and the intent is only authorized (requires_capture); this
        // event is what commits the headless contract then, requiresCapture.
        'payment_intent.amount_capturable_updated',
    ];

    /**
     * @return list<string>
     */
    public function all(): array
    {
        return self::EVENTS;
    }
}
