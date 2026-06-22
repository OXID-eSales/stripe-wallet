<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Service;

use OxidEsales\Payments\Stripe\Service\CapturableAmount;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(CapturableAmount::class)]
class CapturableAmountTest extends TestCase
{
    /**
     * @return array<string, array{float, float|null, float}>
     */
    public static function remainingProvider(): array
    {
        return [
            'nothing captured yet'  => [100.00, null, 100.00],
            'zero captured'         => [100.00, 0.0, 100.00],
            'partially captured'    => [100.00, 40.00, 60.00],
            'fully captured'        => [100.00, 100.00, 0.00],
            'reconciliation skew'   => [100.00, 120.00, -20.00],
        ];
    }

    #[DataProvider('remainingProvider')]
    public function testRemaining(float $authorized, ?float $captured, float $expected): void
    {
        $this->assertEqualsWithDelta($expected, CapturableAmount::remaining($authorized, $captured), 1e-9);
    }

    /**
     * @return array<string, array{float, float, float|null, bool}>
     */
    public static function exceededProvider(): array
    {
        return [
            'within remaining'          => [60.00, 100.00, 40.00, false],
            'exactly remaining'         => [60.00, 100.00, 40.00, false],
            'full authorized, none cap' => [100.00, 100.00, null, false],
            'sub-cent over (drift)'     => [60.004, 100.00, 40.00, false],
            'one cent over'             => [60.01, 100.00, 40.00, true],
            'far over'                  => [200.00, 100.00, 40.00, true],
            'over an exhausted hold'    => [0.01, 100.00, 100.00, true],
        ];
    }

    #[DataProvider('exceededProvider')]
    public function testIsExceededBy(
        float $requested,
        float $authorized,
        ?float $captured,
        bool $expected
    ): void {
        $this->assertSame($expected, CapturableAmount::isExceededBy($requested, $authorized, $captured));
    }

    public function testExactlyRemainingIsNotExceeded(): void
    {
        // The whole point of the epsilon: a capture for exactly the remaining
        // amount must never be rejected as an over-capture.
        $this->assertFalse(CapturableAmount::isExceededBy(40.00, 100.00, 60.00));
    }
}
