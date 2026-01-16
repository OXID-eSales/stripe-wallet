<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\EventSystem\Handler;

use OxidEsales\Payments\Stripe\EventSystem\Handler\StripeContractCreationHandler;
use OxidEsales\Payments\Stripe\EventSystem\Event\StripeCheckoutSessionRequestEvent;
use OxidEsales\Payments\Stripe\Service\ContractMetadataServiceInterface;
use OxidEsales\PaymentComponent\EventSystem\Event\EventContext;
use OxidEsales\PaymentComponent\EventSystem\EventDispatcherInterface;
use OxidEsales\PaymentComponent\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentComponent\Service\ContractServiceInterface;
use OxidEsales\PaymentComponent\Contract\PaymentContractInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for StripeContractCreationHandler.
 *
 * Sprint 21: Refactored tests for handler with ContractMetadataService delegation.
 *
 * @covers \OxidEsales\Payments\Stripe\EventSystem\Handler\StripeContractCreationHandler
 */
class StripeContractCreationHandlerTest extends TestCase
{
    private ContractServiceInterface&MockObject $contractService;
    private ContractRepositoryInterface&MockObject $contractRepository;
    private ContractMetadataServiceInterface&MockObject $metadataService;
    private EventDispatcherInterface&MockObject $eventDispatcher;

    protected function setUp(): void
    {
        $this->contractService = $this->createMock(ContractServiceInterface::class);
        $this->contractRepository = $this->createMock(ContractRepositoryInterface::class);
        $this->metadataService = $this->createMock(ContractMetadataServiceInterface::class);
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
    }

    private function createHandler(): StripeContractCreationHandler
    {
        return new StripeContractCreationHandler(
            $this->contractService,
            $this->contractRepository,
            $this->metadataService,
            $this->eventDispatcher
        );
    }

    // =========================================================================
    // Handler Delegation Tests (Sprint 21)
    // =========================================================================

    public function testDelegatesDeliveryAddressMetadataToService(): void
    {
        // Arrange
        $contract = $this->createMockContract();
        $this->setupContractService($contract);

        $basket = $this->createMock(\stdClass::class);
        $context = $this->createCheckoutContext($basket);
        $event = new StripeCheckoutSessionRequestEvent($context);

        // Expect metadata service to be called
        $this->metadataService
            ->expects($this->once())
            ->method('storeDeliveryAddressMetadata')
            ->with($contract, $basket);

        // Act
        $handler = $this->createHandler();
        $handler->handle($event);
    }

    public function testDelegatesSecurityMetadataToService(): void
    {
        // Arrange
        $contract = $this->createMockContract();
        $this->setupContractService($contract);

        $basket = $this->createMock(\stdClass::class);
        $context = $this->createCheckoutContext($basket);
        $event = new StripeCheckoutSessionRequestEvent($context);

        // Expect metadata service to be called with context
        $this->metadataService
            ->expects($this->once())
            ->method('storeSecurityMetadata')
            ->with($contract, $context);

        // Act
        $handler = $this->createHandler();
        $handler->handle($event);
    }

    public function testSavesContractAfterMetadataIsStored(): void
    {
        // Arrange
        $contract = $this->createMockContract();
        $this->setupContractService($contract);

        $basket = $this->createMock(\stdClass::class);
        $context = $this->createCheckoutContext($basket);
        $event = new StripeCheckoutSessionRequestEvent($context);

        // Expect contract to be saved
        $this->contractRepository
            ->expects($this->once())
            ->method('save')
            ->with($contract);

        // Act
        $handler = $this->createHandler();
        $handler->handle($event);
    }

    public function testSetsContractInContext(): void
    {
        // Arrange
        $contract = $this->createMockContract();
        $this->setupContractService($contract);

        $basket = $this->createMock(\stdClass::class);
        $context = $this->createCheckoutContext($basket);
        $event = new StripeCheckoutSessionRequestEvent($context);

        // Act
        $handler = $this->createHandler();
        $handler->handle($event);

        // Assert
        $this->assertSame($contract, $context->getContract());
    }

    public function testSetsContractIdInContext(): void
    {
        // Arrange
        $contractId = 'contract_test_123';
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getId')->willReturn($contractId);

        $this->contractService
            ->method('createContract')
            ->willReturn($contract);

        $basket = $this->createMock(\stdClass::class);
        $context = $this->createCheckoutContext($basket);
        $event = new StripeCheckoutSessionRequestEvent($context);

        // Act
        $handler = $this->createHandler();
        $handler->handle($event);

        // Assert
        $this->assertEquals($contractId, $context->get('contractId'));
    }

    public function testSkipsIfContractAlreadyExists(): void
    {
        // Arrange
        $existingContract = $this->createMock(PaymentContractInterface::class);

        $basket = $this->createMock(\stdClass::class);
        $context = $this->createCheckoutContext($basket);
        $context->setContract($existingContract);
        $event = new StripeCheckoutSessionRequestEvent($context);

        // Should NOT create a new contract
        $this->contractService
            ->expects($this->never())
            ->method('createContract');

        // Act
        $handler = $this->createHandler();
        $handler->handle($event);
    }

    public function testThrowsExceptionWhenUserIdMissing(): void
    {
        // Arrange
        $basket = $this->createMock(\stdClass::class);
        $context = new EventContext([
            'basket' => $basket,
            // userId is missing
        ]);
        $event = new StripeCheckoutSessionRequestEvent($context);

        // Expect
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('User ID is required');

        // Act
        $handler = $this->createHandler();
        $handler->handle($event);
    }

    public function testThrowsExceptionWhenBasketMissing(): void
    {
        // Arrange
        $context = new EventContext([
            'userId' => 'user_123',
            // basket is missing
        ]);
        $event = new StripeCheckoutSessionRequestEvent($context);

        // Expect
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Basket is required');

        // Act
        $handler = $this->createHandler();
        $handler->handle($event);
    }

    public function testHandlerIgnoresNonStripeCheckoutSessionRequestEvent(): void
    {
        // Arrange
        $otherEvent = new class {
            public function getContext(): EventContext
            {
                return new EventContext([]);
            }
        };

        // Should NOT interact with services
        $this->contractService->expects($this->never())->method('createContract');
        $this->metadataService->expects($this->never())->method('storeDeliveryAddressMetadata');

        // Act
        $handler = $this->createHandler();
        $handler->handle($otherEvent);
    }

    public function testReturnsCorrectHandledEventClass(): void
    {
        $this->assertEquals(
            StripeCheckoutSessionRequestEvent::class,
            StripeContractCreationHandler::getHandledEventClass()
        );
    }

    public function testHasHighPriority(): void
    {
        $handler = $this->createHandler();

        // Priority 100 ensures it runs before StripeCheckoutSessionHandler (priority 0)
        $this->assertEquals(100, $handler->getPriority());
    }

    public function testPassesConditionTypesToContractService(): void
    {
        // Arrange
        $contract = $this->createMockContract();
        $basket = $this->createMock(\stdClass::class);

        $conditionTypes = ['payment_authorized', 'fraud_check'];
        $context = new EventContext([
            'userId' => 'user_123',
            'basket' => $basket,
            'conditionTypes' => $conditionTypes,
        ]);
        $event = new StripeCheckoutSessionRequestEvent($context);

        // Expect contract service to receive condition types
        $this->contractService
            ->expects($this->once())
            ->method('createContract')
            ->with('user_123', $basket, $conditionTypes)
            ->willReturn($contract);

        // Act
        $handler = $this->createHandler();
        $handler->handle($event);
    }

    // =========================================================================
    // Helper Methods
    // =========================================================================

    private function createMockContract(): PaymentContractInterface&MockObject
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getId')->willReturn('contract_' . uniqid());
        return $contract;
    }

    private function setupContractService(PaymentContractInterface $contract): void
    {
        $this->contractService
            ->method('createContract')
            ->willReturn($contract);
    }

    private function createCheckoutContext(object $basket): EventContext
    {
        return new EventContext([
            'userId' => 'user_123',
            'basket' => $basket,
            'conditionTypes' => [],
        ]);
    }
}
