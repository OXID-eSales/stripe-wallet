<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Webhook;

use OxidEsales\PaymentBase\Webhook\WebhookEvent;

/**
 * Stripe-local handler interface for webhook event processing.
 *
 * Distinct from payment-base WebhookEventHandlerInterface because Stripe
 * handlers must also carry a contractId back to the processor for webhook
 * log linking. The payment-base interface returns a bare WebhookResult with
 * no contractId field; we cannot modify the shared package (R-9.1, scope).
 *
 * Tagged as 'stripe.webhook_handler' in services.yaml and collected by
 * StripeWebhookProcessor via !tagged_iterator.
 *
 * @since Sprint 114.4
 */
interface StripeWebhookEventHandlerInterface
{
    /**
     * Return true if this handler processes the given Stripe event type.
     *
     * Each handler supports exactly one event type (exact string match).
     */
    public function supports(string $eventType): bool;

    /**
     * Handle the webhook event and return the outcome including any resolved contractId.
     */
    public function handle(WebhookEvent $event): StripeWebhookOutcome;
}
