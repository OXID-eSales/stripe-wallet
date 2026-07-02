<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Admin;

use OxidEsales\Eshop\Application\Model\Order;
use OxidEsales\Payments\Stripe\Admin\AdminActionBoundsInterface;
use OxidEsales\Payments\Stripe\Admin\StripeAdminActionBounds;
use OxidEsales\Payments\Stripe\Controller\Admin\OrderRefundViewDataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Sprint 121 Phase B (STRP-129): bounds delegate to the same PI/charge-derived
 * provider methods the panel view data and form `max` attributes use.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\OxidEsales\Payments\Stripe\Admin\StripeAdminActionBounds::class)]
#[\PHPUnit\Framework\Attributes\Group('sprint-121')]
final class StripeAdminActionBoundsTest extends TestCase
{
    public function testImplementsInterface(): void
    {
        $this->assertInstanceOf(
            AdminActionBoundsInterface::class,
            new StripeAdminActionBounds($this->createMock(OrderRefundViewDataProvider::class))
        );
    }

    public function testCaptureBoundDelegatesToCaptureableRaw(): void
    {
        $order = $this->orderStub();

        $provider = $this->createMock(OrderRefundViewDataProvider::class);
        $provider->expects(self::once())->method('getCaptureableRaw')->with($order)->willReturn(123.45);
        $provider->expects(self::never())->method('getRemainingRefundableRaw');

        $this->assertSame(123.45, (new StripeAdminActionBounds($provider))->captureBound($order));
    }

    public function testRefundBoundDelegatesToRemainingRefundableRaw(): void
    {
        $order = $this->orderStub();

        $provider = $this->createMock(OrderRefundViewDataProvider::class);
        $provider->expects(self::once())->method('getRemainingRefundableRaw')->with($order)->willReturn(67.89);
        $provider->expects(self::never())->method('getCaptureableRaw');

        $this->assertSame(67.89, (new StripeAdminActionBounds($provider))->refundBound($order));
    }

    private function orderStub(): Order
    {
        return $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();
    }
}
