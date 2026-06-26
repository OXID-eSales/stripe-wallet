<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Model;

use OxidEsales\Payments\Stripe\Core\StripeDefinitions;
use OxidEsales\Payments\Stripe\Model\Payment;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Stripe Payment Model extension.
 *
 * Tests isStripePaymentMethod() — the single predicate that determines
 * whether a payment belongs to the Stripe module.
 *
 *
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\OxidEsales\Payments\Stripe\Model\Payment::class)]
#[\PHPUnit\Framework\Attributes\Group('unit')]
#[\PHPUnit\Framework\Attributes\Group('stripe')]
#[\PHPUnit\Framework\Attributes\Group('model')]
final class PaymentTest extends TestCase
{
    private function createPaymentWithId(string $paymentId): Payment
    {
        /** @var Payment $payment */
        $payment = $this->getMockBuilder(Payment::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId'])
            ->getMock();

        $payment->method('getId')->willReturn($paymentId);

        return $payment;
    }

    // ==========================================
    // isStripePaymentMethod() Tests
    // ==========================================
    #[\PHPUnit\Framework\Attributes\DataProvider('stripePaymentMethodsProvider')]
    #[\PHPUnit\Framework\Attributes\Test]
    public function testIsStripePaymentMethodReturnsTrueForStripePaymentMethods(string $paymentId): void
    {
        $payment = $this->createPaymentWithId($paymentId);

        $result = $payment->isStripePaymentMethod();

        $this->assertTrue(
            $result,
            "Payment method '{$paymentId}' should be recognized as Stripe-powered"
        );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('nonStripePaymentMethodsProvider')]
    #[\PHPUnit\Framework\Attributes\Test]
    public function testIsStripePaymentMethodReturnsFalseForNonStripePaymentMethods(string $paymentId): void
    {
        $payment = $this->createPaymentWithId($paymentId);

        $result = $payment->isStripePaymentMethod();

        $this->assertFalse(
            $result,
            "Payment method '{$paymentId}' should NOT be recognized as Stripe-powered"
        );
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testIsStripePaymentMethodReturnsFalseWhenPaymentIdIsEmpty(): void
    {
        $payment = $this->createPaymentWithId('');

        $result = $payment->isStripePaymentMethod();

        $this->assertFalse($result, 'Empty payment ID should return false');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testIsStripePaymentMethodReturnsTrueForCustomStripePaymentMethod(): void
    {
        $payment = $this->createPaymentWithId('oe_payments_stripe_custom');

        $result = $payment->isStripePaymentMethod();

        $this->assertTrue(
            $result,
            'Custom payment method starting with "oe_payments_stripe_" should be recognized'
        );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('legacyPaymentMethodsProvider')]
    #[\PHPUnit\Framework\Attributes\Test]
    public function testIsStripePaymentMethodReturnsFalseForLegacyPaymentMethods(string $paymentId): void
    {
        $payment = $this->createPaymentWithId($paymentId);

        $result = $payment->isStripePaymentMethod();

        $this->assertFalse(
            $result,
            "Legacy payment method '{$paymentId}' should NOT be recognized (use oe_payments_stripe_* instead)"
        );
    }

    // ==========================================
    // Data Providers
    // ==========================================

    /**
     * @return array<string, array<string>>
     */
    public static function stripePaymentMethodsProvider(): array
    {
        return [
            'Digital Wallet' => [StripeDefinitions::STRIPE_WALLET_PAYMENT_ID],
        ];
    }

    /**
     * @return array<string, array<string>>
     */
    public static function legacyPaymentMethodsProvider(): array
    {
        return [
            'Legacy Credit Card' => ['stripecreditcard'],
            'Legacy SEPA Direct Debit' => ['stripesepa'],
            'Legacy iDEAL' => ['stripeideal'],
            'Legacy giropay' => ['stripegiropay'],
            'Legacy Bancontact' => ['stripebancontact'],
            'Legacy Sofort' => ['stripesofort'],
            'Legacy EPS' => ['stripeeps'],
            'Legacy Przelewy24' => ['stripeprzelewy24'],
        ];
    }

    /**
     * @return array<string, array<string>>
     */
    public static function nonStripePaymentMethodsProvider(): array
    {
        return [
            'PayPal' => ['paypal'],
            'Invoice' => ['invoice'],
            'Bank Transfer' => ['banktransfer'],
            'Cash on Delivery' => ['cod'],
            'Amazon Pay' => ['amazonpay'],
            'Klarna' => ['klarna'],
            'Unzer' => ['unzer'],
            'OXID Cash on Delivery' => ['oxidcashondel'],
        ];
    }
}
