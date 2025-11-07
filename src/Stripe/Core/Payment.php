<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Stripe\Core;

/**
 * Stripe Payment Methods Helper
 *
 * Provides a centralized definition of all available Stripe payment methods
 * that should be installed in the OXID shop during module activation.
 */
class Payment
{
    /**
     * Singleton instance
     *
     * @var Payment|null
     */
    private static ?Payment $instance = null;

    /**
     * List of available Stripe payment methods
     *
     * @var array<string, string> Payment ID => Payment Title
     */
    private array $stripePaymentMethods = [
        'stripecreditcard' => 'Stripe Credit Card',
        'stripesepa' => 'Stripe SEPA Direct Debit',
        'stripeideal' => 'Stripe iDEAL',
        'stripegiropay' => 'Stripe giropay',
        'stripebancontact' => 'Stripe Bancontact',
        'stripesofort' => 'Stripe Sofort',
        'stripeeps' => 'Stripe EPS',
        'stripeprzelewy24' => 'Stripe Przelewy24',
    ];

    /**
     * Private constructor for singleton pattern
     */
    private function __construct()
    {
    }

    /**
     * Get singleton instance
     *
     * @return Payment
     */
    public static function getInstance(): Payment
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Get all available Stripe payment methods
     *
     * @return array<string, string> Payment ID => Payment Title
     */
    public function getStripePaymentMethods(): array
    {
        return $this->stripePaymentMethods;
    }

    /**
     * Check if a payment method ID is a Stripe payment method
     *
     * @param string $paymentId
     * @return bool
     */
    public function isStripePaymentMethod(string $paymentId): bool
    {
        return isset($this->stripePaymentMethods[$paymentId]);
    }

    /**
     * Get payment method title by ID
     *
     * @param string $paymentId
     * @return string|null
     */
    public function getPaymentMethodTitle(string $paymentId): ?string
    {
        return $this->stripePaymentMethods[$paymentId] ?? null;
    }
}
