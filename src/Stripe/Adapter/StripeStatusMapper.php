<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Adapter;

use OxidEsales\PaymentBase\Adapter\Response\NormalizedPaymentStatus;

/**
 * Maps Stripe PaymentIntent statuses to normalized payment statuses.
 *
 * Provides bidirectional mapping between Stripe-specific statuses
 * and provider-agnostic normalized statuses.
 *
 * Sprint 114.10a (A2): Normalized status constants are now canonical in
 * {@see NormalizedPaymentStatus} (payment-base). The STATUS_* aliases below
 * are preserved for backwards compatibility so every existing reader keeps
 * working with zero edits.
 *
 * @since 1.0.0
 */
class StripeStatusMapper
{
    /**
     * Normalized payment statuses — delegates to the canonical payment-base constants.
     *
     * @see NormalizedPaymentStatus for the single source of truth.
     */
    public const STATUS_PENDING = NormalizedPaymentStatus::PENDING;
    public const STATUS_AUTHORIZED = NormalizedPaymentStatus::AUTHORIZED;
    public const STATUS_CAPTURED = NormalizedPaymentStatus::CAPTURED;
    public const STATUS_FAILED = NormalizedPaymentStatus::FAILED;
    public const STATUS_CANCELLED = NormalizedPaymentStatus::CANCELLED;
    public const STATUS_REFUNDED = NormalizedPaymentStatus::REFUNDED;
    public const STATUS_PARTIALLY_REFUNDED = NormalizedPaymentStatus::PARTIALLY_REFUNDED;

    /**
     * Stripe Checkout Session `payment_status` values (Sprint 114.12 C4).
     *
     * @see https://stripe.com/docs/api/checkout/sessions/object#checkout_session_object-payment_status
     */
    public const CHECKOUT_PAYMENT_STATUS_PAID = 'paid';
    public const CHECKOUT_PAYMENT_STATUS_UNPAID = 'unpaid';

    /**
     * Stripe Refund object `status` values (Sprint 114.12 C4).
     *
     * Distinct from PaymentIntent statuses — refund objects have their own lifecycle.
     *
     * @see https://stripe.com/docs/api/refunds/object#refund_object-status
     */
    public const STRIPE_REFUND_STATUS_PENDING = 'pending';

    /**
     * Stripe PaymentIntent statuses.
     *
     * @see https://stripe.com/docs/api/payment_intents/object#payment_intent_object-status
     */
    public const STRIPE_REQUIRES_PAYMENT_METHOD = 'requires_payment_method';
    public const STRIPE_REQUIRES_CONFIRMATION = 'requires_confirmation';
    public const STRIPE_REQUIRES_ACTION = 'requires_action';
    public const STRIPE_PROCESSING = 'processing';
    public const STRIPE_REQUIRES_CAPTURE = 'requires_capture';
    public const STRIPE_CANCELED = 'canceled';
    public const STRIPE_SUCCEEDED = 'succeeded';

    /**
     * Map from Stripe status to normalized status.
     *
     * @var array<string, string>
     */
    private const STRIPE_TO_NORMALIZED = [
        self::STRIPE_REQUIRES_PAYMENT_METHOD => self::STATUS_PENDING,
        self::STRIPE_REQUIRES_CONFIRMATION => self::STATUS_PENDING,
        self::STRIPE_REQUIRES_ACTION => self::STATUS_PENDING,
        self::STRIPE_PROCESSING => self::STATUS_PENDING,
        self::STRIPE_REQUIRES_CAPTURE => self::STATUS_AUTHORIZED,
        self::STRIPE_CANCELED => self::STATUS_CANCELLED,
        self::STRIPE_SUCCEEDED => self::STATUS_CAPTURED,
    ];

    /**
     * Convert Stripe PaymentIntent status to normalized status.
     *
     * @param string $stripeStatus Stripe PaymentIntent status
     * @return string Normalized status
     */
    public static function toNormalized(string $stripeStatus): string
    {
        return self::STRIPE_TO_NORMALIZED[$stripeStatus] ?? self::STATUS_PENDING;
    }

    /**
     * Check if Stripe status indicates payment requires action (e.g., 3DS).
     *
     * @param string $stripeStatus Stripe PaymentIntent status
     * @return bool True if action required
     */
    public static function requiresAction(string $stripeStatus): bool
    {
        return $stripeStatus === self::STRIPE_REQUIRES_ACTION;
    }

    /**
     * Check if Stripe status indicates payment is complete (captured).
     *
     * @param string $stripeStatus Stripe PaymentIntent status
     * @return bool True if captured
     */
    public static function isCaptured(string $stripeStatus): bool
    {
        return $stripeStatus === self::STRIPE_SUCCEEDED;
    }

    /**
     * Check if Stripe status indicates payment is cancelled.
     *
     * @param string $stripeStatus Stripe PaymentIntent status
     * @return bool True if cancelled
     */
    public static function isCancelled(string $stripeStatus): bool
    {
        return $stripeStatus === self::STRIPE_CANCELED;
    }
}
