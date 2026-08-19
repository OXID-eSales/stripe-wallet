<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Core;

use OxidEsales\Payments\Stripe\Core\ShopCurrency;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[\PHPUnit\Framework\Attributes\CoversClass(ShopCurrency::class)]
final class ShopCurrencyTest extends TestCase
{
    public function testReturnsTheConfiguredCurrencyName(): void
    {
        $currency = new \stdClass();
        $currency->name = 'CHF';

        $this->assertSame('CHF', ShopCurrency::nameOf($currency, 'test'));
    }

    public function testThrowsWhenCurrencyObjectIsMissing(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Refusing to assume a currency');

        ShopCurrency::nameOf(null, 'test');
    }

    public function testThrowsWhenNameIsMissing(): void
    {
        $this->expectException(RuntimeException::class);

        ShopCurrency::nameOf(new \stdClass(), 'test');
    }

    public function testThrowsWhenNameIsEmpty(): void
    {
        $currency = new \stdClass();
        $currency->name = '';

        $this->expectException(RuntimeException::class);

        ShopCurrency::nameOf($currency, 'test');
    }

    public function testNeverSubstitutesEur(): void
    {
        // The whole point of Story 7: no code path may produce 'EUR' for a shop
        // whose currency could not be determined.
        try {
            ShopCurrency::nameOf(null, 'test');
            $this->fail('Expected a RuntimeException.');
        } catch (RuntimeException $e) {
            $this->assertStringNotContainsString('EUR', $e->getMessage());
        }
    }

    public function testDisplayOnlyResolutionReturnsEmptyInsteadOfGuessing(): void
    {
        $this->assertSame('', ShopCurrency::nameOrEmpty(null));
        $this->assertSame('', ShopCurrency::nameOrEmpty(new \stdClass()));
    }
}
