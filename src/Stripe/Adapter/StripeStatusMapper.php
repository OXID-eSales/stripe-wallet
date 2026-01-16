<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Adapter;

/**
 * Maps Stripe PaymentIntent statuses to normalized payment statuses.
 *
 * Provides bidirectional mapping between Stripe-specific statuses
 * and provider-agnostic normalized statuses.
 *
 * @since 1.0.0
 */
final class StripeStatusMapper
{
    /**
     * Normalized payment statuses used across all providers.
     */
    public const STATUS_PENDING = 'pending';
    public const STATUS_AUTHORIZED = 'authorized';
    public const STATUS_CAPTURED = 'captured';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_REFUNDED = 'refunded';
    public const STATUS_PARTIALLY_REFUNDED = 'partially_refunded';

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
     * Determine normalized status from Stripe PaymentIntent.
     *
     * This considers both the status field and additional flags like
     * amount_capturable and amount_received.
     *
     * @param string $status Stripe status
     * @param int $amountCapturable Amount available to capture (in cents)
     * @param int $amountReceived Amount captured (in cents)
     * @return string Normalized status
     */
    public static function fromPaymentIntent(
        string $status,
        int $amountCapturable = 0,
        int $amountReceived = 0
    ): string {
        // Check for refunded state
        if ($status === self::STRIPE_SUCCEEDED && $amountReceived === 0) {
            return self::STATUS_REFUNDED;
        }

        // Authorized but not captured
        if ($status === self::STRIPE_REQUIRES_CAPTURE || $amountCapturable > 0) {
            return self::STATUS_AUTHORIZED;
        }

        // Captured/succeeded
        if ($status === self::STRIPE_SUCCEEDED) {
            return self::STATUS_CAPTURED;
        }

        // Cancelled
        if ($status === self::STRIPE_CANCELED) {
            return self::STATUS_CANCELLED;
        }

        // Default mapping
        return self::toNormalized($status);
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
     * Check if Stripe status indicates payment is authorized but not captured.
     *
     * @param string $stripeStatus Stripe PaymentIntent status
     * @return bool True if authorized
     */
    public static function isAuthorized(string $stripeStatus): bool
    {
        return $stripeStatus === self::STRIPE_REQUIRES_CAPTURE;
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

    /**
     * Check if Stripe status indicates payment is in progress.
     *
     * @param string $stripeStatus Stripe PaymentIntent status
     * @return bool True if processing
     */
    public static function isProcessing(string $stripeStatus): bool
    {
        return in_array($stripeStatus, [
            self::STRIPE_REQUIRES_PAYMENT_METHOD,
            self::STRIPE_REQUIRES_CONFIRMATION,
            self::STRIPE_REQUIRES_ACTION,
            self::STRIPE_PROCESSING,
        ], true);
    }
}
