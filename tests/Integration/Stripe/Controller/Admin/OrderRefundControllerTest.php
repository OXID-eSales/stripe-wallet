<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Integration\Stripe\Controller\Admin;

use OxidEsales\Eshop\Application\Model\Order;
use OxidEsales\Payments\Stripe\Controller\Admin\OrderRefund;
use OxidEsales\Payments\Stripe\EventSystem\Event\StripeRefundRequestEvent;
use OxidEsales\PaymentComponent\EventSystem\Event\EventContext;
use OxidEsales\PaymentComponent\EventSystem\EventDispatcherInterface;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Testable subclass that allows injecting dependencies for testing.
 * Follows LSP - return types match parent class.
 */
class TestableOrderRefund extends OrderRefund
{
    private ?EventDispatcherInterface $testEventDispatcher = null;
    private ?Order $testOrder = null;
    private ?string $testEditObjectId = null;
    private array $testRequestParams = [];

    public function setTestEventDispatcher(EventDispatcherInterface $dispatcher): void
    {
        $this->testEventDispatcher = $dispatcher;
    }

    protected function getEventDispatcher(): EventDispatcherInterface
    {
        if ($this->testEventDispatcher !== null) {
            return $this->testEventDispatcher;
        }
        return parent::getEventDispatcher();
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

    public function setTestRequestParams(array $params): void
    {
        $this->testRequestParams = $params;
    }

    protected function getRefundReasonFromRequest(): ?string
    {
        return $this->testRequestParams['refund_reason'] ?? null;
    }

    protected function getRefundDescriptionFromRequest(): ?string
    {
        return $this->testRequestParams['refund_description'] ?? null;
    }

    protected function getRefundAmountFromRequest(): ?float
    {
        $amount = $this->testRequestParams['refund_amount'] ?? null;
        return $amount !== null ? (float) $amount : null;
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
    private TestableOrderRefund $controller;

    protected function setUp(): void
    {
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->controller = new TestableOrderRefund();
        $this->controller->setTestEventDispatcher($this->eventDispatcher);
    }

    public function testFullRefundEmitsStripeRefundRequestEvent(): void
    {
        // Arrange
        $order = $this->createOrderMock('order_123');
        $this->controller->setTestOrder($order);
        $this->controller->setTestRequestParams([
            'refund_reason' => 'duplicate',
            'refund_description' => 'Test refund',
        ]);

        $capturedEvent = null;
        $this->eventDispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function ($event) use (&$capturedEvent) {
                $capturedEvent = $event;
                return $event; // Must return EventInterface
            });

        // Act
        $this->controller->fullRefund();

        // Assert
        $this->assertInstanceOf(StripeRefundRequestEvent::class, $capturedEvent);
        $this->assertEquals('order_123', $capturedEvent->getOrderId());
        $this->assertNull($capturedEvent->getAmount()); // Full refund
        $this->assertEquals('duplicate', $capturedEvent->getReason());
        $this->assertEquals('Test refund', $capturedEvent->getDescription());
        $this->assertEquals('admin', $capturedEvent->getInitiator());
    }

    public function testPartialRefundEmitsEventWithAmount(): void
    {
        // Arrange
        $order = $this->createOrderMock('order_456');
        $this->controller->setTestOrder($order);
        $this->controller->setTestRequestParams([
            'refund_amount' => 50.00,
            'refund_reason' => 'requested_by_customer',
        ]);

        $capturedEvent = null;
        $this->eventDispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function ($event) use (&$capturedEvent) {
                $capturedEvent = $event;
                return $event; // Must return EventInterface
            });

        // Act
        $this->controller->partialRefund();

        // Assert
        $this->assertEquals(50.00, $capturedEvent->getAmount());
        $this->assertFalse($capturedEvent->isFullRefund());
    }

    public function testFullRefundSetsSuccessOnSuccessfulResult(): void
    {
        // Arrange
        $order = $this->createOrderMock('order_789');
        $this->controller->setTestOrder($order);

        $this->eventDispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function (StripeRefundRequestEvent $event) {
                // Simulate handler setting success
                $event->getContext()->set('refundSuccess', true);
                $event->getContext()->set('refundId', 're_test_123');
                $event->getContext()->set('refundedAmount', 100.00);
                return $event; // Must return EventInterface
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
                // Simulate handler setting error
                $event->getContext()->set('refundSuccess', false);
                $event->getContext()->set('error', 'Charge already refunded');
                return $event; // Must return EventInterface
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

        $this->eventDispatcher
            ->expects($this->never())
            ->method('dispatch');

        // Act
        $this->controller->fullRefund();

        // Assert
        $this->assertFalse($this->controller->wasRefundSuccessful());
        $this->assertNotEmpty($this->controller->getErrorMessage());
    }

    public function testPartialRefundSetsErrorWhenAmountInvalid(): void
    {
        // Arrange
        $order = $this->createOrderMock('order_invalid');
        $this->controller->setTestOrder($order);
        $this->controller->setTestRequestParams([
            'refund_amount' => null,
        ]);

        $this->eventDispatcher
            ->expects($this->never())
            ->method('dispatch');

        // Act
        $this->controller->partialRefund();

        // Assert
        $this->assertFalse($this->controller->wasRefundSuccessful());
    }

    public function testPartialRefundSetsErrorWhenAmountZero(): void
    {
        // Arrange
        $order = $this->createOrderMock('order_zero');
        $this->controller->setTestOrder($order);
        $this->controller->setTestRequestParams([
            'refund_amount' => 0,
        ]);

        $this->eventDispatcher
            ->expects($this->never())
            ->method('dispatch');

        // Act
        $this->controller->partialRefund();

        // Assert
        $this->assertFalse($this->controller->wasRefundSuccessful());
    }

    public function testPartialRefundSetsErrorWhenAmountNegative(): void
    {
        // Arrange
        $order = $this->createOrderMock('order_negative');
        $this->controller->setTestOrder($order);
        $this->controller->setTestRequestParams([
            'refund_amount' => -10.00,
        ]);

        $this->eventDispatcher
            ->expects($this->never())
            ->method('dispatch');

        // Act
        $this->controller->partialRefund();

        // Assert
        $this->assertFalse($this->controller->wasRefundSuccessful());
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
                return $event; // Must return EventInterface
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
                return $event; // Must return EventInterface
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

        $this->eventDispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function (StripeRefundRequestEvent $event) {
                $event->getContext()->set('refundSuccess', true);
                $event->getContext()->set('customData', 'test_value');
                return $event; // Must return EventInterface
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

    /**
     * Create a mock Order object that returns the given ID.
     * Uses a proper mock implementing Order interface.
     */
    private function createOrderMock(string $orderId): Order
    {
        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn($orderId);
        return $order;
    }
}
