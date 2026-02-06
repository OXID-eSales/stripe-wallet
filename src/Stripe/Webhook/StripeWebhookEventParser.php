<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Webhook;

use OxidEsales\PaymentComponent\Webhook\WebhookEvent;

/**
 * Extracts typed data from Stripe webhook events.
 *
 * Sprint 46: Extracted from StripeWebhookProcessor to reduce ECC.
 *
 * @since 2.0.0
 */
final class StripeWebhookEventParser
{
    /**
     * Extract payment intent ID from event data.
     */
    public function extractPaymentIntentId(WebhookEvent $event): ?string
    {
        $object = $event->getObject();
        $id = $object['id'] ?? null;

        return is_string($id) ? $id : null;
    }

    /**
     * Extract payment intent ID from charge event data.
     */
    public function extractPaymentIntentIdFromCharge(WebhookEvent $event): ?string
    {
        $object = $event->getObject();
        $paymentIntent = $object['payment_intent'] ?? null;

        return is_string($paymentIntent) ? $paymentIntent : null;
    }

    /**
     * Extract failure reason from payment_intent.payment_failed event.
     */
    public function extractFailureReason(WebhookEvent $event): string
    {
        $object = $event->getObject();
        $lastError = $object['last_payment_error'] ?? null;

        if (is_array($lastError)) {
            $message = $lastError['message'] ?? null;
            if (is_string($message)) {
                return $message;
            }
        }

        return 'Unknown error';
    }

    /**
     * Extract cancellation reason from payment_intent.canceled event.
     */
    public function extractCancellationReason(WebhookEvent $event): string
    {
        $object = $event->getObject();
        $reason = $object['cancellation_reason'] ?? null;

        return is_string($reason) ? $reason : 'user_requested';
    }

    /**
     * Extract amount and convert from cents to currency units.
     */
    public function extractAmountInCurrencyUnits(WebhookEvent $event, string $field): float
    {
        $object = $event->getObject();
        $amount = $object[$field] ?? 0;

        return is_int($amount) ? $amount / 100 : 0.0;
    }

    /**
     * Extract contract_id from event metadata.
     */
    public function extractContractIdFromMetadata(WebhookEvent $event): ?string
    {
        $object = $event->getObject();
        $metadata = $object['metadata'] ?? null;

        if (is_array($metadata)) {
            $contractId = $metadata['contract_id'] ?? null;
            if (is_string($contractId) && $contractId !== '') {
                return $contractId;
            }
        }

        return null;
    }
}
