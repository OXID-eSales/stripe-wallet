<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Unit\Stripe\EventSystem\Handler;

use OxidEsales\Eshop\Application\Model\Basket;
use OxidEsales\Eshop\Application\Model\User;
use OxidEsales\Eshop\Core\Registry;
use OxidEsales\Eshop\Core\Session;
use OxidSolutionCatalysts\Payments\Component\Contract\PaymentContract;
use OxidSolutionCatalysts\Payments\Component\Contract\BasketSnapshot;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\EventContext;
use OxidSolutionCatalysts\Payments\Component\Repository\ContractRepositoryInterface;
use OxidSolutionCatalysts\Payments\Component\Service\ContractServiceInterface;
use OxidSolutionCatalysts\Payments\Stripe\EventSystem\Event\StripeCheckoutSessionRequestEvent;
use OxidSolutionCatalysts\Payments\Stripe\EventSystem\Handler\StripeContractCreationHandler;
use PHPUnit\Framework\TestCase;

/**
 * Tests for address hash storage in contract during Stripe checkout initiation.
 *
 * TDD Test Suite - Phase 1 (Red):
 * These tests verify that the delivery address hash is properly stored
 * in the contract metadata before redirecting to Stripe.
 */
class AddressHashStorageTest extends TestCase
{
    /**
     * Test 1: Address hash is stored in contract metadata when contract is created.
     */
    public function testAddressHashStoredInContractOnCreation(): void
    {
        // Arrange
        $expectedHash = 'abc123def456';

        // Mock session to return the delivery address hash
        $session = $this->createMock(Session::class);
        $session->method('getVariable')
            ->willReturnMap([
                ['sDelAddrMD5', $expectedHash],
                ['deladrid', null],
            ]);
        Registry::set(Session::class, $session);

        // Create mock basket with user
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn('user123');
        $user->method('getEncodedDeliveryAddress')->willReturn($expectedHash);

        $basket = $this->createMock(Basket::class);
        $basket->method('getBasketUser')->willReturn($user);
        $basket->method('getProductsCount')->willReturn(1);
        $basket->method('getPrice')->willReturn(new class {
            public function getBruttoPrice(): float { return 100.0; }
            public function getNettoPrice(): float { return 84.0; }
            public function getVatValue(): float { return 16.0; }
        });
        $basket->method('getBasketCurrency')->willReturn(new class {
            public string $name = 'EUR';
        });
        $basket->method('getContents')->willReturn([]);

        // Create a real contract to verify metadata is stored
        $contract = new PaymentContract(
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

        // Mock contract service to return our contract
        $contractService = $this->createMock(ContractServiceInterface::class);
        $contractService->method('createContract')
            ->willReturn($contract);

        // Create context
        $context = new EventContext([
            'userId' => 'user123',
            'basket' => $basket,
            'conditionTypes' => ['payment_authorized'],
        ]);

        $event = new StripeCheckoutSessionRequestEvent($context);

        // Mock repository for save
        $contractRepository = $this->createMock(ContractRepositoryInterface::class);

        // Act
        $handler = new StripeContractCreationHandler($contractService, $contractRepository);
        $handler->handle($event);

        // Assert
        $storedContract = $context->getContract();
        $this->assertNotNull($storedContract);
        $this->assertEquals(
            $expectedHash,
            $storedContract->getMetadata('delivery_address_hash'),
            'Delivery address hash should be stored in contract metadata'
        );
    }

    /**
     * Test 2: Address hash includes delivery address ID when present.
     */
    public function testAddressHashIncludesDeliveryAddressId(): void
    {
        // Arrange
        $billingHash = 'billing123';
        $deliveryHash = 'delivery456';
        $combinedHash = $billingHash . $deliveryHash;
        $deliveryAddressId = 'deladdr_abc';

        $session = $this->createMock(Session::class);
        $session->method('getVariable')
            ->willReturnMap([
                ['sDelAddrMD5', $combinedHash],
                ['deladrid', $deliveryAddressId],
            ]);
        Registry::set(Session::class, $session);

        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn('user123');
        $user->method('getEncodedDeliveryAddress')->willReturn($billingHash);

        $basket = $this->createMock(Basket::class);
        $basket->method('getBasketUser')->willReturn($user);
        $basket->method('getProductsCount')->willReturn(1);
        $basket->method('getPrice')->willReturn(new class {
            public function getBruttoPrice(): float { return 100.0; }
            public function getNettoPrice(): float { return 84.0; }
            public function getVatValue(): float { return 16.0; }
        });
        $basket->method('getBasketCurrency')->willReturn(new class {
            public string $name = 'EUR';
        });
        $basket->method('getContents')->willReturn([]);

        $contract = new PaymentContract(
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

        $contractService = $this->createMock(ContractServiceInterface::class);
        $contractService->method('createContract')->willReturn($contract);

        $context = new EventContext([
            'userId' => 'user123',
            'basket' => $basket,
            'conditionTypes' => ['payment_authorized'],
        ]);

        $event = new StripeCheckoutSessionRequestEvent($context);

        // Mock repository for save
        $contractRepository = $this->createMock(ContractRepositoryInterface::class);

        // Act
        $handler = new StripeContractCreationHandler($contractService, $contractRepository);
        $handler->handle($event);

        // Assert
        $storedContract = $context->getContract();
        $this->assertEquals(
            $combinedHash,
            $storedContract->getMetadata('delivery_address_hash'),
            'Combined billing+delivery hash should be stored'
        );
        $this->assertEquals(
            $deliveryAddressId,
            $storedContract->getMetadata('delivery_address_id'),
            'Delivery address ID should be stored'
        );
    }

    /**
     * Test 3: Contract stores null hash when no address hash in session.
     */
    public function testContractHandlesMissingAddressHash(): void
    {
        // Arrange - no hash in session
        $session = $this->createMock(Session::class);
        $session->method('getVariable')
            ->willReturnMap([
                ['sDelAddrMD5', null],
                ['deladrid', null],
            ]);
        Registry::set(Session::class, $session);

        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn('user123');
        $user->method('getEncodedDeliveryAddress')->willReturn('computed_hash');

        $basket = $this->createMock(Basket::class);
        $basket->method('getBasketUser')->willReturn($user);
        $basket->method('getProductsCount')->willReturn(1);
        $basket->method('getPrice')->willReturn(new class {
            public function getBruttoPrice(): float { return 100.0; }
            public function getNettoPrice(): float { return 84.0; }
            public function getVatValue(): float { return 16.0; }
        });
        $basket->method('getBasketCurrency')->willReturn(new class {
            public string $name = 'EUR';
        });
        $basket->method('getContents')->willReturn([]);

        $contract = new PaymentContract(
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

        $contractService = $this->createMock(ContractServiceInterface::class);
        $contractService->method('createContract')->willReturn($contract);

        $context = new EventContext([
            'userId' => 'user123',
            'basket' => $basket,
            'conditionTypes' => ['payment_authorized'],
        ]);

        $event = new StripeCheckoutSessionRequestEvent($context);

        // Mock repository for save
        $contractRepository = $this->createMock(ContractRepositoryInterface::class);

        // Act
        $handler = new StripeContractCreationHandler($contractService, $contractRepository);
        $handler->handle($event);

        // Assert - should compute hash from user
        $storedContract = $context->getContract();
        $this->assertEquals(
            'computed_hash',
            $storedContract->getMetadata('delivery_address_hash'),
            'Should compute and store hash from user when session hash is missing'
        );
    }
}
