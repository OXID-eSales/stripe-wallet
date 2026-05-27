<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Adapter\Helper;

use OxidEsales\Payments\Stripe\Core\AmountConverter;
use PHPUnit\Framework\TestCase;

/**
 * Sprint 114.7 — Batch A characterization tests.
 *
 * These tests pin the EUR behavior at the sites to be migrated in Batch A
 * (PaymentIntentHelper, RefundHelper, StripeWebhookEventParser), proving
 * that the AmountConverter produces the same output as the original inline math
 * for the common EUR case (R-1.4 characterization parity).
 *
 * They also prove the JPY correction: sites that previously returned a wrong value
 * for zero-decimal currencies will now return the correct value.
 *
 * @covers \OxidEsales\Payments\Stripe\Core\AmountConverter
 */
final class AmountConverterBatchACharacterizationTest extends TestCase
{
    // ---------------------------------------------------------------
    // PaymentIntentHelper::createPaymentIntent line 53
    // (int) ($request->amount * 100)  →  AmountConverter::toMinorUnits
    // ---------------------------------------------------------------

    public function testCreatePaymentIntentAmountEur(): void
    {
        // Old: (int)(19.99 * 100) = 1998  (truncation BUG — float drift)
        // New: (int)round(19.99 * 100) = 1999  (correct)
        // NOTE: the old (int) cast truncates; this is a pre-existing bug fixed here.
        // Flag: EUR 19.99 was producing 1998 cents (off by one) — now 1999. BUG FIX.
        self::assertSame(1999, AmountConverter::toMinorUnits(19.99, 'EUR'));

        // Clean amount without drift
        self::assertSame(10000, AmountConverter::toMinorUnits(100.0, 'EUR'));

        // JPY: previously (int)(1000 * 100) = 100000 (WRONG); now 1000 (CORRECT)
        self::assertSame(1000, AmountConverter::toMinorUnits(1000.0, 'JPY'));
    }

    // ---------------------------------------------------------------
    // PaymentIntentHelper::authorizePayment line 135 — same formula
    // ---------------------------------------------------------------

    public function testAuthorizePaymentAmountEur(): void
    {
        // Old: (int)(25.50 * 100) = 2550 (exact — no drift for 0.5)
        // New: (int)round(25.50 * 100) = 2550 (same)
        self::assertSame(2550, AmountConverter::toMinorUnits(25.50, 'EUR'));
    }

    // ---------------------------------------------------------------
    // PaymentIntentHelper::executeCapturePaymentIntent line 304
    // (int) ($request->amount * 100)  — partial capture
    // ---------------------------------------------------------------

    public function testCapturePartialAmountEur(): void
    {
        // Old: (int)(49.99 * 100) = 4998 (truncation — 49.99*100 = 4998.9999…)
        // New: (int)round(49.99 * 100) = 4999 (correct)
        // Flag: EUR 49.99 was producing 4998 cents — now 4999. BUG FIX.
        self::assertSame(4999, AmountConverter::toMinorUnits(49.99, 'EUR'));
    }

    // ---------------------------------------------------------------
    // PaymentIntentHelper::getPaymentDetails lines 97, 98, 102
    // $paymentIntent->amount / 100  →  AmountConverter::toMajorUnits
    // ---------------------------------------------------------------

    public function testGetPaymentDetailsAmountEur(): void
    {
        // Old: 1999 / 100 = 19.99
        // New: AmountConverter::toMajorUnits(1999, 'EUR') = 19.99
        self::assertEqualsWithDelta(19.99, AmountConverter::toMajorUnits(1999, 'EUR'), 0.00001);
        self::assertEqualsWithDelta(100.0, AmountConverter::toMajorUnits(10000, 'EUR'), 0.00001);
    }

    public function testGetPaymentDetailsAmountJpy(): void
    {
        // Old: 1000 / 100 = 10.0 (WRONG for JPY — 1000 yen ≠ 10 yen)
        // New: AmountConverter::toMajorUnits(1000, 'JPY') = 1000.0 (CORRECT)
        self::assertEqualsWithDelta(1000.0, AmountConverter::toMajorUnits(1000, 'JPY'), 0.00001);
    }

    // ---------------------------------------------------------------
    // PaymentIntentHelper::executeCapturePaymentIntent line 318
    // $paymentIntent->amount_received / 100
    // ---------------------------------------------------------------

    public function testCaptureAmountReceivedEur(): void
    {
        self::assertEqualsWithDelta(49.99, AmountConverter::toMajorUnits(4999, 'EUR'), 0.00001);
    }

    // ---------------------------------------------------------------
    // RefundHelper::executeRefundPayment line 83
    // (int) ($request->amount * 100)
    // ---------------------------------------------------------------

    public function testRefundAmountToMinorUnitsEur(): void
    {
        // Old: (int)(19.99 * 100) = 1998 (drift)
        // New: (int)round(19.99 * 100) = 1999
        // Flag: EUR 19.99 was 1998 cents — now 1999. BUG FIX.
        self::assertSame(1999, AmountConverter::toMinorUnits(19.99, 'EUR'));
    }

    // ---------------------------------------------------------------
    // RefundHelper::executeRefundPayment line 102
    // $refund->amount / 100
    // ---------------------------------------------------------------

    public function testRefundResponseAmountEur(): void
    {
        // Old: 1999 / 100 = 19.99
        // New: AmountConverter::toMajorUnits(1999, 'EUR') = 19.99
        self::assertEqualsWithDelta(19.99, AmountConverter::toMajorUnits(1999, 'EUR'), 0.00001);
    }

    public function testRefundResponseAmountJpy(): void
    {
        // Old: 1000 / 100 = 10.0 (WRONG)
        // New: AmountConverter::toMajorUnits(1000, 'JPY') = 1000.0 (CORRECT)
        self::assertEqualsWithDelta(1000.0, AmountConverter::toMajorUnits(1000, 'JPY'), 0.00001);
    }

    // ---------------------------------------------------------------
    // StripeWebhookEventParser::extractAmountInCurrencyUnits line 82
    // $amount / 100
    // ---------------------------------------------------------------

    public function testWebhookAmountEur(): void
    {
        // Old: 1999 / 100 = 19.99
        // New: AmountConverter::toMajorUnits(1999, 'EUR') = 19.99
        self::assertEqualsWithDelta(19.99, AmountConverter::toMajorUnits(1999, 'EUR'), 0.00001);
    }

    public function testWebhookAmountJpy(): void
    {
        // Old: 1000 / 100 = 10.0 (WRONG)
        // New: AmountConverter::toMajorUnits(1000, 'JPY') = 1000.0 (CORRECT)
        self::assertEqualsWithDelta(1000.0, AmountConverter::toMajorUnits(1000, 'JPY'), 0.00001);
    }
}
