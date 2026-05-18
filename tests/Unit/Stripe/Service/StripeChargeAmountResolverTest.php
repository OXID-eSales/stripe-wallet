<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Service;

use OxidEsales\Payments\Stripe\Service\StripeChargeAmountResolver;
use PHPUnit\Framework\TestCase;
use Stripe\Charge;

/**
 * Sprint 103: Pins the partial-capture refund math in isolation.
 *
 * All charge fixtures are built with Charge::constructFrom() so no
 * network call is made. Each case verifies the three accessors of
 * StripeChargeAmountResolver against the Liskov postconditions:
 *   - customerRefundedAmount ∈ [0, amount_captured / 100]
 *   - availableForRefund ∈ [0, amount_captured / 100]
 *   - customerRefundedAmount + availableForRefund == amount_captured / 100
 *   - hasCustomerRefund ⟺ customerRefundedAmount > 0
 *
 * @covers \OxidEsales\Payments\Stripe\Service\StripeChargeAmountResolver
 */
final class StripeChargeAmountResolverTest extends TestCase
{
    private StripeChargeAmountResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new StripeChargeAmountResolver();
    }

    public function testFullCaptureNoRefund(): void
    {
        // Arrange
        $charge = $this->buildCharge(amount: 10000, amountCaptured: 10000, amountRefunded: 0);

        // Act + Assert
        self::assertSame(0.0, $this->resolver->customerRefundedAmount($charge));
        self::assertSame(100.0, $this->resolver->availableForRefund($charge));
        self::assertFalse($this->resolver->hasCustomerRefund($charge));
    }

    public function testFullCaptureWithCustomerRefund(): void
    {
        // Arrange — full capture, then 30 EUR customer refund
        $charge = $this->buildCharge(amount: 10000, amountCaptured: 10000, amountRefunded: 3000);

        // Act + Assert
        self::assertSame(30.0, $this->resolver->customerRefundedAmount($charge));
        self::assertSame(70.0, $this->resolver->availableForRefund($charge));
        self::assertTrue($this->resolver->hasCustomerRefund($charge));
    }

    public function testPartialCaptureNoCustomerRefund(): void
    {
        // Arrange — partial capture: authorised 397 EUR, captured 100 EUR.
        // Stripe encodes the 297 EUR release as amount_refunded — no customer refund.
        $charge = $this->buildCharge(amount: 39700, amountCaptured: 10000, amountRefunded: 29700);

        // Act + Assert
        self::assertSame(0.0, $this->resolver->customerRefundedAmount($charge));
        self::assertSame(100.0, $this->resolver->availableForRefund($charge));
        self::assertFalse($this->resolver->hasCustomerRefund($charge));
    }

    public function testPartialCaptureWithLaterCustomerRefund(): void
    {
        // Arrange — partial capture 397→100, then 50 EUR real customer refund.
        // amount_refunded = 297 (release) + 5000 (customer) = 34700 cents.
        $charge = $this->buildCharge(amount: 39700, amountCaptured: 10000, amountRefunded: 34700);

        // Act + Assert
        self::assertSame(50.0, $this->resolver->customerRefundedAmount($charge));
        self::assertSame(50.0, $this->resolver->availableForRefund($charge));
        self::assertTrue($this->resolver->hasCustomerRefund($charge));
    }

    public function testZeroCaptureZeroRefund(): void
    {
        // Arrange — payment authorised but not yet captured (requires_capture state)
        $charge = $this->buildCharge(amount: 10000, amountCaptured: 0, amountRefunded: 0);

        // Act + Assert
        self::assertSame(0.0, $this->resolver->customerRefundedAmount($charge));
        self::assertSame(0.0, $this->resolver->availableForRefund($charge));
        self::assertFalse($this->resolver->hasCustomerRefund($charge));
    }

    public function testRoundingDownGuard(): void
    {
        // Arrange — float-epsilon edge case: capture 9999 cents, refund 10000 cents.
        // customerRefundedAmount = max(0, R − max(0, A − C))
        //   = max(0, 10000 − max(0, 10000 − 9999)) = max(0, 10000 − 1) = 9999
        //   → 99.99 EUR
        // availableForRefund = max(0, 9999 − 9999 * 100) / 100
        //   = max(0, 9999 − 999900) / 100 → clamped to 0.0
        // The clamp must prevent negative leakage into HTML max attribute.
        $charge = $this->buildCharge(amount: 10000, amountCaptured: 9999, amountRefunded: 10000);

        // Act + Assert
        self::assertSame(0.0, $this->resolver->availableForRefund($charge));
        self::assertFalse($this->resolver->hasCustomerRefund($charge) && $this->resolver->availableForRefund($charge) < 0.0);
    }

    private function buildCharge(int $amount, int $amountCaptured, int $amountRefunded): Charge
    {
        return Charge::constructFrom([
            'id'              => 'ch_test',
            'amount'          => $amount,
            'amount_captured' => $amountCaptured,
            'amount_refunded' => $amountRefunded,
            'currency'        => 'eur',
        ]);
    }
}
