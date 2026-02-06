<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Integration\Stripe\Controller\Admin;

use OxidEsales\Eshop\Application\Model\Order;
use OxidEsales\Payments\Stripe\Controller\Admin\OrderActionDispatcher;
use OxidEsales\Payments\Stripe\Controller\Admin\OrderRefund;
use OxidEsales\Payments\Stripe\Controller\Admin\OrderRefundViewDataProvider;
use OxidEsales\Payments\Stripe\EventSystem\Event\StripeRefundRequestEvent;
use OxidEsales\Payments\Stripe\Service\OrderContractResolver;
use OxidEsales\PaymentComponent\Contract\PaymentContractInterface;
use OxidEsales\PaymentComponent\EventSystem\Event\EventContext;
use OxidEsales\PaymentComponent\EventSystem\EventDispatcherInterface;
use OxidEsales\PaymentComponent\Repository\ContractRepositoryInterface;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Testable subclass that allows injecting dependencies for testing.
 * Overrides service locator methods to inject test doubles.
 */
class TestableOrderRefund extends OrderRefund
{
    private ?OrderActionDispatcher $testActionDispatcher = null;
    private ?OrderRefundViewDataProvider $testViewDataProvider = null;
    private ?Order $testOrder = null;
    private ?string $testEditObjectId = null;

    public function setTestActionDispatcher(OrderActionDispatcher $dispatcher): void
    {
        $this->testActionDispatcher = $dispatcher;
    }

    protected function getActionDispatcher(): OrderActionDispatcher
    {
        if ($this->testActionDispatcher !== null) {
            return $this->testActionDispatcher;
        }
        return parent::getActionDispatcher();
    }

    public function setTestViewDataProvider(OrderRefundViewDataProvider $provider): void
    {
        $this->testViewDataProvider = $provider;
    }

    protected function getViewDataProvider(): OrderRefundViewDataProvider
    {
        if ($this->testViewDataProvider !== null) {
            return $this->testViewDataProvider;
        }
        return parent::getViewDataProvider();
    }

    public function setTestOrder(?Order $order): void
    {
        $this->testOrder = $order;
        $this->_oOrder = $order;
    }

    public function getOrder(): ?Order
    {
        if ($this->testOrder !== null) {
            return $this->testOrder;
        }
        return parent::getOrder();
    }

    public function setTestEditObjectId(?string $id): void
    {
        $this->testEditObjectId = $id;
    }

    public function getEditObjectId(): ?string
    {
        return $this->testEditObjectId;
    }

    /**
     * Expose event context for testing.
     */
    public function getEventContext(): ?EventContext
    {
        return $this->_oEventContext;
    }
}

class OrderRefundControllerTest extends TestCase
{
    private EventDispatcherInterface&MockObject $eventDispatcher;
    private OrderRefundViewDataProvider&MockObject $viewDataProvider;
    private TestableOrderRefund $controller;

    protected function setUp(): void
    {
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);

        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getId')->willReturn('contract_test');

        $contractRepository = $this->createMock(ContractRepositoryInterface::class);
        $contractRepository->method('findByOrderId')->willReturn($contract);

        $contractResolver = new OrderContractResolver($contractRepository);
        $actionDispatcher = new OrderActionDispatcher($this->eventDispatcher, $contractResolver);

        $this->viewDataProvider = $this->createMock(OrderRefundViewDataProvider::class);

        $this->controller = new TestableOrderRefund();
        $this->controller->setTestActionDispatcher($actionDispatcher);
        $this->controller->setTestViewDataProvider($this->viewDataProvider);
    }

    public function testFullRefundEmitsStripeRefundRequestEvent(): void
    {
        // Arrange
        $order = $this->createOrderMock('order_123');
        $this->controller->setTestOrder($order);

        $capturedEvent = null;
        $this->eventDispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function ($event) use (&$capturedEvent) {
                $capturedEvent = $event;
                return $event;
            });

        // Act
        $this->controller->fullRefund();

        // Assert
        $this->assertInstanceOf(StripeRefundRequestEvent::class, $capturedEvent);
        $this->assertEquals('order_123', $capturedEvent->getOrderId());
        $this->assertNull($capturedEvent->getAmount()); // Full refund
        $this->assertEquals('admin', $capturedEvent->getInitiator());
    }

    public function testFullRefundSetsSuccessOnSuccessfulResult(): void
    {
        // Arrange
        $order = $this->createOrderMock('order_789');
        $this->controller->setTestOrder($order);

        $this->viewDataProvider->method('resetCache');

        $this->eventDispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function (StripeRefundRequestEvent $event) {
                $event->getContext()->set('refundSuccess', true);
                $event->getContext()->set('refundId', 're_test_123');
                $event->getContext()->set('refundedAmount', 100.00);
                return $event;
            });

        // Act
        $this->controller->fullRefund();

        // Assert
        $this->assertTrue($this->controller->wasRefundSuccessful());
        $this->assertEquals('re_test_123', $this->controller->getRefundId());
        $this->assertEquals(100.00, $this->controller->getRefundedAmount());
        $this->assertFalse($this->controller->getErrorMessage());
    }

    public function testFullRefundSetsErrorOnFailedResult(): void
    {
        // Arrange
        $order = $this->createOrderMock('order_error');
        $this->controller->setTestOrder($order);

        $this->eventDispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function (StripeRefundRequestEvent $event) {
                $event->getContext()->set('refundSuccess', false);
                $event->getContext()->set('error', 'Charge already refunded');
                return $event;
            });

        // Act
        $this->controller->fullRefund();

        // Assert
        $this->assertFalse($this->controller->wasRefundSuccessful());
        $this->assertEquals('Charge already refunded', $this->controller->getErrorMessage());
        $this->assertNull($this->controller->getRefundId());
    }

    public function testFullRefundSetsErrorWhenNoOrder(): void
    {
        // Arrange
        $this->controller->setTestOrder(null);
        // getOrder() returns null when testOrder is null and _oOrder is null
        // Need to ensure getOrder() returns null
        $this->controller->setTestEditObjectId(null);

        $this->eventDispatcher
            ->expects($this->never())
            ->method('dispatch');

        // Act
        $this->controller->fullRefund();

        // Assert
        $this->assertFalse($this->controller->wasRefundSuccessful());
        $this->assertNotEmpty($this->controller->getErrorMessage());
    }

    public function testEventContextContainsOrderId(): void
    {
        // Arrange
        $order = $this->createOrderMock('order_ctx_test');
        $this->controller->setTestOrder($order);

        $this->eventDispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function (StripeRefundRequestEvent $event) {
                $this->assertEquals('order_ctx_test', $event->getContext()->get('orderId'));
                return $event;
            });

        // Act
        $this->controller->fullRefund();

        // Assert - verified in callback
    }

    public function testEventContextContainsContractId(): void
    {
        // Arrange
        $order = $this->createOrderMock('order_contract');
        $this->controller->setTestOrder($order);

        $this->eventDispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function (StripeRefundRequestEvent $event) {
                $this->assertEquals('contract_test', $event->getContext()->get('contractId'));
                return $event;
            });

        // Act
        $this->controller->fullRefund();

        // Assert - verified in callback
    }

    public function testEventContextInitiatorIsAdmin(): void
    {
        // Arrange
        $order = $this->createOrderMock('order_admin');
        $this->controller->setTestOrder($order);

        $capturedEvent = null;
        $this->eventDispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function ($event) use (&$capturedEvent) {
                $capturedEvent = $event;
                return $event;
            });

        // Act
        $this->controller->fullRefund();

        // Assert
        $this->assertEquals('admin', $capturedEvent->getInitiator());
    }

    public function testControllerStoresEventContext(): void
    {
        // Arrange
        $order = $this->createOrderMock('order_store_ctx');
        $this->controller->setTestOrder($order);

        $this->viewDataProvider->method('resetCache');

        $this->eventDispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function (StripeRefundRequestEvent $event) {
                $event->getContext()->set('refundSuccess', true);
                $event->getContext()->set('customData', 'test_value');
                return $event;
            });

        // Act
        $this->controller->fullRefund();

        // Assert
        $context = $this->controller->getEventContext();
        $this->assertNotNull($context);
        $this->assertEquals('test_value', $context->get('customData'));
    }

    public function testWasRefundSuccessfulReturnsNullBeforeOperation(): void
    {
        // Assert
        $this->assertNull($this->controller->wasRefundSuccessful());
    }

    public function testGetRefundIdReturnsNullBeforeOperation(): void
    {
        // Assert
        $this->assertNull($this->controller->getRefundId());
    }

    public function testGetRefundedAmountReturnsNullBeforeOperation(): void
    {
        // Assert
        $this->assertNull($this->controller->getRefundedAmount());
    }

    // --- Helper methods ---

    private function createOrderMock(string $orderId): Order
    {
        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn($orderId);
        return $order;
    }
}
