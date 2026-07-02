<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Service;

use OxidEsales\Payments\Stripe\Adapter\Dto\StripeChargeDto;
use OxidEsales\Payments\Stripe\Service\StripeChargeAmountResolver;
use PHPUnit\Framework\TestCase;

/**
 * Sprint 114.10b: Characterization tests for StripeChargeAmountResolver using DTOs.
 *
 * Mirrors StripeChargeAmountResolverTest but feeds StripeChargeDto instead of
 * \Stripe\Charge. Proves behavior parity before and after the migration.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\OxidEsales\Payments\Stripe\Service\StripeChargeAmountResolver::class)]
final class StripeChargeAmountResolverDtoCharacterizationTest extends TestCase
{
    private StripeChargeAmountResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new StripeChargeAmountResolver();
    }

    public function testFullCaptureNoRefundReturnsZeroCustomerRefund(): void
    {
        $charge = $this->buildDto(amount: 10000, amountCaptured: 10000, amountRefunded: 0);

        self::assertSame(0.0, $this->resolver->customerRefundedAmount($charge));
        self::assertSame(100.0, $this->resolver->availableForRefund($charge));
        self::assertFalse($this->resolver->hasCustomerRefund($charge));
    }

    public function testFullCaptureWithCustomerRefundReturnsCorrectedAmounts(): void
    {
        $charge = $this->buildDto(amount: 10000, amountCaptured: 10000, amountRefunded: 3000);

        self::assertSame(30.0, $this->resolver->customerRefundedAmount($charge));
        self::assertSame(70.0, $this->resolver->availableForRefund($charge));
        self::assertTrue($this->resolver->hasCustomerRefund($charge));
    }

    public function testPartialCaptureNoCustomerRefundReturnsFullCapturedAmount(): void
    {
        // Stripe encodes the 297 EUR release as amount_refunded — no customer refund
        $charge = $this->buildDto(amount: 39700, amountCaptured: 10000, amountRefunded: 29700);

        self::assertSame(0.0, $this->resolver->customerRefundedAmount($charge));
        self::assertSame(100.0, $this->resolver->availableForRefund($charge));
        self::assertFalse($this->resolver->hasCustomerRefund($charge));
    }

    public function testPartialCaptureWithCustomerRefundReturnsCorrectedAmounts(): void
    {
        // partial capture 397→100, then 50 EUR real customer refund
        $charge = $this->buildDto(amount: 39700, amountCaptured: 10000, amountRefunded: 34700);

        self::assertSame(50.0, $this->resolver->customerRefundedAmount($charge));
        self::assertSame(50.0, $this->resolver->availableForRefund($charge));
        self::assertTrue($this->resolver->hasCustomerRefund($charge));
    }

    public function testAvailableForRefundIsNeverNegativeOnEdgeCase(): void
    {
        $charge = $this->buildDto(amount: 10000, amountCaptured: 9999, amountRefunded: 10000);

        self::assertSame(0.0, $this->resolver->availableForRefund($charge));
    }

    public function testJpyFullCaptureNoRefund(): void
    {
        // JPY: 1000 yen — zero-decimal currency, minor units == yen
        $charge = $this->buildDto(amount: 1000, amountCaptured: 1000, amountRefunded: 0, currency: 'jpy');

        self::assertSame(0.0, $this->resolver->customerRefundedAmount($charge));
        // AmountConverter::toMajorUnits(1000, 'JPY') == 1000.0 (no /100)
        self::assertSame(1000.0, $this->resolver->availableForRefund($charge));
    }

    private function buildDto(
        int $amount,
        int $amountCaptured,
        int $amountRefunded,
        string $currency = 'eur',
    ): StripeChargeDto {
        return new StripeChargeDto(
            id: 'ch_test',
            amount: $amount,
            amountCaptured: $amountCaptured,
            amountRefunded: $amountRefunded,
            currency: $currency,
            captured: true,
            created: 0,
        );
    }
}
