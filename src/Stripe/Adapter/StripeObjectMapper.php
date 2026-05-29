<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Adapter;

use OxidEsales\Payments\Stripe\Adapter\Dto\StripeChargeDto;
use OxidEsales\Payments\Stripe\Adapter\Dto\StripeCheckoutSessionDto;
use OxidEsales\Payments\Stripe\Adapter\Dto\StripeCustomerDto;
use OxidEsales\Payments\Stripe\Adapter\Dto\StripePaymentIntentDto;
use OxidEsales\Payments\Stripe\Adapter\Dto\StripeRefundDto;
use Stripe\Charge;
use Stripe\Checkout\Session;
use Stripe\Customer;
use Stripe\PaymentIntent;
use Stripe\Refund;

/**
 * Maps raw Stripe SDK objects to provider-neutral DTOs.
 *
 * This is the ONLY place in the codebase that reads \Stripe\* object fields
 * directly. All other code receives DTOs from here.
 *
 * Static design rationale (R-9.1): the mapper has no state; a static class
 * is simpler than a singleton service and removes a DI binding that adds no
 * value — callers inside Adapter/ already depend on the concrete Stripe SDK.
 *
 * Sprint 114.10b: single entry point for the A1 boundary fix.
 *
 * @since 2.0.0
 */
class StripeObjectMapper
{
    /**
     * Map a Stripe Checkout Session to a neutral DTO.
     *
     * Handles the `payment_intent` field in three forms:
     *   - string  → PI id, status unknown
     *   - object  → PI id + status from expanded object
     *   - null    → empty id, status unknown
     */
    public static function fromCheckoutSession(Session $session): StripeCheckoutSessionDto
    {
        $paymentIntentField = $session->payment_intent ?? null;

        [$paymentIntentId, $paymentIntentStatus] = self::extractPaymentIntentIdAndStatus(
            $paymentIntentField
        );

        /** @var array<string,mixed> $metadata */
        $metadata = $session->metadata !== null ? $session->metadata->toArray() : [];

        $url = $session->url ?? null;

        return new StripeCheckoutSessionDto(
            id: (string) ($session->id ?? ''),
            paymentStatus: (string) ($session->payment_status ?? 'unknown'),
            paymentIntentId: $paymentIntentId,
            paymentIntentStatus: $paymentIntentStatus,
            metadata: $metadata,
            amountTotal: (int) ($session->amount_total ?? 0),
            currency: (string) ($session->currency ?? 'eur'),
            url: $url !== null ? (string) $url : null,
        );
    }

    /**
     * Map a Stripe PaymentIntent to a neutral DTO.
     *
     * When `latest_charge` is an expanded Charge object it is mapped to a
     * StripeChargeDto and stored in `$dto->charge`. When it is a string ID
     * (not expanded) it is stored in `$dto->latestChargeId` and `$dto->charge`
     * is null.
     */
    public static function fromPaymentIntent(PaymentIntent $pi): StripePaymentIntentDto
    {
        $latestChargeField = $pi->latest_charge ?? null;
        $latestChargeId    = null;
        $chargeDto         = null;

        if ($latestChargeField instanceof Charge) {
            $latestChargeId = $latestChargeField->id;
            $chargeDto      = self::fromCharge($latestChargeField);
        } elseif (is_string($latestChargeField)) {
            $latestChargeId = $latestChargeField;
        }

        return new StripePaymentIntentDto(
            id: (string) ($pi->id ?? ''),
            status: (string) ($pi->status ?? ''),
            amount: (int) ($pi->amount ?? 0),
            currency: (string) ($pi->currency ?? ''),
            created: (int) ($pi->created ?? 0),
            latestChargeId: $latestChargeId,
            charge: $chargeDto,
        );
    }

    /**
     * Map a Stripe Charge to a neutral DTO.
     *
     * Refunds are mapped only when the `refunds->data` array is present
     * (i.e. the charge was retrieved with expansion). An absent refunds list
     * yields an empty array — not an error.
     */
    public static function fromCharge(Charge $charge): StripeChargeDto
    {
        return new StripeChargeDto(
            id: (string) ($charge->id ?? ''),
            amount: (int) ($charge->amount ?? 0),
            amountCaptured: (int) ($charge->amount_captured ?? 0),
            amountRefunded: (int) ($charge->amount_refunded ?? 0),
            currency: (string) ($charge->currency ?? ''),
            captured: (bool) ($charge->captured ?? false),
            created: (int) ($charge->created ?? 0),
            refunds: self::mapRefunds($charge),
        );
    }

    /**
     * Map a Stripe Refund to a neutral DTO.
     */
    public static function fromRefund(Refund $refund): StripeRefundDto
    {
        $reason = $refund->reason ?? null;

        return new StripeRefundDto(
            id: (string) ($refund->id ?? ''),
            amount: (int) ($refund->amount ?? 0),
            currency: (string) ($refund->currency ?? ''),
            status: (string) ($refund->status ?? 'unknown'),
            reason: $reason !== null ? (string) $reason : null,
            createdAt: (int) ($refund->created ?? 0),
        );
    }

    /**
     * Map a Stripe Customer to a neutral DTO.
     */
    public static function fromCustomer(Customer $customer): StripeCustomerDto
    {
        $email = $customer->email ?? null;

        /** @var array<string,mixed> $metadata */
        $metadata = $customer->metadata !== null ? $customer->metadata->toArray() : [];

        return new StripeCustomerDto(
            id: (string) ($customer->id ?? ''),
            email: $email !== null ? (string) $email : null,
            metadata: $metadata,
        );
    }

    /**
     * Extract PI id and status from the raw `payment_intent` field.
     *
     * The field can be a string (unexpanded), an object with id+status
     * (expanded PaymentIntent), or null.
     *
     * @param mixed $paymentIntentField
     * @return array{string, string} [paymentIntentId, paymentIntentStatus]
     */
    private static function extractPaymentIntentIdAndStatus(mixed $paymentIntentField): array
    {
        if (is_string($paymentIntentField)) {
            return [$paymentIntentField, 'unknown'];
        }

        if (is_object($paymentIntentField) && isset($paymentIntentField->id)) {
            $idRaw     = $paymentIntentField->id;
            $statusRaw = $paymentIntentField->status ?? null;
            $id        = is_scalar($idRaw) ? (string) $idRaw : '';
            $status    = is_scalar($statusRaw) ? (string) $statusRaw : 'unknown';
            return [$id, $status];
        }

        return ['', 'unknown'];
    }

    /**
     * Map the refunds list from a Charge object to StripeRefundDto[].
     *
     * @return array<StripeRefundDto>
     */
    private static function mapRefunds(Charge $charge): array
    {
        $refundsData = $charge->refunds->data ?? [];

        $dtos = [];
        foreach ($refundsData as $refund) {
            $dtos[] = self::fromRefund($refund);
        }

        return $dtos;
    }
}
