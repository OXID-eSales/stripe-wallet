<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Service;

use OxidEsales\Payments\Stripe\Service\WebhookUrlOwnership;
use PHPUnit\Framework\TestCase;

final class WebhookUrlOwnershipTest extends TestCase
{
    private const LEGACY = 'https://shop.example.com/index.php?cl=StripeWebhookController';

    public function testOwns_WhenUrlsAreEqual_ReturnsTrue(): void
    {
        self::assertTrue((new WebhookUrlOwnership())->owns(self::LEGACY . '&shp=2', self::LEGACY . '&shp=2'));
    }

    public function testOwns_WhenEndpointLacksShpAndTheShopIsOne_ReturnsTrue(): void
    {
        self::assertTrue((new WebhookUrlOwnership())->owns(self::LEGACY, self::LEGACY . '&shp=1'));
    }

    public function testOwns_WhenEndpointLacksShpAndTheShopIsASubshop_ReturnsFalse(): void
    {
        self::assertFalse((new WebhookUrlOwnership())->owns(self::LEGACY, self::LEGACY . '&shp=2'));
    }

    public function testOwns_WhenShpDiffers_ReturnsFalse(): void
    {
        self::assertFalse((new WebhookUrlOwnership())->owns(self::LEGACY . '&shp=1', self::LEGACY . '&shp=2'));
    }

    public function testOwns_WhenHostDiffers_ReturnsFalse(): void
    {
        self::assertFalse((new WebhookUrlOwnership())->owns(
            'https://other.example.com/index.php?cl=StripeWebhookController&shp=1',
            self::LEGACY . '&shp=1'
        ));
    }

    public function testOwns_WhenShopIsTenAndEndpointLacksShp_ReturnsFalse(): void
    {
        self::assertFalse((new WebhookUrlOwnership())->owns(self::LEGACY, self::LEGACY . '&shp=10'));
    }
}
