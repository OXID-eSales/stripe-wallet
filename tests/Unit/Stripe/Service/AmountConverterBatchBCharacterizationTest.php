<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Service;

use OxidEsales\Payments\Stripe\Core\AmountConverter;
use PHPUnit\Framework\TestCase;

/**
 * Sprint 114.7 — Batch B characterization tests.
 *
 * Pins EUR behavior parity and JPY correction for the sites migrated in Batch B:
 * - RefundService (lines 60, 171)
 * - CheckoutSessionService (lines 169, 192)
 * - CheckoutReturnService (line 100 — log only, no storage impact)
 * - CheckoutReturnResult::getAmount (line 140)
 *
 * @covers \OxidEsales\Payments\Stripe\Core\AmountConverter
 */
final class AmountConverterBatchBCharacterizationTest extends TestCase
{
    // ---------------------------------------------------------------
    // RefundService::processRefund line 60
    // (int) round($amount * 100)  →  AmountConverter::toMinorUnits
    // Already used round() — EUR values unchanged; JPY now correct.
    // ---------------------------------------------------------------

    public function testRefundServiceToMinorUnitsEur(): void
    {
        // Old: (int)round(19.99 * 100) = 1999 (already using round — same result)
        // New: AmountConverter::toMinorUnits(19.99, 'EUR') = 1999
        self::assertSame(1999, AmountConverter::toMinorUnits(19.99, 'EUR'));
        self::assertSame(5000, AmountConverter::toMinorUnits(50.0, 'EUR'));
    }

    public function testRefundServiceToMinorUnitsJpy(): void
    {
        // Old: (int)round(1000 * 100) = 100000 (WRONG — passes 100000 to Stripe for ¥1000)
        // New: AmountConverter::toMinorUnits(1000.0, 'JPY') = 1000 (CORRECT)
        self::assertSame(1000, AmountConverter::toMinorUnits(1000.0, 'JPY'));
    }

    // ---------------------------------------------------------------
    // RefundService::handleRefundResponse line 171
    // ($refund->amount ?? 0) / 100  →  AmountConverter::toMajorUnits
    // ---------------------------------------------------------------

    public function testRefundServiceToMajorUnitsEur(): void
    {
        self::assertEqualsWithDelta(19.99, AmountConverter::toMajorUnits(1999, 'EUR'), 0.00001);
    }

    public function testRefundServiceToMajorUnitsJpy(): void
    {
        // Old: 1000 / 100 = 10.0 (WRONG)
        // New: AmountConverter::toMajorUnits(1000, 'JPY') = 1000.0 (CORRECT)
        self::assertEqualsWithDelta(1000.0, AmountConverter::toMajorUnits(1000, 'JPY'), 0.00001);
    }

    // ---------------------------------------------------------------
    // CheckoutSessionService::buildItemizedLineItems line 169
    // (int) round($unitPrice * 100)  →  AmountConverter::toMinorUnits
    // Already used round() — EUR values unchanged.
    // ---------------------------------------------------------------

    public function testCheckoutSessionUnitPriceEur(): void
    {
        // Old: (int)round(29.99 * 100) = 2999 (already using round — same result)
        // New: AmountConverter::toMinorUnits(29.99, 'EUR') = 2999
        self::assertSame(2999, AmountConverter::toMinorUnits(29.99, 'EUR'));
    }

    public function testCheckoutSessionUnitPriceJpy(): void
    {
        // Old: (int)round(2999 * 100) = 299900 (WRONG for ¥2999)
        // New: AmountConverter::toMinorUnits(2999.0, 'JPY') = 2999 (CORRECT)
        self::assertSame(2999, AmountConverter::toMinorUnits(2999.0, 'JPY'));
    }

    // ---------------------------------------------------------------
    // CheckoutSessionService::buildTotalLineItem line 192
    // (int) round($snapshot->getTotalGross() * 100)
    // ---------------------------------------------------------------

    public function testCheckoutSessionTotalEur(): void
    {
        self::assertSame(9999, AmountConverter::toMinorUnits(99.99, 'EUR'));
    }

    // ---------------------------------------------------------------
    // CheckoutReturnResult::getAmount line 140
    // $this->amountCents / 100  →  AmountConverter::toMajorUnits
    // ---------------------------------------------------------------

    public function testCheckoutReturnResultGetAmountEur(): void
    {
        // Old: 9999 / 100 = 99.99
        // New: AmountConverter::toMajorUnits(9999, 'EUR') = 99.99
        self::assertEqualsWithDelta(99.99, AmountConverter::toMajorUnits(9999, 'EUR'), 0.00001);
    }

    public function testCheckoutReturnResultGetAmountJpy(): void
    {
        // Old: 1000 / 100 = 10.0 (WRONG — checkout result reported ¥1000 as ¥10)
        // New: AmountConverter::toMajorUnits(1000, 'JPY') = 1000.0 (CORRECT)
        self::assertEqualsWithDelta(1000.0, AmountConverter::toMajorUnits(1000, 'JPY'), 0.00001);
    }
}
