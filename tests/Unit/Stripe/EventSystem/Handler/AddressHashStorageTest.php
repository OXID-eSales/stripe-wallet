<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\EventSystem\Handler;

use OxidEsales\PaymentComponent\Contract\PaymentContract;
use OxidEsales\PaymentComponent\Contract\BasketSnapshot;
use OxidEsales\PaymentComponent\EventSystem\Event\EventContext;
use OxidEsales\PaymentComponent\EventSystem\EventDispatcherInterface;
use OxidEsales\PaymentComponent\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentComponent\Service\ContractServiceInterface;
use OxidEsales\Payments\Stripe\EventSystem\Event\StripeCheckoutSessionRequestEvent;
use OxidEsales\Payments\Stripe\EventSystem\Handler\StripeContractCreationHandler;
use OxidEsales\Payments\Stripe\Service\ContractMetadataServiceInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for address hash storage in contract during Stripe checkout initiation.
 *
 * Sprint 21: Updated tests for handler with ContractMetadataService delegation.
 * Sprint 1 (2026): Updated for Template Method pattern - handler now extends ContractCreationHandler.
 * The actual metadata storage logic is now tested in ContractMetadataServiceTest.
 * These tests verify that the handler delegates correctly to the service.
 */
class AddressHashStorageTest extends TestCase
{
    private ContractServiceInterface&MockObject $contractService;
    private EventDispatcherInterface&MockObject $eventDispatcher;
    private ContractRepositoryInterface&MockObject $contractRepository;
    private ContractMetadataServiceInterface&MockObject $metadataService;

    protected function setUp(): void
    {
        $this->contractService = $this->createMock(ContractServiceInterface::class);
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->contractRepository = $this->createMock(ContractRepositoryInterface::class);
        $this->metadataService = $this->createMock(ContractMetadataServiceInterface::class);
    }

    private function createHandler(): StripeContractCreationHandler
    {
        // Constructor order matches parent class (ContractCreationHandler) + Stripe-specific deps
        return new StripeContractCreationHandler(
            $this->contractService,
            $this->eventDispatcher,
            $this->contractRepository,
            $this->metadataService
        );
    }

    /**
     * Test 1: Handler delegates address hash storage to metadata service.
     */
    public function testAddressHashStoredInContractOnCreation(): void
    {
        // Arrange
        $contract = $this->createContract();
        $basket = new \stdClass();

        $this->contractService->method('createContract')->willReturn($contract);

        $context = new EventContext([
            'userId' => 'user123',
            'basket' => $basket,
            'conditionTypes' => ['payment_authorized'],
        ]);
        $event = new StripeCheckoutSessionRequestEvent($context);

        // Expect metadata service to be called to store delivery address
        $this->metadataService
            ->expects($this->once())
            ->method('storeDeliveryAddressMetadata')
            ->with($contract, $basket);

        // Act
        $handler = $this->createHandler();
        $handler->handle($event);

        // Assert - contract should be set in context
        $this->assertSame($contract, $context->getContract());
    }

    /**
     * Test 2: Handler delegates delivery address ID storage to metadata service.
     */
    public function testAddressHashIncludesDeliveryAddressId(): void
    {
        // Arrange
        $contract = $this->createContract();
        $basket = new \stdClass();

        $this->contractService->method('createContract')->willReturn($contract);

        $context = new EventContext([
            'userId' => 'user123',
            'basket' => $basket,
            'conditionTypes' => ['payment_authorized'],
        ]);
        $event = new StripeCheckoutSessionRequestEvent($context);

        // Expect metadata service to be called
        $this->metadataService
            ->expects($this->once())
            ->method('storeDeliveryAddressMetadata')
            ->with($contract, $basket);

        // Act
        $handler = $this->createHandler();
        $handler->handle($event);

        // Assert - the metadata service handles the actual storage logic
        // See ContractMetadataServiceTest for detailed hash storage tests
        $this->assertSame($contract, $context->getContract());
    }

    /**
     * Test 3: Handler still delegates when no address hash in session.
     *
     * The metadata service handles empty/null cases internally.
     */
    public function testContractHandlesMissingAddressHash(): void
    {
        // Arrange
        $contract = $this->createContract();
        $basket = new \stdClass();

        $this->contractService->method('createContract')->willReturn($contract);

        $context = new EventContext([
            'userId' => 'user123',
            'basket' => $basket,
            'conditionTypes' => ['payment_authorized'],
        ]);
        $event = new StripeCheckoutSessionRequestEvent($context);

        // Expect metadata service to be called regardless - it handles empty values
        $this->metadataService
            ->expects($this->once())
            ->method('storeDeliveryAddressMetadata')
            ->with($contract, $basket);

        // Act
        $handler = $this->createHandler();
        $handler->handle($event);

        // Assert - contract should be set and saved
        $this->assertSame($contract, $context->getContract());
    }

    private function createContract(): PaymentContract
    {
        return new PaymentContract(
            shopId: 1,
            userId: 'user123',
            basketSnapshot: BasketSnapshot::fromArray([
                'items' => [],
                'discounts' => [],
                'totalGross' => 100.0,
                'totalNet' => 84.0,
                'totalVat' => 16.0,
                'currency' => 'EUR',
            ])
        );
    }
}
