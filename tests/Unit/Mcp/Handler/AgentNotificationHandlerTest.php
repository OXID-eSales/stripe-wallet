<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Mcp\Handler;

use OxidEsales\PaymentComponent\Contract\PaymentContractInterface;
use OxidEsales\PaymentComponent\EventSystem\Event\Contract\ContractCancelledEvent;
use OxidEsales\PaymentComponent\EventSystem\Event\Contract\ContractCommittedEvent;
use OxidEsales\PaymentComponent\EventSystem\Event\Contract\ContractFailedEvent;
use OxidEsales\PaymentComponent\EventSystem\Event\Contract\ContractFulfilledEvent;
use OxidEsales\PaymentComponent\EventSystem\Event\EventContextInterface;
use OxidEsales\PaymentComponent\EventSystem\Handler\HandlerInterface;
use OxidEsales\PaymentComponent\Mcp\Handler\AgentNotificationHandler;
use OxidEsales\PaymentComponent\Mcp\Notification\AgentNotificationPayload;
use OxidEsales\PaymentComponent\Mcp\Notification\AgentNotificationServiceInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OxidEsales\PaymentComponent\Mcp\Handler\AgentNotificationHandler
 * @group sprint-50
 * @group mcp
 * @group handler
 */
final class AgentNotificationHandlerTest extends TestCase
{
    private AgentNotificationServiceInterface&MockObject $notificationService;
    private AgentNotificationHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->notificationService = $this->createMock(AgentNotificationServiceInterface::class);
        $this->handler = new AgentNotificationHandler($this->notificationService);
    }

    /**
     * @test
     */
    public function implementsHandlerInterface(): void
    {
        $this->assertInstanceOf(HandlerInterface::class, $this->handler);
    }

    /**
     * @test
     */
    public function getHandledEventClassReturnsContractCommittedEvent(): void
    {
        $this->assertSame(ContractCommittedEvent::class, AgentNotificationHandler::getHandledEventClass());
    }

    /**
     * @test
     */
    public function skipsNonAgentContracts(): void
    {
        // Arrange
        $contract = $this->createContractMock('contract_123', null);
        $event = $this->createCommittedEvent($contract, 'order_abc');

        // Assert - should NOT call notify
        $this->notificationService
            ->expects($this->never())
            ->method('notify');

        // Act
        $this->handler->handle($event);
    }

    /**
     * @test
     */
    public function notifiesOnContractCommittedForAgentContract(): void
    {
        // Arrange
        $contract = $this->createContractMock('contract_456', 'agent_001', 'order_def');
        $event = $this->createCommittedEvent($contract, 'order_def');

        // Assert
        $this->notificationService
            ->expects($this->once())
            ->method('notify')
            ->with(
                'contract_456',
                $this->callback(function (AgentNotificationPayload $payload): bool {
                    $array = $payload->toArray();
                    return $payload->getEventType() === 'order.created'
                        && $array['status'] === 'created'
                        && $array['order']['id'] === 'order_def';
                })
            );

        // Act
        $this->handler->handle($event);
    }

    /**
     * @test
     */
    public function notifiesOnContractFulfilledForAgentContract(): void
    {
        // Arrange
        $contract = $this->createContractMock('contract_789', 'agent_002', 'order_ghi');
        $context = $this->createMock(EventContextInterface::class);
        $event = new ContractFulfilledEvent($contract, $context, 'order_ghi');

        // Assert
        $this->notificationService
            ->expects($this->once())
            ->method('notify')
            ->with(
                'contract_789',
                $this->callback(function (AgentNotificationPayload $payload): bool {
                    $array = $payload->toArray();
                    return $payload->getEventType() === 'order.fulfilled'
                        && $array['status'] === 'fulfilled'
                        && $array['order']['id'] === 'order_ghi';
                })
            );

        // Act
        $this->handler->handle($event);
    }

    /**
     * @test
     */
    public function notifiesOnContractCancelledForAgentContract(): void
    {
        // Arrange
        $contract = $this->createContractMock('contract_cancel', 'agent_003');
        $context = $this->createMock(EventContextInterface::class);
        $event = new ContractCancelledEvent($contract, $context, 'User requested cancellation');

        // Assert
        $this->notificationService
            ->expects($this->once())
            ->method('notify')
            ->with(
                'contract_cancel',
                $this->callback(function (AgentNotificationPayload $payload): bool {
                    return $payload->getEventType() === 'order.canceled'
                        && $payload->toArray()['status'] === 'canceled';
                })
            );

        // Act
        $this->handler->handle($event);
    }

    /**
     * @test
     */
    public function notifiesOnContractFailedForAgentContract(): void
    {
        // Arrange
        $contract = $this->createContractMock('contract_fail', 'agent_004');
        $context = $this->createMock(EventContextInterface::class);
        $event = new ContractFailedEvent($contract, $context, 'payment_error', 'Card declined');

        // Assert
        $this->notificationService
            ->expects($this->once())
            ->method('notify')
            ->with(
                'contract_fail',
                $this->callback(function (AgentNotificationPayload $payload): bool {
                    return $payload->getEventType() === 'order.failed'
                        && $payload->toArray()['status'] === 'canceled';
                })
            );

        // Act
        $this->handler->handle($event);
    }

    /**
     * @test
     */
    public function skipsWhenContractIdIsNull(): void
    {
        // Arrange
        $contract = $this->createContractMock(null, 'agent_005');
        $event = $this->createCommittedEvent($contract, 'order_xyz');

        // Assert
        $this->notificationService
            ->expects($this->never())
            ->method('notify');

        // Act
        $this->handler->handle($event);
    }

    /**
     * @test
     */
    public function skipsWhenEventHasNoGetContractMethod(): void
    {
        // Arrange - a generic object without getContract()
        $event = new \stdClass();

        // Assert
        $this->notificationService
            ->expects($this->never())
            ->method('notify');

        // Act
        $this->handler->handle($event);
    }

    /**
     * @test
     */
    public function committedEventMapsToOrderCreatedNotificationType(): void
    {
        // Arrange
        $contract = $this->createContractMock('contract_map1', 'agent_map', 'order_map1');
        $event = $this->createCommittedEvent($contract, 'order_map1');

        // Assert
        $this->notificationService
            ->expects($this->once())
            ->method('notify')
            ->with(
                $this->anything(),
                $this->callback(fn(AgentNotificationPayload $p) => $p->getEventType() === 'order.created')
            );

        // Act
        $this->handler->handle($event);
    }

    /**
     * @test
     */
    public function fulfilledEventMapsToOrderFulfilledNotificationType(): void
    {
        // Arrange
        $contract = $this->createContractMock('contract_map2', 'agent_map', 'order_map2');
        $context = $this->createMock(EventContextInterface::class);
        $event = new ContractFulfilledEvent($contract, $context, 'order_map2');

        // Assert
        $this->notificationService
            ->expects($this->once())
            ->method('notify')
            ->with(
                $this->anything(),
                $this->callback(fn(AgentNotificationPayload $p) => $p->getEventType() === 'order.fulfilled')
            );

        // Act
        $this->handler->handle($event);
    }

    /**
     * @test
     */
    public function cancelledEventMapsToOrderCanceledNotificationType(): void
    {
        // Arrange
        $contract = $this->createContractMock('contract_map3', 'agent_map');
        $context = $this->createMock(EventContextInterface::class);
        $event = new ContractCancelledEvent($contract, $context, 'timeout');

        // Assert
        $this->notificationService
            ->expects($this->once())
            ->method('notify')
            ->with(
                $this->anything(),
                $this->callback(fn(AgentNotificationPayload $p) => $p->getEventType() === 'order.canceled')
            );

        // Act
        $this->handler->handle($event);
    }

    /**
     * @test
     */
    public function failedEventMapsToOrderFailedNotificationType(): void
    {
        // Arrange
        $contract = $this->createContractMock('contract_map4', 'agent_map');
        $context = $this->createMock(EventContextInterface::class);
        $event = new ContractFailedEvent($contract, $context, 'err_code', 'Payment failed');

        // Assert
        $this->notificationService
            ->expects($this->once())
            ->method('notify')
            ->with(
                $this->anything(),
                $this->callback(fn(AgentNotificationPayload $p) => $p->getEventType() === 'order.failed')
            );

        // Act
        $this->handler->handle($event);
    }

    private function createContractMock(
        ?string $id,
        ?string $agentId,
        ?string $orderId = null
    ): PaymentContractInterface&MockObject {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getId')->willReturn($id);
        $contract->method('getOrderId')->willReturn($orderId);
        $contract->method('getMetadata')
            ->willReturnCallback(function (string $key) use ($agentId): mixed {
                if ($key === 'acp_agent_id') {
                    return $agentId;
                }
                return null;
            });

        return $contract;
    }

    private function createCommittedEvent(
        PaymentContractInterface $contract,
        string $orderId
    ): ContractCommittedEvent {
        $context = $this->createMock(EventContextInterface::class);
        return new ContractCommittedEvent($contract, $context, $orderId);
    }
}
