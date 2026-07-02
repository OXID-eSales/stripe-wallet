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
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\OxidEsales\Payments\Stripe\Core\StripeDefinitions::class)]
class StripeDefinitionsTest extends TestCase
{
    // ==========================================
    // isStripePaymentMethod() - data-provider tests
    // ==========================================
    #[\PHPUnit\Framework\Attributes\DataProvider('stripePaymentMethodProvider')]
    public function testIsStripePaymentMethodReturnsTrueForStripeIds(string $paymentId): void
    {
        $this->assertTrue(
            StripeDefinitions::isStripePaymentMethod($paymentId),
            "Expected '{$paymentId}' to be recognised as a Stripe payment method"
        );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('nonStripePaymentMethodProvider')]
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

    // ==========================================
    // C4 — mode/capture/transaction constants (Sprint 114.12)
    // ==========================================

    public function testModeTestConstantHasCorrectValue(): void
    {
        $this->assertSame('test', StripeDefinitions::MODE_TEST);
    }

    public function testModeLiveConstantHasCorrectValue(): void
    {
        $this->assertSame('live', StripeDefinitions::MODE_LIVE);
    }

    public function testCaptureModeAutomaticConstantHasCorrectValue(): void
    {
        $this->assertSame('automatic', StripeDefinitions::CAPTURE_MODE_AUTOMATIC);
    }

    public function testCaptureModeManualConstantHasCorrectValue(): void
    {
        $this->assertSame('manual', StripeDefinitions::CAPTURE_MODE_MANUAL);
    }

    public function testTransactionTypeCaptureConstantHasCorrectValue(): void
    {
        $this->assertSame('capture', StripeDefinitions::TRANSACTION_TYPE_CAPTURE);
    }

    public function testTransactionTypeRefundConstantHasCorrectValue(): void
    {
        $this->assertSame('refund', StripeDefinitions::TRANSACTION_TYPE_REFUND);
    }

    public function testTransactionStatusCompletedConstantHasCorrectValue(): void
    {
        $this->assertSame('completed', StripeDefinitions::TRANSACTION_STATUS_COMPLETED);
    }

    public function testDefaultCurrencyConstantHasCorrectValue(): void
    {
        $this->assertSame('EUR', StripeDefinitions::DEFAULT_CURRENCY);
    }
}
