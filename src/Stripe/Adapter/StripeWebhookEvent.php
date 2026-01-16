<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Adapter;

use OxidEsales\PaymentComponent\Adapter\WebhookEvent;
use Stripe\Event;

/**
 * Stripe implementation of WebhookEvent interface.
 *
 * Wraps Stripe\Event and provides provider-agnostic access to webhook data.
 *
 * @since 1.0.0
 */
final class StripeWebhookEvent implements WebhookEvent
{
    private readonly bool $verified;

    /**
     * @param Event $stripeEvent Stripe SDK Event object
     * @param string $rawPayload Original webhook payload
     * @param bool $verified Whether signature was verified
     */
    public function __construct(
        private readonly Event $stripeEvent,
        private readonly string $rawPayload,
        bool $verified = true
    ) {
        $this->verified = $verified;
    }

    public function getEventId(): string
    {
        return $this->stripeEvent->id;
    }

    public function getEventType(): string
    {
        // Map Stripe event types to normalized types
        return match (true) {
            str_contains($this->stripeEvent->type, 'payment_intent.succeeded') => 'payment.captured',
            str_contains($this->stripeEvent->type, 'payment_intent.payment_failed') => 'payment.failed',
            str_contains($this->stripeEvent->type, 'payment_intent.canceled') => 'payment.cancelled',
            str_contains($this->stripeEvent->type, 'charge.succeeded') => 'payment.captured',
            str_contains($this->stripeEvent->type, 'charge.failed') => 'payment.failed',
            str_contains($this->stripeEvent->type, 'charge.refunded') => 'payment.refunded',
            str_contains($this->stripeEvent->type, 'payment_intent.amount_capturable_updated') => 'payment.authorized',
            default => $this->stripeEvent->type,
        };
    }

    public function getProviderEventType(): string
    {
        return $this->stripeEvent->type;
    }

    public function getPaymentId(): ?string
    {
        $data = $this->stripeEvent->data->object ?? null;

        if ($data === null) {
            return null;
        }

        // Try to get payment intent ID from various Stripe object types
        if (isset($data->id)) {
            // For PaymentIntent, Charge, etc.
            return $data->id;
        }

        if (isset($data->payment_intent)) {
            // For Charge objects that reference a PaymentIntent
            return $data->payment_intent;
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        $object = $this->stripeEvent->data->object ?? null;

        if ($object === null) {
            return [];
        }

        // Convert Stripe object to array
        /** @var array<string, mixed> $data */
        $data = $object->toArray();
        return $data;
    }

    public function getCreatedAt(): \DateTimeInterface
    {
        return new \DateTimeImmutable('@' . $this->stripeEvent->created);
    }

    public function isVerified(): bool
    {
        return $this->verified;
    }

    public function getRawPayload(): string
    {
        return $this->rawPayload;
    }

    /**
     * Get the underlying Stripe Event object.
     *
     * This allows Stripe-specific code to access provider-specific features.
     *
     * @return Event Stripe SDK Event object
     */
    public function getStripeEvent(): Event
    {
        return $this->stripeEvent;
    }
}
