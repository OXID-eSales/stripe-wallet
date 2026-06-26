<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Model;

use OxidEsales\Payments\Stripe\Adapter\Dto\StripeChargeDto;
use OxidEsales\Payments\Stripe\Model\Order;
use OxidEsales\Payments\Stripe\Service\ChargeAmountResolverInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Sprint 103: Pins getStripeRefundedAmount() and hasStripeRefunds() against
 * the partial-capture fixture where amount_refunded includes the auth-release.
 *
 * Sprint 114.10b: migrated from \Stripe\Charge fixtures to StripeChargeDto
 * (A1 boundary fix). Behavior is identical.
 *
 * Order extends Order_parent (OXID class chain) — constructor DI is not
 * possible. Tests use a testable subclass that overrides getStripeCharge()
 * and the resolver accessor so no ContainerFactory or Stripe API call is made.
 * This follows CLAUDE.md §"Testable subclass pattern".
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\OxidEsales\Payments\Stripe\Model\Order::class)]
final class OrderStripeAmountsTest extends TestCase
{
    private ChargeAmountResolverInterface&MockObject $resolver;

    protected function setUp(): void
    {
        $this->resolver = $this->createMock(ChargeAmountResolverInterface::class);
    }

    public function testGetStripeRefundedAmountEmptyForFullCaptureNoRefund(): void
    {
        // Arrange — full capture, no refund: R_customer = 0 → empty string
        $charge = $this->buildCharge(amount: 10000, amountCaptured: 10000, amountRefunded: 0);
        $this->resolver->method('customerRefundedAmount')->willReturn(0.0);
        $this->resolver->method('hasCustomerRefund')->willReturn(false);

        // Act
        $result = $this->createOrder($charge)->getStripeRefundedAmount();

        // Assert
        self::assertSame('', $result);
    }

    public function testGetStripeRefundedAmountFormattedForFullCaptureWithCustomerRefund(): void
    {
        // Arrange — full capture, 30 EUR customer refund
        $charge = $this->buildCharge(amount: 10000, amountCaptured: 10000, amountRefunded: 3000);
        $this->resolver->method('customerRefundedAmount')->willReturn(30.0);
        $this->resolver->method('hasCustomerRefund')->willReturn(true);

        // Act
        $result = $this->createOrder($charge)->getStripeRefundedAmount();

        // Assert — formatted string includes the amount; exact locale varies
        self::assertStringContainsString('30', $result);
    }

    public function testGetStripeRefundedAmountEmptyForPartialCaptureNoCustomerRefund(): void
    {
        // Arrange — partial capture 397→100. amount_refunded = 29700 (auth-release only).
        // Pre-fix: getStripeRefundedAmount() returned '297,00 €'. Post-fix: ''.
        $charge = $this->buildCharge(amount: 39700, amountCaptured: 10000, amountRefunded: 29700);
        $this->resolver->method('customerRefundedAmount')->willReturn(0.0);
        $this->resolver->method('hasCustomerRefund')->willReturn(false);

        // Act
        $result = $this->createOrder($charge)->getStripeRefundedAmount();

        // Assert — regression case: must return '' not '297,00 €'
        self::assertSame('', $result);
    }

    public function testGetStripeRefundedAmountFormattedForPartialCaptureWithCustomerRefund(): void
    {
        // Arrange — partial capture 397→100, then 50 EUR customer refund
        $charge = $this->buildCharge(amount: 39700, amountCaptured: 10000, amountRefunded: 34700);
        $this->resolver->method('customerRefundedAmount')->willReturn(50.0);
        $this->resolver->method('hasCustomerRefund')->willReturn(true);

        // Act
        $result = $this->createOrder($charge)->getStripeRefundedAmount();

        // Assert
        self::assertStringContainsString('50', $result);
    }

    public function testHasStripeRefundsFalseForPartialCaptureNoCustomerRefund(): void
    {
        // Arrange — partial capture, no customer refund: hasCustomerRefund = false
        $charge = $this->buildCharge(amount: 39700, amountCaptured: 10000, amountRefunded: 29700);
        $this->resolver->method('hasCustomerRefund')->willReturn(false);

        // Act
        $result = $this->createOrder($charge)->hasStripeRefunds();

        // Assert
        self::assertFalse($result);
    }

    public function testHasStripeRefundsTrueAfterCustomerRefund(): void
    {
        // Arrange — partial capture, then 50 EUR customer refund
        $charge = $this->buildCharge(amount: 39700, amountCaptured: 10000, amountRefunded: 34700);
        $this->resolver->method('hasCustomerRefund')->willReturn(true);

        // Act
        $result = $this->createOrder($charge)->hasStripeRefunds();

        // Assert
        self::assertTrue($result);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Creates a testable subclass of Order that overrides getStripeCharge() and
     * the resolver accessor, bypassing ContainerFactory and the Stripe API.
     */
    private function createOrder(?StripeChargeDto $charge): Order
    {
        $resolver = $this->resolver;

        return new class ($charge, $resolver) extends Order {
            public function __construct(
                private readonly ?StripeChargeDto $stubCharge,
                private readonly ChargeAmountResolverInterface $stubResolver,
            ) {
                // Skip OXID parent — Order extends Order_parent (virtual class chain).
                // Parent constructor would attempt DB/Registry access. The methods
                // under test only use getStripeCharge() and getChargeAmountResolver().
            }

            protected function getStripeCharge(): ?StripeChargeDto
            {
                return $this->stubCharge;
            }

            protected function getChargeAmountResolver(): ChargeAmountResolverInterface
            {
                return $this->stubResolver;
            }

            protected function formatStripeAmount(float $amount): string
            {
                return (string) $amount;
            }
        };
    }

    private function buildCharge(int $amount, int $amountCaptured, int $amountRefunded): StripeChargeDto
    {
        return new StripeChargeDto(
            id: 'ch_test',
            amount: $amount,
            amountCaptured: $amountCaptured,
            amountRefunded: $amountRefunded,
            currency: 'eur',
            captured: true,
            created: 0,
        );
    }
}
