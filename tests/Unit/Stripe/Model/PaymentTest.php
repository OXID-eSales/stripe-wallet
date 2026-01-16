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
 * Tests the isStripePaymentMethod() and related methods that determine
 * if a payment method is Stripe-powered (digital wallet) or from another source.
 *
 * Note: Legacy payment methods (stripecreditcard, stripesepa, etc.) are deprecated.
 * Stripe now uses only digital wallet payment method (osc_stripe_wallet).
 *
 * These tests use mocking to avoid OXID Registry initialization issues.
 *
 * @group unit
 * @group stripe
 * @group model
 *
 * @covers \OxidEsales\Payments\Stripe\Model\Payment
 */
final class PaymentTest extends TestCase
{
    /**
     * Create a mock Payment that returns a specific payment ID
     */
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

    /**
     * @test
     * @dataProvider stripePaymentMethodsProvider
     */
    public function testIsStripePaymentMethodReturnsTrueForStripePaymentMethods(string $paymentId): void
    {
        // Arrange
        $payment = $this->createPaymentWithId($paymentId);

        // Act
        $result = $payment->isStripePaymentMethod();

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
    public function testIsStripePaymentMethodReturnsFalseForNonStripePaymentMethods(string $paymentId): void
    {
        // Arrange
        $payment = $this->createPaymentWithId($paymentId);

        // Act
        $result = $payment->isStripePaymentMethod();

        // Assert
        $this->assertFalse(
            $result,
            "Payment method '{$paymentId}' should NOT be recognized as Stripe-powered"
        );
    }

    /**
     * @test
     */
    public function testIsStripePaymentMethodReturnsFalseWhenPaymentIdIsEmpty(): void
    {
        // Arrange
        $payment = $this->createPaymentWithId('');

        // Act
        $result = $payment->isStripePaymentMethod();

        // Assert
        $this->assertFalse($result, 'Empty payment ID should return false');
    }

    /**
     * @test
     */
    public function testIsStripePaymentMethodReturnsTrueForCustomOscStripePaymentMethod(): void
    {
        // Arrange - Custom Stripe payment method with osc_stripe_ prefix
        $payment = $this->createPaymentWithId('osc_stripe_custom');

        // Act
        $result = $payment->isStripePaymentMethod();

        // Assert
        $this->assertTrue(
            $result,
            'Custom payment method starting with "osc_stripe_" should be recognized'
        );
    }

    /**
     * @test
     * @dataProvider legacyPaymentMethodsProvider
     */
    public function testIsStripePaymentMethodReturnsFalseForLegacyPaymentMethods(string $paymentId): void
    {
        // Arrange
        $payment = $this->createPaymentWithId($paymentId);

        // Act
        $result = $payment->isStripePaymentMethod();

        // Assert - Legacy methods (stripe* prefix without osc_) are NOT recognized
        $this->assertFalse(
            $result,
            "Legacy payment method '{$paymentId}' should NOT be recognized (use osc_stripe_* instead)"
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
        $payment = $this->createPaymentWithId($paymentId);

        // Act
        $result = $payment->isOtherSourced();

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
        $payment = $this->createPaymentWithId($paymentId);

        // Act
        $result = $payment->isOtherSourced();

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
        $payment = $this->createPaymentWithId($paymentId);

        // Act
        $provider = $payment->getPaymentProvider();

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
        $payment = $this->createPaymentWithId($paymentId);

        // Act
        $provider = $payment->getPaymentProvider();

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
        $payment = $this->createPaymentWithId($paymentId);

        // Act
        $result = $payment->requiresStripeConfiguration();

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
        $payment = $this->createPaymentWithId($paymentId);

        // Act
        $result = $payment->requiresStripeConfiguration();

        // Assert
        $this->assertFalse($result);
    }

    // ==========================================
    // getStripePaymentMethodType() Tests
    // ==========================================

    /**
     * @test
     */
    public function testGetStripePaymentMethodTypeReturnsWalletForWalletPayment(): void
    {
        // Arrange
        $payment = $this->createPaymentWithId(StripeDefinitions::STRIPE_WALLET_PAYMENT_ID);

        // Act
        $type = $payment->getStripePaymentMethodType();

        // Assert
        $this->assertSame('wallet', $type);
    }

    /**
     * @test
     */
    public function testGetStripePaymentMethodTypeReturnsCorrectTypeForCustomMethod(): void
    {
        // Arrange
        $payment = $this->createPaymentWithId('osc_stripe_custom');

        // Act
        $type = $payment->getStripePaymentMethodType();

        // Assert
        $this->assertSame('custom', $type);
    }

    /**
     * @test
     * @dataProvider nonStripePaymentMethodsProvider
     */
    public function testGetStripePaymentMethodTypeReturnsNullForNonStripePaymentMethods(
        string $paymentId
    ): void {
        // Arrange
        $payment = $this->createPaymentWithId($paymentId);

        // Act
        $type = $payment->getStripePaymentMethodType();

        // Assert
        $this->assertNull($type);
    }

    // ==========================================
    // supportsStripeFeature() Tests
    // ==========================================

    /**
     * @test
     */
    public function testSupportsStripeFeatureReturnsTrueForSavedCardsOnWallet(): void
    {
        // Arrange
        $payment = $this->createPaymentWithId(StripeDefinitions::STRIPE_WALLET_PAYMENT_ID);

        // Act
        $result = $payment->supportsStripeFeature('saved_cards');

        // Assert
        $this->assertTrue($result, 'Wallet should support saved_cards');
    }

    /**
     * @test
     */
    public function testSupportsStripeFeatureReturnsTrueForRefundsOnWallet(): void
    {
        // Arrange
        $payment = $this->createPaymentWithId(StripeDefinitions::STRIPE_WALLET_PAYMENT_ID);

        // Act
        $result = $payment->supportsStripeFeature('refunds');

        // Assert
        $this->assertTrue($result, 'Wallet should support refunds');
    }

    /**
     * @test
     */
    public function testSupportsStripeFeatureReturnsTrueForPartialRefundsOnWallet(): void
    {
        // Arrange
        $payment = $this->createPaymentWithId(StripeDefinitions::STRIPE_WALLET_PAYMENT_ID);

        // Act
        $result = $payment->supportsStripeFeature('partial_refunds');

        // Assert
        $this->assertTrue($result, 'Wallet should support partial_refunds');
    }

    /**
     * @test
     * @dataProvider nonStripePaymentMethodsProvider
     */
    public function testSupportsStripeFeatureReturnsFalseForNonStripeMethods(
        string $paymentId
    ): void {
        // Arrange
        $payment = $this->createPaymentWithId($paymentId);

        // Act
        $result = $payment->supportsStripeFeature('refunds');

        // Assert
        $this->assertFalse($result, "Non-Stripe methods should not support Stripe features");
    }

    /**
     * @test
     */
    public function testSupportsStripeFeatureReturnsFalseForUnknownFeature(): void
    {
        // Arrange
        $payment = $this->createPaymentWithId(StripeDefinitions::STRIPE_WALLET_PAYMENT_ID);

        // Act
        $result = $payment->supportsStripeFeature('unknown_feature');

        // Assert
        $this->assertFalse($result, "Unknown features should return false");
    }

    /**
     * @test
     */
    public function testSupportsStripeFeatureReturnsFalseFor3DSOnWallet(): void
    {
        // Arrange - 3DS is handled automatically by Stripe for wallet, not exposed as feature
        $payment = $this->createPaymentWithId(StripeDefinitions::STRIPE_WALLET_PAYMENT_ID);

        // Act
        $result = $payment->supportsStripeFeature('3ds');

        // Assert
        $this->assertFalse($result, 'Wallet does not expose 3DS as a separate feature');
    }

    /**
     * @test
     */
    public function testSupportsStripeFeatureReturnsFalseForRecurringOnWallet(): void
    {
        // Arrange - Recurring is not exposed as a feature for wallet
        $payment = $this->createPaymentWithId(StripeDefinitions::STRIPE_WALLET_PAYMENT_ID);

        // Act
        $result = $payment->supportsStripeFeature('recurring');

        // Assert
        $this->assertFalse($result, 'Wallet does not expose recurring as a separate feature');
    }

    // ==========================================
    // Data Providers
    // ==========================================

    /**
     * Provides current Stripe payment method IDs for testing.
     * Only digital wallet is supported now.
     *
     * @return array<string, array<string>>
     */
    public static function stripePaymentMethodsProvider(): array
    {
        return [
            'Digital Wallet' => [StripeDefinitions::STRIPE_WALLET_PAYMENT_ID],
        ];
    }

    /**
     * Provides legacy Stripe payment method IDs that are no longer supported.
     * These methods use the old 'stripe*' prefix instead of 'osc_stripe_*'.
     *
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
            'OXID Cash on Delivery' => ['oxidcashondel'],
        ];
    }
}
