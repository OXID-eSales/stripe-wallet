<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Unit\Component\EventSystem\Event\Contract;

use PHPUnit\Framework\TestCase;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\Contract\ContractFulfilledEvent;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\Contract\ContractFulfilledEventInterface;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\Contract\ContractEventInterface;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\EventInterface;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\EventContext;
use OxidSolutionCatalysts\Payments\Component\Contract\PaymentContractInterface;

final class ContractFulfilledEventTest extends TestCase
{
    private PaymentContractInterface $contract;
    private EventContext $context;

    protected function setUp(): void
    {
        $this->contract = $this->createMock(PaymentContractInterface::class);
        $this->contract->method('getId')->willReturn('contract_123');
        $this->contract->method('getStateValue')->willReturn('fulfilled');

        $this->context = new EventContext(['userId' => 'user_456']);
    }

    public function testImplementsContractFulfilledEventInterface(): void
    {
        $event = new ContractFulfilledEvent($this->contract, $this->context, 'order_789');

        $this->assertInstanceOf(ContractFulfilledEventInterface::class, $event);
    }

    public function testImplementsContractEventInterface(): void
    {
        $event = new ContractFulfilledEvent($this->contract, $this->context, 'order_789');

        $this->assertInstanceOf(ContractEventInterface::class, $event);
    }

    public function testImplementsEventInterface(): void
    {
        $event = new ContractFulfilledEvent($this->contract, $this->context, 'order_789');

        $this->assertInstanceOf(EventInterface::class, $event);
    }

    public function testGetContract_ReturnsContract(): void
    {
        $event = new ContractFulfilledEvent($this->contract, $this->context, 'order_789');

        $this->assertSame($this->contract, $event->getContract());
    }

    public function testGetContext_ReturnsContext(): void
    {
        $event = new ContractFulfilledEvent($this->contract, $this->context, 'order_789');

        $this->assertSame($this->context, $event->getContext());
    }

    public function testGetContractId_ReturnsIdFromContract(): void
    {
        $event = new ContractFulfilledEvent($this->contract, $this->context, 'order_789');

        $this->assertEquals('contract_123', $event->getContractId());
    }

    public function testGetContractState_ReturnsStateFromContract(): void
    {
        $event = new ContractFulfilledEvent($this->contract, $this->context, 'order_789');

        $this->assertEquals('fulfilled', $event->getContractState());
    }

    public function testGetOrderId_ReturnsOrderId(): void
    {
        $event = new ContractFulfilledEvent($this->contract, $this->context, 'order_789');

        $this->assertEquals('order_789', $event->getOrderId());
    }

    public function testEvent_IsImmutable(): void
    {
        $event = new ContractFulfilledEvent($this->contract, $this->context, 'order_789');

        $this->assertFalse(method_exists($event, 'setContract'));
        $this->assertFalse(method_exists($event, 'setContext'));
        $this->assertFalse(method_exists($event, 'setOrderId'));
    }

    public function testConstructor_UsesReadonlyProperties(): void
    {
        $event = new ContractFulfilledEvent($this->contract, $this->context, 'order_789');

        $reflection = new \ReflectionClass($event);
        $this->assertTrue($reflection->getProperty('contract')->isReadOnly());
        $this->assertTrue($reflection->getProperty('context')->isReadOnly());
        $this->assertTrue($reflection->getProperty('orderId')->isReadOnly());
    }
}
