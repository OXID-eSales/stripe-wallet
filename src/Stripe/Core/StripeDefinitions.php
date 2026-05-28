<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Core;

/**
 * Stripe Payment Method Definitions
 *
 * Centralized configuration for Stripe Wallet payment method
 * including multilingual descriptions, constraints, and supported currencies.
 */
final class StripeDefinitions
{
    // Payment method ID
    public const STRIPE_WALLET_PAYMENT_ID = 'oe_payments_stripe_wallet';

    /**
     * Common prefix shared by all Stripe payment method IDs.
     *
     * Single source of truth for the `oe_payments_stripe_` prefix.
     * All prefix checks across the codebase must use either this constant
     * or the isStripePaymentMethod() helper below — never a hardcoded literal.
     */
    public const PAYMENT_PREFIX = 'oe_payments_stripe_';

    /**
     * Canonical provider name for `PaymentContract::getProvider()` and any
     * `provider === 'stripe'` comparison. Single source of truth; new code
     * should reference this constant rather than hardcoding the literal.
     * (Existing call sites still contain the literal — sweep as separate
     * tech-debt cleanup.)
     */
    public const PROVIDER = 'stripe';

    // -------------------------------------------------------------------------
    // Module mode constants (Sprint 114.12 C4)
    // -------------------------------------------------------------------------

    /** Stripe test mode identifier (stored in sStripeMode setting). */
    public const MODE_TEST = 'test';

    /** Stripe live mode identifier (stored in sStripeMode setting). */
    public const MODE_LIVE = 'live';

    /** Automatic capture mode: payment captured immediately on authorization. */
    public const CAPTURE_MODE_AUTOMATIC = 'automatic';

    /** Manual capture mode: payment authorized, captured later (e.g. on shipping). */
    public const CAPTURE_MODE_MANUAL = 'manual';

    // -------------------------------------------------------------------------
    // Audit-transaction type/status constants (Sprint 114.12 C4)
    // -------------------------------------------------------------------------

    /** Transaction type written to oe_payments_transaction when a capture succeeds. */
    public const TRANSACTION_TYPE_CAPTURE = 'capture';

    /** Transaction type written to oe_payments_transaction when a refund succeeds. */
    public const TRANSACTION_TYPE_REFUND = 'refund';

    /** Terminal status for a successfully processed transaction audit record. */
    public const TRANSACTION_STATUS_COMPLETED = 'completed';

    /**
     * Fallback currency used when a Stripe API response omits the currency field.
     *
     * A missing currency in a CaptureResponse or CheckoutSession represents a Stripe
     * API anomaly. The EUR default is a last-resort guard — in production this branch
     * should never be reached; log a warning if it is.
     */
    public const DEFAULT_CURRENCY = 'EUR';

    // Payment constraints
    private const PAYMENT_CONSTRAINTS_DEFAULT = [
        'oxfromamount' => 0.50,
        'oxtoamount' => 999999,
        'oxaddsumtype' => 'abs'
    ];

    /**
     * Payment method definitions
     *
     * Structure:
     * - descriptions: Multilingual descriptions (de, en)
     *   - desc: Short description
     *   - longdesc: Long description (HTML allowed)
     * - countries: Supported countries (empty = all)
     * - currencies: Supported currencies (ISO 4217)
     * - constraints: Payment constraints (from/to amount, addsum type)
     * - defaulton: Default active status
     * - paymenttype: Stripe payment type identifier
     */
    private const STRIPE_DEFINITIONS = [
        // Digital Wallet (Apple Pay, Google Pay)
        self::STRIPE_WALLET_PAYMENT_ID => [
            'descriptions' => [
                'de' => [
                    'desc' => 'Digitale Geldbörse (Stripe)',
                    'longdesc' => 'Bezahlen Sie sicher mit Apple Pay, Google Pay oder anderen digitalen Geldbörsen via Stripe.',
                ],
                'en' => [
                    'desc' => 'Digital Wallet (Stripe)',
                    'longdesc' => 'Pay securely with Apple Pay, Google Pay, or other digital wallets via Stripe.',
                ]
            ],
            'countries' => [],
            'currencies' => [
                'AED', 'AUD', 'BGN', 'BRL', 'CAD', 'CHF', 'CZK', 'DKK', 'EUR', 'GBP',
                'HKD', 'HRK', 'HUF', 'INR', 'JPY', 'MXN', 'MYR', 'NOK', 'NZD', 'PLN',
                'RON', 'SEK', 'SGD', 'USD'
            ],
            'constraints' => self::PAYMENT_CONSTRAINTS_DEFAULT,
            'defaulton' => true,
            'paymenttype' => 'wallet'
        ],
    ];

    /**
     * Get all Stripe payment method definitions
     *
     * @return array<string, array<string, mixed>>
     */
    public static function getStripeDefinitions(): array
    {
        return self::STRIPE_DEFINITIONS;
    }

    /**
     * Check if a payment method ID belongs to the Stripe module.
     *
     * Returns true when the ID starts with the PAYMENT_PREFIX (`oe_payments_stripe_`).
     * This covers all known Stripe payment method IDs (STRIPE_WALLET_PAYMENT_ID and any
     * future extension IDs) with a single, consistent predicate.
     *
     * Single source of truth for the prefix check — replace all inline
     * `str_starts_with($id, 'oe_payments_stripe_')` and `strpos(...)` checks with this.
     */
    public static function isStripePaymentMethod(string $paymentId): bool
    {
        return $paymentId !== '' && str_starts_with($paymentId, self::PAYMENT_PREFIX);
    }

    /**
     * Check if a payment method is a known exact-match Stripe payment (definition-based).
     *
     * @param string $paymentId
     * @return bool
     */
    public static function isStripePayment(string $paymentId): bool
    {
        return isset(self::STRIPE_DEFINITIONS[$paymentId]);
    }

    /**
     * Get payment method type for Stripe API
     *
     * @param string $paymentId
     * @return string|null
     */
    public static function getPaymentType(string $paymentId): ?string
    {
        return self::STRIPE_DEFINITIONS[$paymentId]['paymenttype'] ?? null;
    }

    /**
     * Get supported currencies for a payment method
     *
     * @param string $paymentId
     * @return array<string>
     */
    public static function getSupportedCurrencies(string $paymentId): array
    {
        return self::STRIPE_DEFINITIONS[$paymentId]['currencies'] ?? [];
    }

    /**
     * Get supported countries for a payment method
     *
     * @param string $paymentId
     * @return array<string>
     */
    public static function getSupportedCountries(string $paymentId): array
    {
        return self::STRIPE_DEFINITIONS[$paymentId]['countries'] ?? [];
    }

    /**
     * Check if payment method supports a specific currency
     *
     * @param string $paymentId
     * @param string $currency
     * @return bool
     */
    public static function supportsCurrency(string $paymentId, string $currency): bool
    {
        $currencies = self::getSupportedCurrencies($paymentId);
        return empty($currencies) || in_array(strtoupper($currency), $currencies, true);
    }

    /**
     * Check if payment method supports a specific country
     *
     * @param string $paymentId
     * @param string $country
     * @return bool
     */
    public static function supportsCountry(string $paymentId, string $country): bool
    {
        $countries = self::getSupportedCountries($paymentId);
        return empty($countries) || in_array(strtoupper($country), $countries, true);
    }
}
