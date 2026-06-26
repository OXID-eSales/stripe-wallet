<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Controller\Admin;

use OxidEsales\Payments\Stripe\Core\AmountConverter;
use PHPUnit\Framework\TestCase;

/**
 * Sprint 114.7 — Batch C characterization tests.
 *
 * Pins EUR behavior parity and JPY correction for sites migrated in Batch C:
 * - OrderRefundViewDataProvider (lines 136, 191, 231, 248, 262)
 * - StripePanelViewDataBuilder (line 56)
 * - Model/Order (line 193)
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\OxidEsales\Payments\Stripe\Core\AmountConverter::class)]
final class AmountConverterBatchCCharacterizationTest extends TestCase
{
    // ---------------------------------------------------------------
    // OrderRefundViewDataProvider::getCaptureableRaw line 136
    // (int) ($paymentIntent->amount ?? 0) / 100
    // ---------------------------------------------------------------

    public function testCaptureableRawEur(): void
    {
        // Old: (int)(9999) / 100 = 99.99 (note: the cast applies to $paymentIntent->amount, then /100)
        // New: AmountConverter::toMajorUnits(9999, 'EUR') = 99.99
        self::assertEqualsWithDelta(99.99, AmountConverter::toMajorUnits(9999, 'EUR'), 0.00001);
    }

    public function testCaptureableRawJpy(): void
    {
        // Old: (int)(1000) / 100 = 10.0 (WRONG — ¥1000 auth amount shown as ¥10)
        // New: AmountConverter::toMajorUnits(1000, 'JPY') = 1000.0 (CORRECT)
        self::assertEqualsWithDelta(1000.0, AmountConverter::toMajorUnits(1000, 'JPY'), 0.00001);
    }

    // ---------------------------------------------------------------
    // OrderRefundViewDataProvider::getStripeCapturedAmount line 191
    // $charge->amount_captured / 100
    // ---------------------------------------------------------------

    public function testStripeCapturedAmountEur(): void
    {
        self::assertEqualsWithDelta(49.99, AmountConverter::toMajorUnits(4999, 'EUR'), 0.00001);
    }

    public function testStripeCapturedAmountJpy(): void
    {
        // Old: 5000 / 100 = 50.0 (WRONG for ¥5000)
        // New: AmountConverter::toMajorUnits(5000, 'JPY') = 5000.0 (CORRECT)
        self::assertEqualsWithDelta(5000.0, AmountConverter::toMajorUnits(5000, 'JPY'), 0.00001);
    }

    // ---------------------------------------------------------------
    // OrderRefundViewDataProvider::getStripeTransactionHistory line 231
    // ((int) ($paymentIntent->amount ?? 0)) / 100  — authorization entry
    // ---------------------------------------------------------------

    public function testTransactionHistoryAuthAmountEur(): void
    {
        self::assertEqualsWithDelta(99.99, AmountConverter::toMajorUnits(9999, 'EUR'), 0.00001);
    }

    // ---------------------------------------------------------------
    // OrderRefundViewDataProvider::getStripeTransactionHistory line 248
    // ((int) ($charge->amount_captured ?? 0)) / 100  — capture entry
    // ---------------------------------------------------------------

    public function testTransactionHistoryCaptureAmountEur(): void
    {
        self::assertEqualsWithDelta(50.0, AmountConverter::toMajorUnits(5000, 'EUR'), 0.00001);
    }

    // ---------------------------------------------------------------
    // OrderRefundViewDataProvider::getStripeTransactionHistory line 262
    // ((int) ($refund->amount ?? 0)) / 100  — refund entry
    // ---------------------------------------------------------------

    public function testTransactionHistoryRefundAmountEur(): void
    {
        self::assertEqualsWithDelta(19.99, AmountConverter::toMajorUnits(1999, 'EUR'), 0.00001);
    }

    // ---------------------------------------------------------------
    // StripePanelViewDataBuilder line 56
    // ($paymentIntent->amount ?? 0) / 100
    // ---------------------------------------------------------------

    public function testStripePanelAmountEur(): void
    {
        // Old: 9999 / 100 = 99.99 (then number_format'd)
        // New: AmountConverter::toMajorUnits(9999, 'EUR') = 99.99
        self::assertEqualsWithDelta(99.99, AmountConverter::toMajorUnits(9999, 'EUR'), 0.00001);
    }

    // ---------------------------------------------------------------
    // Model/Order::getStripeCapturedAmount line 193
    // ((int) ($charge->amount_captured ?? 0)) / 100
    // ---------------------------------------------------------------

    public function testOrderModelCapturedAmountEur(): void
    {
        self::assertEqualsWithDelta(49.99, AmountConverter::toMajorUnits(4999, 'EUR'), 0.00001);
    }

    public function testOrderModelCapturedAmountJpy(): void
    {
        // Old: 5000 / 100 = 50.0 (WRONG — model showed ¥5000 as ¥50)
        // New: AmountConverter::toMajorUnits(5000, 'JPY') = 5000.0 (CORRECT)
        self::assertEqualsWithDelta(5000.0, AmountConverter::toMajorUnits(5000, 'JPY'), 0.00001);
    }
}
