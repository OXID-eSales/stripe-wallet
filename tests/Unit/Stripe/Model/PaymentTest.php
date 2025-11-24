<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Unit\Stripe\Model;

use OxidSolutionCatalysts\Payments\Stripe\Model\Payment;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Stripe Payment Model extension.
 *
 * Tests the isStripePowered() and related methods that determine
 * if a payment method is Stripe-powered or from another source.
 *
 * @group unit
 * @group stripe
 * @group model
 *
 * @covers \OxidSolutionCatalysts\Payments\Stripe\Model\Payment
 */
final class PaymentTest extends TestCase
{
    private Payment $payment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->payment = new Payment();
    }

    // ==========================================
    // isStripePowered() Tests
    // ==========================================

    /**
     * @test
     * @dataProvider stripePaymentMethodsProvider
     */
    public function testIsStripePoweredReturnsTrueForStripePaymentMethods(string $paymentId): void
    {
        // Arrange
        $this->payment->setId($paymentId);

        // Act
        $result = $this->payment->isStripePaymentMethod();

        // Assert
        $this->assertTrue(
            $result,
            "Payment method '{$paymentId}' should be recognized as Stripe-powered"
        );
    }

    /**
     * @test
     * @dataProvider nonStripePaymentMethodsProvider
     */
    public function testIsStripePoweredReturnsFalseForNonStripePaymentMethods(string $paymentId): void
    {
        // Arrange
        $this->payment->setId($paymentId);

        // Act
        $result = $this->payment->isStripePaymentMethod();

        // Assert
        $this->assertFalse(
            $result,
            "Payment method '{$paymentId}' should NOT be recognized as Stripe-powered"
        );
    }

    /**
     * @test
     */
    public function testIsStripePoweredReturnsFalseWhenPaymentIdIsEmpty(): void
    {
        // Arrange
        $this->payment->setId('');

        // Act
        $result = $this->payment->isStripePaymentMethod();

        // Assert
        $this->assertFalse($result, 'Empty payment ID should return false');
    }

    /**
     * @test
     */
    public function testIsStripePoweredReturnsTrueForCustomStripePaymentMethod(): void
    {
        // Arrange - Custom Stripe payment method not in predefined list
        $this->payment->setId('stripecustommethod');

        // Act
        $result = $this->payment->isStripePaymentMethod();

        // Assert
        $this->assertTrue(
            $result,
            'Custom payment method starting with "stripe" should be recognized'
        );
    }

    // ==========================================
    // isOtherSourced() Tests
    // ==========================================

    /**
     * @test
     * @dataProvider stripePaymentMethodsProvider
     */
    public function testIsOtherSourcedReturnsFalseForStripePaymentMethods(string $paymentId): void
    {
        // Arrange
        $this->payment->setId($paymentId);

        // Act
        $result = $this->payment->isOtherSourced();

        // Assert
        $this->assertFalse(
            $result,
            "Payment method '{$paymentId}' should NOT be other-sourced"
        );
    }

    /**
     * @test
     * @dataProvider nonStripePaymentMethodsProvider
     */
    public function testIsOtherSourcedReturnsTrueForNonStripePaymentMethods(string $paymentId): void
    {
        // Arrange
        $this->payment->setId($paymentId);

        // Act
        $result = $this->payment->isOtherSourced();

        // Assert
        $this->assertTrue(
            $result,
            "Payment method '{$paymentId}' should be other-sourced"
        );
    }

    // ==========================================
    // getPaymentProvider() Tests
    // ==========================================

    /**
     * @test
     * @dataProvider stripePaymentMethodsProvider
     */
    public function testGetPaymentProviderReturnsStripeForStripePaymentMethods(string $paymentId): void
    {
        // Arrange
        $this->payment->setId($paymentId);

        // Act
        $provider = $this->payment->getPaymentProvider();

        // Assert
        $this->assertSame('stripe', $provider);
    }

    /**
     * @test
     * @dataProvider nonStripePaymentMethodsProvider
     */
    public function testGetPaymentProviderReturnsOtherForNonStripePaymentMethods(string $paymentId): void
    {
        // Arrange
        $this->payment->setId($paymentId);

        // Act
        $provider = $this->payment->getPaymentProvider();

        // Assert
        $this->assertSame('other', $provider);
    }

    // ==========================================
    // requiresStripeConfiguration() Tests
    // ==========================================

    /**
     * @test
     * @dataProvider stripePaymentMethodsProvider
     */
    public function testRequiresStripeConfigurationReturnsTrueForStripePaymentMethods(string $paymentId): void
    {
        // Arrange
        $this->payment->setId($paymentId);

        // Act
        $result = $this->payment->requiresStripeConfiguration();

        // Assert
        $this->assertTrue($result);
    }

    /**
     * @test
     * @dataProvider nonStripePaymentMethodsProvider
     */
    public function testRequiresStripeConfigurationReturnsFalseForNonStripePaymentMethods(string $paymentId): void
    {
        // Arrange
        $this->payment->setId($paymentId);

        // Act
        $result = $this->payment->requiresStripeConfiguration();

        // Assert
        $this->assertFalse($result);
    }

    // ==========================================
    // getStripePaymentMethodType() Tests
    // ==========================================

    /**
     * @test
     * @dataProvider stripePaymentMethodTypesProvider
     */
    public function testGetStripePaymentMethodTypeReturnsCorrectType(
        string $paymentId,
        string $expectedType
    ): void {
        // Arrange
        $this->payment->setId($paymentId);

        // Act
        $type = $this->payment->getStripePaymentMethodType();

        // Assert
        $this->assertSame($expectedType, $type);
    }

    /**
     * @test
     * @dataProvider nonStripePaymentMethodsProvider
     */
    public function testGetStripePaymentMethodTypeReturnsNullForNonStripePaymentMethods(
        string $paymentId
    ): void {
        // Arrange
        $this->payment->setId($paymentId);

        // Act
        $type = $this->payment->getStripePaymentMethodType();

        // Assert
        $this->assertNull($type);
    }

    // ==========================================
    // supportsStripeFeature() Tests
    // ==========================================

    /**
     * @test
     */
    public function testSupportsStripeFeatureReturnsTrueForSavedCardsOnCreditCard(): void
    {
        // Arrange
        $this->payment->setId('stripecreditcard');

        // Act
        $result = $this->payment->supportsStripeFeature('saved_cards');

        // Assert
        $this->assertTrue($result, 'Credit card should support saved_cards');
    }

    /**
     * @test
     */
    public function testSupportsStripeFeatureReturnsFalseForSavedCardsOnSepa(): void
    {
        // Arrange
        $this->payment->setId('stripesepa');

        // Act
        $result = $this->payment->supportsStripeFeature('saved_cards');

        // Assert
        $this->assertFalse($result, 'SEPA should not support saved_cards');
    }

    /**
     * @test
     * @dataProvider stripePaymentMethodsProvider
     */
    public function testSupportsStripeFeatureReturnsTrueForRefundsOnAllStripeMethods(
        string $paymentId
    ): void {
        // Arrange
        $this->payment->setId($paymentId);

        // Act
        $result = $this->payment->supportsStripeFeature('refunds');

        // Assert
        $this->assertTrue($result, "All Stripe methods should support refunds");
    }

    /**
     * @test
     * @dataProvider nonStripePaymentMethodsProvider
     */
    public function testSupportsStripeFeatureReturnsFalseForNonStripeMethods(
        string $paymentId
    ): void {
        // Arrange
        $this->payment->setId($paymentId);

        // Act
        $result = $this->payment->supportsStripeFeature('refunds');

        // Assert
        $this->assertFalse($result, "Non-Stripe methods should not support Stripe features");
    }

    /**
     * @test
     */
    public function testSupportsStripeFeatureReturnsFalseForUnknownFeature(): void
    {
        // Arrange
        $this->payment->setId('stripecreditcard');

        // Act
        $result = $this->payment->supportsStripeFeature('unknown_feature');

        // Assert
        $this->assertFalse($result, "Unknown features should return false");
    }

    /**
     * @test
     */
    public function testSupportsStripeFeatureReturnsCorrectlyFor3DS(): void
    {
        // Credit card supports 3DS
        $this->payment->setId('stripecreditcard');
        $this->assertTrue($this->payment->supportsStripeFeature('3ds'));

        // SEPA does not support 3DS
        $this->payment->setId('stripesepa');
        $this->assertFalse($this->payment->supportsStripeFeature('3ds'));
    }

    /**
     * @test
     */
    public function testSupportsStripeFeatureReturnsCorrectlyForRecurring(): void
    {
        // Credit card supports recurring
        $this->payment->setId('stripecreditcard');
        $this->assertTrue($this->payment->supportsStripeFeature('recurring'));

        // SEPA supports recurring
        $this->payment->setId('stripesepa');
        $this->assertTrue($this->payment->supportsStripeFeature('recurring'));

        // iDEAL does not support recurring
        $this->payment->setId('stripeideal');
        $this->assertFalse($this->payment->supportsStripeFeature('recurring'));
    }

    // ==========================================
    // Data Providers
    // ==========================================

    /**
     * Provides Stripe payment method IDs for testing.
     *
     * @return array<string, array<string>>
     */
    public static function stripePaymentMethodsProvider(): array
    {
        return [
            'Credit Card' => ['stripecreditcard'],
            'SEPA Direct Debit' => ['stripesepa'],
            'iDEAL' => ['stripeideal'],
            'giropay' => ['stripegiropay'],
            'Bancontact' => ['stripebancontact'],
            'Sofort' => ['stripesofort'],
            'EPS' => ['stripeeps'],
            'Przelewy24' => ['stripeprzelewy24'],
        ];
    }

    /**
     * Provides non-Stripe payment method IDs for testing.
     *
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
        ];
    }

    /**
     * Provides Stripe payment method IDs and their expected types.
     *
     * @return array<string, array<string, string>>
     */
    public static function stripePaymentMethodTypesProvider(): array
    {
        return [
            'Credit Card' => [
                'paymentId' => 'stripecreditcard',
                'expectedType' => 'creditcard'
            ],
            'SEPA' => [
                'paymentId' => 'stripesepa',
                'expectedType' => 'sepa'
            ],
            'iDEAL' => [
                'paymentId' => 'stripeideal',
                'expectedType' => 'ideal'
            ],
            'giropay' => [
                'paymentId' => 'stripegiropay',
                'expectedType' => 'giropay'
            ],
            'Bancontact' => [
                'paymentId' => 'stripebancontact',
                'expectedType' => 'bancontact'
            ],
        ];
    }
}
