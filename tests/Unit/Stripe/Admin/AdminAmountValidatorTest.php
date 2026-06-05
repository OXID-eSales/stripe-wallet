<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Admin;

use OxidEsales\Payments\Stripe\Admin\AdminAmountValidator;
use OxidEsales\Payments\Stripe\Admin\AmountValidationResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Sprint 121 Phase A (STRP-129): semantic validation of admin capture/refund
 * amount inputs.
 *
 * The contract that kills the silent full-capture footgun:
 *   - absent (null / '')  -> ok(null)   = full capture/refund, legitimate
 *   - present but malformed -> failure  = NEVER degrades to null
 *
 * Pure function over its inputs — no mocks anywhere in this suite.
 *
 * @covers \OxidEsales\Payments\Stripe\Admin\AdminAmountValidator
 * @covers \OxidEsales\Payments\Stripe\Admin\AmountValidationResult
 * @group sprint-121
 */
final class AdminAmountValidatorTest extends TestCase
{
    private AdminAmountValidator $sut;

    protected function setUp(): void
    {
        $this->sut = new AdminAmountValidator();
    }

    // A1 — absent means full action, preserved.
    #[DataProvider('absentInputs')]
    public function testAbsentInputIsOkWithNullAmount(mixed $raw): void
    {
        $result = $this->sut->validate($raw, 100.00, 'EUR');

        $this->assertTrue($result->isOk());
        $this->assertNull($result->amount);
    }

    /** @return iterable<string, array{mixed}> */
    public static function absentInputs(): iterable
    {
        yield 'null'         => [null];
        yield 'empty string' => [''];
    }

    // A2 — both decimal separators accepted.
    #[DataProvider('validAmounts')]
    public function testValidAmountParsesToFloat(mixed $raw, float $expected): void
    {
        $result = $this->sut->validate($raw, 100.00, 'EUR');

        $this->assertTrue($result->isOk());
        $this->assertSame($expected, $result->amount);
    }

    /** @return iterable<string, array{mixed, float}> */
    public static function validAmounts(): iterable
    {
        yield 'integer string'  => ['50', 50.0];
        yield 'dot decimal'     => ['50.00', 50.0];
        yield 'comma decimal'   => ['50,00', 50.0];
        yield 'one decimal'     => ['12.5', 12.5];
        yield 'native int'      => [50, 50.0];
        yield 'native float'    => [12.5, 12.5];
    }

    // A3 — boundary inclusive.
    public function testAmountEqualToBoundIsOk(): void
    {
        $result = $this->sut->validate('100.00', 100.00, 'EUR');

        $this->assertTrue($result->isOk());
        $this->assertSame(100.0, $result->amount);
    }

    // A4 — the footgun killers: malformed input is a FAILURE, never null.
    #[DataProvider('malformedInputs')]
    public function testMalformedInputFailsInsteadOfDegradingToFullCapture(mixed $raw): void
    {
        $result = $this->sut->validate($raw, 100.00, 'EUR');

        $this->assertFalse($result->isOk());
        $this->assertSame(AmountValidationResult::CODE_MALFORMED, $result->code);
        $this->assertNull($result->amount);
    }

    /** @return iterable<string, array{mixed}> */
    public static function malformedInputs(): iterable
    {
        yield 'letters'                  => ['abc'];
        yield 'currency suffix'          => ['12,30 EUR'];
        yield 'german thousands'         => ['1.234,50'];
        yield 'double dot'               => ['12.3.4'];
        yield 'exponent'                 => ['1e3'];
        yield 'leading space'            => [' 50'];
        yield 'trailing space'           => ['50 '];
        yield 'inner space'              => ['5 0'];
        yield 'plus sign'                => ['+5'];
        yield 'lone separator'           => ['.'];
        yield 'separator without digits' => ['50.'];
    }

    // A5 — non-positive amounts.
    #[DataProvider('nonPositiveInputs')]
    public function testNonPositiveAmountFails(string $raw): void
    {
        $result = $this->sut->validate($raw, 100.00, 'EUR');

        $this->assertFalse($result->isOk());
        $this->assertSame(AmountValidationResult::CODE_NOT_POSITIVE, $result->code);
    }

    /** @return iterable<string, array{string}> */
    public static function nonPositiveInputs(): iterable
    {
        yield 'negative'      => ['-5'];
        yield 'zero'          => ['0'];
        yield 'zero decimal'  => ['0.00'];
        yield 'zero comma'    => ['0,0'];
    }

    // A6 — currency-aware precision.
    public function testTooManyDecimalsForEurFails(): void
    {
        $result = $this->sut->validate('10.123', 100.00, 'EUR');

        $this->assertFalse($result->isOk());
        $this->assertSame(AmountValidationResult::CODE_PRECISION, $result->code);
    }

    public function testAnyDecimalsForZeroDecimalCurrencyFails(): void
    {
        $result = $this->sut->validate('100.5', 1000.00, 'JPY');

        $this->assertFalse($result->isOk());
        $this->assertSame(AmountValidationResult::CODE_PRECISION, $result->code);
    }

    public function testWholeAmountForZeroDecimalCurrencyIsOk(): void
    {
        $result = $this->sut->validate('100', 1000.00, 'JPY');

        $this->assertTrue($result->isOk());
        $this->assertSame(100.0, $result->amount);
    }

    // A7 — over bound.
    public function testAmountAboveBoundFails(): void
    {
        $result = $this->sut->validate('100.01', 100.00, 'EUR');

        $this->assertFalse($result->isOk());
        $this->assertSame(AmountValidationResult::CODE_EXCEEDS_BOUND, $result->code);
    }

    // A8 — IEEE-754 boundary drift: comparison happens in minor units.
    public function testFloatDriftAtTheBoundDoesNotFalseReject(): void
    {
        $result = $this->sut->validate('100.10', 100.10, 'EUR');

        $this->assertTrue($result->isOk(), 'minor-units comparison must not false-reject at the bound');
    }

    // A9 — junk types fail safely.
    #[DataProvider('junkTypeInputs')]
    public function testNonScalarInputFailsWithoutTypeError(mixed $raw): void
    {
        $result = $this->sut->validate($raw, 100.00, 'EUR');

        $this->assertFalse($result->isOk());
        $this->assertSame(AmountValidationResult::CODE_MALFORMED, $result->code);
    }

    /** @return iterable<string, array{mixed}> */
    public static function junkTypeInputs(): iterable
    {
        yield 'array'  => [[['nested']]];
        yield 'object' => [new \stdClass()];
        yield 'bool'   => [true];
    }

    // VO contract.
    public function testFailureResultCarriesNoAmount(): void
    {
        $result = AmountValidationResult::failure(AmountValidationResult::CODE_MALFORMED);

        $this->assertFalse($result->isOk());
        $this->assertNull($result->amount);
        $this->assertSame(AmountValidationResult::CODE_MALFORMED, $result->code);
    }

    public function testOkResultCarriesNoCode(): void
    {
        $result = AmountValidationResult::ok(12.5);

        $this->assertTrue($result->isOk());
        $this->assertSame(12.5, $result->amount);
        $this->assertNull($result->code);
    }
}
