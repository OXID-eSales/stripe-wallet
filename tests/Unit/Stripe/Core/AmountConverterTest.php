<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Core;

use OxidEsales\PaymentBase\Adapter\Request\CapturePaymentRequest;
use OxidEsales\PaymentBase\Adapter\Request\RefundPaymentRequest;
use OxidEsales\Payments\Stripe\Core\AmountConverter;
use PHPUnit\Framework\TestCase;

/**
 * Sprint 114.7: Pins the AmountConverter currency-aware cents math.
 *
 * RED written before AmountConverter.php exists — all tests must fail first.
 *
 * Covers:
 * - 2-decimal currencies (EUR, USD, GBP) — the common path
 * - 0-decimal currencies (JPY, KRW, VND, …) — the latent bug fix
 * - 3-decimal currencies (BHD, KWD) — supported at exponent 3
 * - Float-drift safety: toMinorUnits uses (int) round(), not truncation
 * - Case-insensitive currency matching
 * - Unknown/empty currency → fallback to 2 decimals
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\OxidEsales\Payments\Stripe\Core\AmountConverter::class)]
final class AmountConverterTest extends TestCase
{
    // ---------------------------------------------------------------
    // decimalsFor
    // ---------------------------------------------------------------
    #[\PHPUnit\Framework\Attributes\DataProvider('decimalsProvider')]
    public function testDecimalsFor(string $currency, int $expectedDecimals): void
    {
        self::assertSame($expectedDecimals, AmountConverter::decimalsFor($currency));
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function decimalsProvider(): array
    {
        return [
            'EUR → 2'        => ['EUR', 2],
            'USD → 2'        => ['USD', 2],
            'GBP → 2'        => ['GBP', 2],
            'JPY → 0'        => ['JPY', 0],
            'KRW → 0'        => ['KRW', 0],
            'VND → 0'        => ['VND', 0],
            'BIF → 0'        => ['BIF', 0],
            'CLP → 0'        => ['CLP', 0],
            'DJF → 0'        => ['DJF', 0],
            'GNF → 0'        => ['GNF', 0],
            'KMF → 0'        => ['KMF', 0],
            'MGA → 0'        => ['MGA', 0],
            'PYG → 0'        => ['PYG', 0],
            'RWF → 0'        => ['RWF', 0],
            'UGX → 0'        => ['UGX', 0],
            'VUV → 0'        => ['VUV', 0],
            'XAF → 0'        => ['XAF', 0],
            'XOF → 0'        => ['XOF', 0],
            'XPF → 0'        => ['XPF', 0],
            'BHD → 3'        => ['BHD', 3],
            'JOD → 3'        => ['JOD', 3],
            'KWD → 3'        => ['KWD', 3],
            'OMR → 3'        => ['OMR', 3],
            'TND → 3'        => ['TND', 3],
            // Case-insensitive
            'jpy lower → 0'  => ['jpy', 0],
            'eur lower → 2'  => ['eur', 2],
            'Eur mixed → 2'  => ['Eur', 2],
            // Unknown / empty → fallback 2
            'unknown → 2'    => ['XXX', 2],
            'empty → 2'      => ['', 2],
        ];
    }

    // ---------------------------------------------------------------
    // toMinorUnits — EUR (2-decimal) correctness + rounding
    // ---------------------------------------------------------------
    #[\PHPUnit\Framework\Attributes\DataProvider('toMinorUnitsProvider')]
    public function testToMinorUnits(float $major, string $currency, int $expectedMinor): void
    {
        self::assertSame($expectedMinor, AmountConverter::toMinorUnits($major, $currency));
    }

    /**
     * @return array<string, array{float, string, int}>
     */
    public static function toMinorUnitsProvider(): array
    {
        return [
            // Standard EUR cases
            'EUR 19.99 → 1999'    => [19.99, 'EUR', 1999],
            'EUR 100.00 → 10000'  => [100.00, 'EUR', 10000],
            'EUR 0.01 → 1'        => [0.01, 'EUR', 1],
            'EUR 0.00 → 0'        => [0.00, 'EUR', 0],

            // Float drift: 19.99 * 100 in IEEE754 = 1998.9999…  — truncation gives 1998, round gives 1999
            'EUR float-drift 19.99' => [19.99, 'EUR', 1999],
            // 0.1 + 0.2 drift
            'EUR 0.30 float-drift'  => [0.1 + 0.2, 'EUR', 30],

            // USD
            'USD 25.50 → 2550'    => [25.50, 'USD', 2550],

            // JPY (0 decimals) — THE BUG FIX: 1000 JPY → 1000 minor units, not 100000
            'JPY 1000 → 1000'     => [1000.0, 'JPY', 1000],
            'JPY 500 → 500'       => [500.0, 'JPY', 500],
            'JPY 1 → 1'           => [1.0, 'JPY', 1],
            'JPY 0 → 0'           => [0.0, 'JPY', 0],

            // KRW (0 decimals)
            'KRW 10000 → 10000'   => [10000.0, 'KRW', 10000],

            // BHD (3 decimals) — 1.234 BHD → 1234 fils
            'BHD 1.234 → 1234'   => [1.234, 'BHD', 1234],
            'BHD 10.000 → 10000' => [10.0, 'BHD', 10000],

            // Case-insensitive currency
            'jpy lower 500 → 500' => [500.0, 'jpy', 500],
            'eur lower 9.99 → 999' => [9.99, 'eur', 999],
        ];
    }

    // ---------------------------------------------------------------
    // toMajorUnits — reverse of toMinorUnits
    // ---------------------------------------------------------------
    #[\PHPUnit\Framework\Attributes\DataProvider('toMajorUnitsProvider')]
    public function testToMajorUnits(int $minor, string $currency, float $expectedMajor): void
    {
        self::assertEqualsWithDelta($expectedMajor, AmountConverter::toMajorUnits($minor, $currency), 0.00001);
    }

    /**
     * @return array<string, array{int, string, float}>
     */
    public static function toMajorUnitsProvider(): array
    {
        return [
            // EUR
            'EUR 1999 → 19.99'   => [1999, 'EUR', 19.99],
            'EUR 10000 → 100.00' => [10000, 'EUR', 100.00],
            'EUR 1 → 0.01'       => [1, 'EUR', 0.01],
            'EUR 0 → 0.00'       => [0, 'EUR', 0.00],

            // JPY (0 decimals): minor unit IS the currency unit
            'JPY 1000 → 1000.0'  => [1000, 'JPY', 1000.0],
            'JPY 1 → 1.0'        => [1, 'JPY', 1.0],
            'JPY 0 → 0.0'        => [0, 'JPY', 0.0],

            // KRW
            'KRW 10000 → 10000.0' => [10000, 'KRW', 10000.0],

            // BHD (3 decimals)
            'BHD 1234 → 1.234'   => [1234, 'BHD', 1.234],
            'BHD 10000 → 10.0'   => [10000, 'BHD', 10.0],

            // Case-insensitive
            'jpy 500 → 500.0'    => [500, 'jpy', 500.0],
        ];
    }

    // ---------------------------------------------------------------
    // Round-trip invariant: toMajorUnits(toMinorUnits(x)) == x
    // ---------------------------------------------------------------
    #[\PHPUnit\Framework\Attributes\DataProvider('roundTripProvider')]
    public function testRoundTrip(float $major, string $currency): void
    {
        $minor = AmountConverter::toMinorUnits($major, $currency);
        $back  = AmountConverter::toMajorUnits($minor, $currency);
        self::assertEqualsWithDelta($major, $back, 0.00001, "Round-trip failed for {$major} {$currency}");
    }

    /**
     * @return array<string, array{float, string}>
     */
    public static function roundTripProvider(): array
    {
        return [
            'EUR 19.99' => [19.99, 'EUR'],
            'EUR 100.0' => [100.0, 'EUR'],
            'JPY 1000'  => [1000.0, 'JPY'],
            'BHD 1.234' => [1.234, 'BHD'],
        ];
    }

    // ---------------------------------------------------------------
    // Zero-decimal set membership — pinned so future table edits are deliberate
    // ---------------------------------------------------------------

    public function testZeroDecimalSetIsPinned(): void
    {
        $expected = ['BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'];

        foreach ($expected as $currency) {
            self::assertSame(0, AmountConverter::decimalsFor($currency), "Expected {$currency} to be zero-decimal");
        }
    }

    public function testThreeDecimalSetIsPinned(): void
    {
        $expected = ['BHD', 'JOD', 'KWD', 'OMR', 'TND'];

        foreach ($expected as $currency) {
            self::assertSame(3, AmountConverter::decimalsFor($currency), "Expected {$currency} to be three-decimal");
        }
    }

    // ---------------------------------------------------------------
    // Sprint 114.10a (§6.2): currency field threading via request DTOs
    // ---------------------------------------------------------------
    #[\PHPUnit\Framework\Attributes\Test]
    public function captureRequestWithJpyCurrencyConvertsToCorrectMinorUnits(): void
    {
        $request = new CapturePaymentRequest(
            providerPaymentId: 'pi_jpy_test',
            amount: 1000.0,
            currency: 'JPY'
        );

        // JPY is zero-decimal: 1000 major units = 1000 minor units (NOT 100000)
        $minorUnits = AmountConverter::toMinorUnits($request->amount, $request->currency ?? '');

        self::assertSame(1000, $minorUnits);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function refundRequestWithJpyCurrencyConvertsToCorrectMinorUnits(): void
    {
        $request = new RefundPaymentRequest(
            providerPaymentId: 'pi_jpy_test',
            amount: 1000.0,
            currency: 'JPY'
        );

        // JPY is zero-decimal: 1000 major units = 1000 minor units (NOT 100000)
        $minorUnits = AmountConverter::toMinorUnits($request->amount, $request->currency ?? '');

        self::assertSame(1000, $minorUnits);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function withoutCurrencyEmptyStringDefaultsToTwoDecimalsForJpy(): void
    {
        // This was the bug: treating JPY as if it were EUR
        $wrongMinorUnits = AmountConverter::toMinorUnits(1000.0, '');

        // Empty currency → 2-decimal fallback → 1000 * 100 = 100000 (WRONG for JPY)
        self::assertSame(100000, $wrongMinorUnits);

        // With JPY currency → correct zero-decimal → 1000 (CORRECT)
        $correctMinorUnits = AmountConverter::toMinorUnits(1000.0, 'JPY');
        self::assertSame(1000, $correctMinorUnits);
    }
}
