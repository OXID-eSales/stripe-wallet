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

    public function testReadBoundsAreBothSidesOfTheLiveIntent(): void
    {
        $order = $this->orderStub();
        $provider = $this->createStub(OrderRefundViewDataProvider::class);
        $provider->method('getPaymentIntent')->willReturn(new \OxidEsales\Payments\Stripe\Adapter\Dto\StripePaymentIntentDto('pi_1', 'requires_capture', 1000, 'eur', 1, null));
        $provider->method('getCaptureableRaw')->willReturn(10.0);
        $provider->method('getRemainingRefundableRaw')->willReturn(0.0);

        $bounds = (new StripeAdminActionBounds($provider))->readBounds($order);

        $this->assertTrue($bounds->available);
        $this->assertSame(10.0, $bounds->capturable);
        $this->assertSame(0.0, $bounds->refundable, 'an authorisation has no charge to refund yet: a real zero, not unreadable');
    }

    public function testReadBoundsAreUnavailableWhenStripeCannotBeRead(): void
    {
        $provider = $this->createStub(OrderRefundViewDataProvider::class);
        $provider->method('getPaymentIntent')->willReturn(null);

        $this->assertFalse((new StripeAdminActionBounds($provider))->readBounds($this->orderStub())->available);
    }

    public function testReadBoundsAreUnavailableWhenReadingThrows(): void
    {
        $provider = $this->createStub(OrderRefundViewDataProvider::class);
        $provider->method('getPaymentIntent')->willThrowException(new \RuntimeException('boom'));

        $this->assertFalse((new StripeAdminActionBounds($provider))->readBounds($this->orderStub())->available);
    }

    private function orderStub(): Order
    {
        return $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();
    }
}
