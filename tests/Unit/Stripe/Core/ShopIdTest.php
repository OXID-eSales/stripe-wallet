<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Core;

use OxidEsales\Payments\Stripe\Core\ShopId;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[\PHPUnit\Framework\Attributes\CoversClass(ShopId::class)]
final class ShopIdTest extends TestCase
{
    public function testResolvesNumericStringShopIds(): void
    {
        $this->assertSame(7, ShopId::of('7', 'test'));
    }

    public function testResolvesIntegerShopIds(): void
    {
        $this->assertSame(3, ShopId::of(3, 'test'));
    }

    public function testThrowsInsteadOfDefaultingToShopOne(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Refusing to assume a shop');

        ShopId::of('not-a-shop', 'test');
    }

    public function testThrowsOnNull(): void
    {
        $this->expectException(RuntimeException::class);

        ShopId::of(null, 'test');
    }

    public function testThrowsOnEmptyString(): void
    {
        $this->expectException(RuntimeException::class);

        ShopId::of('', 'test');
    }

    public function testThrowsOnZeroBecauseItIsNotAShop(): void
    {
        // A bare (int) cast produced 0 for any non-numeric value, which then
        // travelled into transaction audit rows as a real shop id.
        $this->expectException(RuntimeException::class);

        ShopId::of('0', 'test');
    }

    public function testDoesNotSilentlyTruncateAMalformedId(): void
    {
        // (int) '12abc' would be 12; that is a different shop, silently.
        $this->expectException(RuntimeException::class);

        ShopId::of('12abc', 'test');
    }
}
