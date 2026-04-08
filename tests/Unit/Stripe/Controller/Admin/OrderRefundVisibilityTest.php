<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Controller\Admin;

use OxidEsales\Eshop\Application\Model\Order;
use OxidEsales\Payments\Stripe\Controller\Admin\OrderRefund;
use OxidEsales\Payments\Stripe\Controller\Admin\OrderRefundViewDataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Sprint 82: Tests that refund section is hidden for uncaptured manual-capture orders.
 *
 * An order in manual capture mode that has not been captured yet should only show
 * capture and cancel authorization actions. Refund makes no sense because there's
 * nothing to refund (no funds captured).
 *
 * @covers \OxidEsales\Payments\Stripe\Controller\Admin\OrderRefund
 * @group sprint-82
 */
final class OrderRefundVisibilityTest extends TestCase
{
    /**
     * Sprint 82: isOrderRefundable() must return false when order is capturable.
     *
     * Even if Stripe says the charge exists, you can't refund an uncaptured payment.
     */
    public function testIsOrderRefundableReturnsFalseWhenOrderIsCapturable(): void
    {
        $viewDataProvider = $this->createMock(OrderRefundViewDataProvider::class);
        $viewDataProvider->method('isOrderCapturable')->willReturn(true);
        $viewDataProvider->method('isOrderRefundable')->willReturn(true); // Stripe says refundable

        $order = $this->createMock(Order::class);

        $controller = new TestableOrderRefundForVisibility(
            order: $order,
            viewDataProvider: $viewDataProvider
        );

        $this->assertFalse(
            $controller->isOrderRefundable(),
            'Refund should be hidden when order is still capturable (not yet captured)'
        );
    }

    /**
     * Sprint 82: isOrderRefundable() returns true when order is NOT capturable and IS refundable.
     */
    public function testIsOrderRefundableReturnsTrueWhenCapturedAndRefundable(): void
    {
        $viewDataProvider = $this->createMock(OrderRefundViewDataProvider::class);
        $viewDataProvider->method('isOrderCapturable')->willReturn(false); // Already captured
        $viewDataProvider->method('isOrderRefundable')->willReturn(true);

        $order = $this->createMock(Order::class);

        $controller = new TestableOrderRefundForVisibility(
            order: $order,
            viewDataProvider: $viewDataProvider
        );

        $this->assertTrue(
            $controller->isOrderRefundable(),
            'Refund should be visible after payment has been captured'
        );
    }

    /**
     * Sprint 82: isOrderRefundable() returns false when neither capturable nor refundable.
     */
    public function testIsOrderRefundableReturnsFalseWhenNotRefundable(): void
    {
        $viewDataProvider = $this->createMock(OrderRefundViewDataProvider::class);
        $viewDataProvider->method('isOrderCapturable')->willReturn(false);
        $viewDataProvider->method('isOrderRefundable')->willReturn(false); // Already fully refunded

        $order = $this->createMock(Order::class);

        $controller = new TestableOrderRefundForVisibility(
            order: $order,
            viewDataProvider: $viewDataProvider
        );

        $this->assertFalse(
            $controller->isOrderRefundable(),
            'Refund should be hidden when order is already fully refunded'
        );
    }

    /**
     * Sprint 82: isOrderCapturable() returns true when ViewDataProvider says capturable.
     */
    public function testIsOrderCapturableReturnsTrueWhenPaymentRequiresCapture(): void
    {
        $viewDataProvider = $this->createMock(OrderRefundViewDataProvider::class);
        $viewDataProvider->method('isOrderCapturable')->willReturn(true);

        $order = $this->createMock(Order::class);

        $controller = new TestableOrderRefundForVisibility(
            order: $order,
            viewDataProvider: $viewDataProvider
        );

        $this->assertTrue($controller->isOrderCapturable());
    }
}

/**
 * Testable subclass that injects mocked dependencies without OXID framework.
 */
class TestableOrderRefundForVisibility extends OrderRefund
{
    private ?OrderRefundViewDataProvider $testViewDataProvider;
    private ?Order $testOrder;

    public function __construct(
        ?Order $order = null,
        ?OrderRefundViewDataProvider $viewDataProvider = null
    ) {
        // No parent constructor — skip OXID admin bootstrap
        $this->testOrder = $order;
        $this->testViewDataProvider = $viewDataProvider;
    }

    public function getOrder(): ?Order
    {
        return $this->testOrder;
    }

    protected function getViewDataProvider(): OrderRefundViewDataProvider
    {
        if ($this->testViewDataProvider === null) {
            throw new \LogicException('Test ViewDataProvider not set');
        }
        return $this->testViewDataProvider;
    }
}
