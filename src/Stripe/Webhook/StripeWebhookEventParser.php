<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Webhook;

use OxidEsales\PaymentBase\Webhook\WebhookEvent;
use OxidEsales\Payments\Stripe\Core\AmountConverter;

/**
 * Extracts typed data from Stripe webhook events.
 *
 * Sprint 46: Extracted from StripeWebhookProcessor to reduce ECC.
 *
 * @since 2.0.0
 */
class StripeWebhookEventParser
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
     * Extract amount and convert from Stripe minor units to major currency units.
     *
     * Sprint 114.7: uses AmountConverter so zero-decimal currencies (JPY, KRW, …)
     * are handled correctly. The currency field must be present in the event object
     * alongside the amount field; defaults to 2 decimals if absent (safe for EUR).
     *
     * Sprint 133 · Story 9 (F9): returns null when the amount is absent or not an
     * integer. It used to return 0.0, which is indistinguishable from a genuine
     * zero — so a renamed field or an unexpected payload silently recorded a 0.00
     * capture/refund and left the full amount looking capturable.
     */
    public function extractAmountInCurrencyUnits(WebhookEvent $event, string $field): ?float
    {
        $object = $event->getObject();
        $amount = $object[$field] ?? null;

        if (!is_int($amount)) {
            return null;
        }

        $currency = isset($object['currency']) && is_string($object['currency'])
            ? strtoupper($object['currency'])
            : '';

        return AmountConverter::toMajorUnits($amount, $currency);
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
