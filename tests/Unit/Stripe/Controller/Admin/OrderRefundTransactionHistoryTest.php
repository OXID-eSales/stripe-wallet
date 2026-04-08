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
 * Sprint 83/86: Tests for getTransactions() on OrderRefund controller.
 *
 * @covers \OxidEsales\Payments\Stripe\Controller\Admin\OrderRefund
 * @group sprint-83
 */
final class OrderRefundTransactionHistoryTest extends TestCase
{
    /**
     * getTransactions() returns Stripe API data when order exists.
     */
    public function testGetTransactionsReturnsStripeApiData(): void
    {
        $txData = [
            ['type' => 'authorization', 'status' => 'completed', 'amount' => 130.39],
            ['type' => 'capture', 'status' => 'completed', 'amount' => 100.00],
            ['type' => 'refund', 'status' => 'succeeded', 'amount' => 10.00],
        ];

        $order = $this->createMock(Order::class);

        $viewDataProvider = $this->getMockBuilder(OrderRefundViewDataProvider::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getStripeTransactionHistory'])
            ->getMock();
        $viewDataProvider
            ->method('getStripeTransactionHistory')
            ->with($order)
            ->willReturn($txData);

        $controller = new TestableOrderRefundForTransactions(
            order: $order,
            viewDataProvider: $viewDataProvider
        );

        $result = $controller->getTransactions();

        $this->assertCount(3, $result);
        $this->assertEquals('authorization', $result[0]['type']);
        $this->assertEquals('capture', $result[1]['type']);
        $this->assertEquals('refund', $result[2]['type']);
    }

    /**
     * getTransactions() returns empty array when no order.
     */
    public function testGetTransactionsReturnsEmptyArrayWhenNoOrder(): void
    {
        $viewDataProvider = $this->getMockBuilder(OrderRefundViewDataProvider::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getStripeTransactionHistory'])
            ->getMock();
        $viewDataProvider
            ->expects($this->never())
            ->method('getStripeTransactionHistory');

        $controller = new TestableOrderRefundForTransactions(
            order: null,
            viewDataProvider: $viewDataProvider
        );

        $result = $controller->getTransactions();

        $this->assertSame([], $result);
    }
}

/**
 * Testable subclass — bypasses OXID admin bootstrap.
 */
class TestableOrderRefundForTransactions extends OrderRefund
{
    private ?OrderRefundViewDataProvider $testViewDataProvider;
    private ?Order $testOrder;

    public function __construct(
        ?Order $order = null,
        ?OrderRefundViewDataProvider $viewDataProvider = null
    ) {
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
