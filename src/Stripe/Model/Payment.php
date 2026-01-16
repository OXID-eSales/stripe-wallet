<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Model;

use OxidEsales\Eshop\Application\Model\Payment as CorePayment;
use OxidEsales\Payments\Stripe\Core\StripeDefinitions;

/**
 * Extended OXID Payment Model with Stripe-specific methods.
 *
 * Extends the core OXID Payment model to add Stripe-specific functionality
 * while maintaining SOLID principles (Single Responsibility).
 *
 * This extension allows checking if a payment method is Stripe-powered
 * directly on the Payment object:
 *
 * Example:
 * ```php
 * $payment = oxNew(\OxidEsales\Eshop\Application\Model\Payment::class);
 * $payment->load('oe_payments_stripe_wallet');
 *
 * if ($payment->isStripePowered()) {
 *     // This is a Stripe payment method
 * }
 * ```
 *
 * @since 1.0.0
 */
class Payment extends CorePayment
{
    /**
     * List of Stripe payment method IDs.
     *
     * @var array<string>
     */
    private const STRIPE_PAYMENT_METHODS = [
        StripeDefinitions::STRIPE_WALLET_PAYMENT_ID
    ];

    /**
     * Check if this payment method is Stripe-powered.
     *
     * Returns true if the payment method ID starts with 'stripe' or is in the
     * list of known Stripe payment methods.
     *
     * This method provides a convenient way to check if a payment requires
     * Stripe-specific processing without coupling to the Stripe module.
     *
     * @return bool True if this is a Stripe payment method, false otherwise
     *
     * @example
     * ```php
     * $payment = oxNew(Payment::class);
     * $payment->load('stripecreditcard');
     *
     * if ($payment->isStripePowered()) {
     *     echo "This payment uses Stripe";
     * } else {
     *     echo "This payment uses another provider";
     * }
     * ```
     */
    public function isStripePaymentMethod(): bool
    {
        $paymentId = $this->getId();

        if (empty($paymentId)) {
            return false;
        }

        // Check if payment ID is in the list of known Stripe methods
        if (in_array($paymentId, self::STRIPE_PAYMENT_METHODS, true)) {
            return true;
        }

        // Fallback: Check if payment ID starts with 'oe_payments_stripe_'
        // This catches any custom Stripe payment methods not in the list
        return str_starts_with($paymentId, 'oe_payments_stripe_');
    }

    /**
     * Check if this payment method is NOT Stripe-powered.
     *
     * Inverse of isStripePowered() for convenience and readability.
     *
     * @return bool True if this is NOT a Stripe payment method
     *
     * @example
     * ```php
     * if ($payment->isOtherSourced()) {
     *     // Use non-Stripe payment processing
     * }
     * ```
     */
    public function isOtherSourced(): bool
    {
        return !$this->isStripePaymentMethod();
    }

    /**
     * Get the payment provider name.
     *
     * Returns 'stripe' for Stripe-powered payments, 'other' for all others.
     * Useful for logging, analytics, and routing payment processing.
     *
     * @return string 'stripe' or 'other'
     *
     * @example
     * ```php
     * $provider = $payment->getPaymentProvider();
     * $logger->info("Processing payment via provider: $provider");
     * ```
     */
    public function getPaymentProvider(): string
    {
        return $this->isStripePaymentMethod() ? 'stripe' : 'other';
    }

    /**
     * Check if payment method requires Stripe API keys.
     *
     * Returns true if the payment is Stripe-powered and thus requires
     * Stripe API credentials to be configured.
     *
     * @return bool True if Stripe API keys are required
     *
     * @example
     * ```php
     * if ($payment->requiresStripeConfiguration()) {
     *     // Verify Stripe API keys are configured
     *     $configService->validateStripeCredentials();
     * }
     * ```
     */
    public function requiresStripeConfiguration(): bool
    {
        return $this->isStripePaymentMethod();
    }

    /**
     * Get the Stripe payment method type.
     *
     * Extracts the payment method type from the payment ID by removing the 'oe_payments_stripe_' prefix.
     * Returns null for non-Stripe payment methods.
     *
     * Examples:
     * - 'oe_payments_stripe_wallet' → 'wallet'
     * - 'paypal' → null (not a Stripe method)
     *
     * @return string|null The Stripe payment method type, or null if not a Stripe method
     *
     * @example
     * ```php
     * $type = $payment->getStripePaymentMethodType();
     * if ($type === 'wallet') {
     *     // Wallet-specific processing
     * }
     * ```
     */
    public function getStripePaymentMethodType(): ?string
    {
        if (!$this->isStripePaymentMethod()) {
            return null;
        }

        $paymentId = $this->getId();

        // Remove 'oe_payments_stripe_' prefix to get the payment method type
        if (str_starts_with($paymentId, 'oe_payments_stripe_')) {
            return substr($paymentId, 19); // strlen('oe_payments_stripe_') = 19
        }

        return null;
    }

    /**
     * Check if this payment method supports a specific Stripe feature.
     *
     * Different Stripe payment methods support different features.
     * This method checks if the current payment method supports a given feature.
     *
     * Supported features:
     * - 'saved_cards' - Payment method can be saved for future use
     * - 'refunds' - Payment method supports refunds
     * - 'partial_refunds' - Payment method supports partial refunds
     *
     * @param string $feature Feature name to check
     * @return bool True if the feature is supported
     *
     * @example
     * ```php
     * if ($payment->supportsStripeFeature('refunds')) {
     *     // Show refund button
     * }
     * ```
     */
    public function supportsStripeFeature(string $feature): bool
    {
        if (!$this->isStripePaymentMethod()) {
            return false;
        }

        $type = $this->getStripePaymentMethodType();

        // Feature support matrix for wallet payments
        return match ($feature) {
            'saved_cards' => in_array($type, ['wallet'], true),
            'refunds' => true, // Wallet payments support refunds
            'partial_refunds' => true, // Wallet payments support partial refunds
            default => false,
        };
    }
}
