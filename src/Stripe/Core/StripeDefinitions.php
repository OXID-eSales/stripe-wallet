<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Stripe\Core;

/**
 * Stripe Payment Method Definitions
 *
 * Centralized configuration for Stripe Wallet payment method
 * including multilingual descriptions, constraints, and supported currencies.
 */
final class StripeDefinitions
{
    // Payment method ID
    public const STRIPE_WALLET_PAYMENT_ID = 'osc_stripe_wallet';

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
     * Check if a payment method is a Stripe payment
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
