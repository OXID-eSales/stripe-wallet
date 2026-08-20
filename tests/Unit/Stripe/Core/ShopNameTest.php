<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Core;

use OxidEsales\Payments\Stripe\Core\ShopName;
use PHPUnit\Framework\TestCase;

#[\PHPUnit\Framework\Attributes\CoversClass(ShopName::class)]
final class ShopNameTest extends TestCase
{
    private function shopWithName(mixed $name): object
    {
        $field = new \stdClass();
        $field->value = $name;

        $shop = new \stdClass();
        $shop->oxshops__oxname = $field;

        return $shop;
    }

    public function testReturnsTheConfiguredShopName(): void
    {
        $this->assertSame('Bäckerei Süd', ShopName::of($this->shopWithName('Bäckerei Süd')));
    }

    public function testNeverInventsABrandName(): void
    {
        // The value reaches Stripe as session branding; 'OXID eShop' is not the
        // merchant's name and must not be shown to their customers.
        $this->assertSame('', ShopName::of(null));
        $this->assertSame('', ShopName::of(new \stdClass()));
        $this->assertSame('', ShopName::of($this->shopWithName(null)));
    }

    public function testTrimsSurroundingWhitespace(): void
    {
        $this->assertSame('Shop', ShopName::of($this->shopWithName('  Shop  ')));
    }
}
