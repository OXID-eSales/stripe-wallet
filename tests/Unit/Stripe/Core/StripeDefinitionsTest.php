<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Core;

use OxidEsales\Payments\Stripe\Core\StripeDefinitions;
use PHPUnit\Framework\TestCase;

/**
 * D7: StripeDefinitions::isStripePaymentMethod() must be the single predicate
 * for the `oe_payments_stripe_` prefix check across all call sites.
 *
 * @covers \OxidEsales\Payments\Stripe\Core\StripeDefinitions
 */
class StripeDefinitionsTest extends TestCase
{
    // ==========================================
    // isStripePaymentMethod() - data-provider tests
    // ==========================================

    /**
     * @dataProvider stripePaymentMethodProvider
     */
    public function testIsStripePaymentMethodReturnsTrueForStripeIds(string $paymentId): void
    {
        $this->assertTrue(
            StripeDefinitions::isStripePaymentMethod($paymentId),
            "Expected '{$paymentId}' to be recognised as a Stripe payment method"
        );
    }

    /**
     * @dataProvider nonStripePaymentMethodProvider
     */
    public function testIsStripePaymentMethodReturnsFalseForNonStripeIds(string $paymentId): void
    {
        $this->assertFalse(
            StripeDefinitions::isStripePaymentMethod($paymentId),
            "Expected '{$paymentId}' NOT to be recognised as a Stripe payment method"
        );
    }

    public function testIsStripePaymentMethodReturnsFalseForEmptyString(): void
    {
        $this->assertFalse(StripeDefinitions::isStripePaymentMethod(''));
    }

    /**
     * PAYMENT_PREFIX constant must be the literal prefix used in all call sites.
     */
    public function testPaymentPrefixConstantHasCorrectValue(): void
    {
        $this->assertSame('oe_payments_stripe_', StripeDefinitions::PAYMENT_PREFIX);
    }

    /**
     * isStripePaymentMethod() must accept any id starting with PAYMENT_PREFIX.
     */
    public function testIsStripePaymentMethodReturnsTrueForArbitraryPrefixedId(): void
    {
        $this->assertTrue(
            StripeDefinitions::isStripePaymentMethod(StripeDefinitions::PAYMENT_PREFIX . 'custom_method')
        );
    }

    // ==========================================
    // Data providers
    // ==========================================

    /** @return array<string, array{string}> */
    public static function stripePaymentMethodProvider(): array
    {
        return [
            'wallet id' => [StripeDefinitions::STRIPE_WALLET_PAYMENT_ID],
            'arbitrary prefixed id' => ['oe_payments_stripe_creditcard'],
        ];
    }

    /** @return array<string, array{string}> */
    public static function nonStripePaymentMethodProvider(): array
    {
        return [
            'oxidcashondel' => ['oxidcashondel'],
            'paypal' => ['paypal'],
            'invoice' => ['invoice'],
            'partial prefix' => ['oe_payments_'],
            'wrong prefix' => ['stripe_wallet'],
        ];
    }
}
