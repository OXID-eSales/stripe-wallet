<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Webhook;

use OxidEsales\PaymentBase\Webhook\WebhookEvent;

/**
 * What a Stripe event says about where it comes from, outside its data object: the connected account it belongs to
 * (`account`, Connect events only) and whether it is a live-mode event. payment-base's WebhookEvent has no field for
 * it, so the processor carries it in the event's data under a key of its own and the origin policy reads it back.
 */
final readonly class StripeEventEnvelope
{
    private const KEY = 'stripe_envelope';

    private function __construct(public ?string $account, public bool $livemode)
    {
    }

    /**
     * @param array<string, mixed> $data the event's data
     *
     * @return array<string, mixed> the same data, with the envelope
     */
    public static function into(array $data, ?string $account, bool $livemode): array
    {
        $data[self::KEY] = ['account' => $account, 'livemode' => $livemode];

        return $data;
    }

    /**
     * Null for an event that did not come through the processor's parser (no envelope to judge).
     */
    public static function from(WebhookEvent $event): ?self
    {
        $envelope = $event->data[self::KEY] ?? null;
        if (!is_array($envelope)) {
            return null;
        }
        $account = $envelope['account'] ?? null;

        return new self(is_string($account) && $account !== '' ? $account : null, ($envelope['livemode'] ?? false) === true);
    }
}
