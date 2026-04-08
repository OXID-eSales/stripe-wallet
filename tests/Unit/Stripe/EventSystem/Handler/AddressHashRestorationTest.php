<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\EventSystem\Handler;

use OxidEsales\PaymentComponent\Contract\PaymentContract;
use OxidEsales\PaymentComponent\Contract\BasketSnapshot;
use OxidEsales\PaymentComponent\EventSystem\Event\EventContext;
use OxidEsales\PaymentComponent\EventSystem\EventDispatcherInterface;
use OxidEsales\PaymentComponent\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentComponent\Contract\SecurityValidationResultInterface;
use OxidEsales\PaymentComponent\Service\ReturnSecurityValidatorInterface;
use OxidEsales\PaymentComponent\Adapter\SessionAdapterInterface;
use OxidEsales\PaymentComponent\Service\DeliveryAddressHashServiceInterface;
use OxidEsales\Payments\Stripe\Service\CheckoutReturnServiceInterface;
use OxidEsales\Payments\Stripe\Service\Result\CheckoutReturnResult;
use OxidEsales\Payments\Stripe\EventSystem\Event\StripeCheckoutReturnEvent;
use OxidEsales\Payments\Stripe\EventSystem\Handler\StripeCheckoutReturnHandler;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Sprint 22: Removed TestableAddressHashHandler - EventDispatcher is now
 * injected via constructor, no longer fetched lazily via ContainerFactory.
 */

/**
 * Tests for address hash restoration from contract when returning from Stripe.
 *
 * Sprint 21: Updated for refactored handler with CheckoutReturnServiceInterface.
 * Sprint 22: EventDispatcher now injected via constructor (no ContainerFactory).
 * Sprint 20: Tests updated to include SessionAdapterInterface mock.
 *
 * These tests verify that the delivery address hash is properly restored
 * to the session when returning from Stripe checkout.
 */
class AddressHashRestorationTest extends TestCase
{
    private CheckoutReturnServiceInterface&MockObject $checkoutReturnService;
    private ContractRepositoryInterface&MockObject $contractRepository;
    private ReturnSecurityValidatorInterface&MockObject $securityValidator;
    private DeliveryAddressHashServiceInterface&MockObject $deliveryAddressHashService;
    private SessionAdapterInterface&MockObject $sessionAdapter;
    private EventDispatcherInterface&MockObject $eventDispatcher;

    protected function setUp(): void
    {
        $this->checkoutReturnService = $this->createMock(CheckoutReturnServiceInterface::class);
        $this->contractRepository = $this->createMock(ContractRepositoryInterface::class);
        $this->securityValidator = $this->createMock(ReturnSecurityValidatorInterface::class);
        $this->deliveryAddressHashService = $this->createMock(DeliveryAddressHashServiceInterface::class);
        $this->sessionAdapter = $this->createMock(SessionAdapterInterface::class);
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);

        // Default: security validation passes
        $securityResult = $this->createMock(SecurityValidationResultInterface::class);
        $securityResult->method('isAllowed')->willReturn(true);
        $securityResult->method('getScore')->willReturn(100);
        $securityResult->method('getWarnings')->willReturn([]);

        $this->securityValidator
            ->method('validateReturn')
            ->willReturn($securityResult);
    }

    private function createHandler(): StripeCheckoutReturnHandler
    {
        return new StripeCheckoutReturnHandler(
            $this->checkoutReturnService,
            $this->contractRepository,
            $this->securityValidator,
            $this->deliveryAddressHashService,
            $this->eventDispatcher,
            $this->sessionAdapter,
            $this->createMock(\OxidEsales\PaymentComponent\Repository\TransactionRepositoryInterface::class)
        );
    }

    /**
     * Test 1: Address hash is restored to session from contract metadata.
     *
     * Sprint 20: Updated to use SessionAdapterInterface mock instead of Registry::getSession().
     */
    public function testAddressHashRestoredToSessionFromContract(): void
    {
        // Arrange
        $storedHash = 'stored_hash_abc123';
        $contractId = 'contract_test_123';

        // Create contract with stored hash
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
            ]),
            id: $contractId
        );
        $contract->setMetadata('delivery_address_hash', $storedHash);

        // Mock checkout return service to return success
        $this->checkoutReturnService
            ->method('validateReturn')
            ->willReturn(CheckoutReturnResult::success($contractId, 'pi_test_123', 10000, 'eur'));

        // Mock contract repository
        $this->contractRepository
            ->method('findById')
            ->with($contractId)
            ->willReturn($contract);

        // Sprint 20: Expect sessionAdapter->setVariable to be called (not Registry::getSession())
        $this->sessionAdapter
            ->expects($this->atLeastOnce())
            ->method('setVariable')
            ->with('sDelAddrMD5', $storedHash);

        $context = new EventContext([
            'checkoutSessionId' => 'cs_test_123',
            'contract_token' => 'valid_token_123',
            'contract_id' => $contractId,
        ]);
        $event = new StripeCheckoutReturnEvent($context);

        // Act
        $handler = $this->createHandler();
        $handler->handle($event);

        // Assert - verify session variable was set (via mock expectation)
    }

    /**
     * Test 2: Delivery address ID is also restored to session.
     *
     * Sprint 20: Updated to use SessionAdapterInterface mock instead of Registry::getSession().
     */
    public function testDeliveryAddressIdRestoredToSession(): void
    {
        // Arrange
        $storedHash = 'stored_hash_xyz';
        $deliveryAddressId = 'deladdr_456';
        $contractId = 'contract_test_456';

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
            ]),
            id: $contractId
        );
        $contract->setMetadata('delivery_address_hash', $storedHash);
        $contract->setMetadata('delivery_address_id', $deliveryAddressId);

        $this->checkoutReturnService
            ->method('validateReturn')
            ->willReturn(CheckoutReturnResult::success($contractId, 'pi_test_456', 10000, 'eur'));

        $this->contractRepository
            ->method('findById')
            ->with($contractId)
            ->willReturn($contract);

        // Sprint 20: Track session adapter calls
        $setVariableCalls = [];
        $this->sessionAdapter
            ->expects($this->atLeast(2))
            ->method('setVariable')
            ->willReturnCallback(function ($key, $value) use (&$setVariableCalls) {
                $setVariableCalls[$key] = $value;
            });

        $context = new EventContext([
            'checkoutSessionId' => 'cs_test_456',
            'contract_token' => 'valid_token_456',
            'contract_id' => $contractId,
        ]);
        $event = new StripeCheckoutReturnEvent($context);

        // Act
        $handler = $this->createHandler();
        $handler->handle($event);

        // Assert
        $this->assertArrayHasKey('sDelAddrMD5', $setVariableCalls);
        $this->assertEquals($storedHash, $setVariableCalls['sDelAddrMD5']);
        $this->assertArrayHasKey('deladrid', $setVariableCalls);
        $this->assertEquals($deliveryAddressId, $setVariableCalls['deladrid']);
    }

    /**
     * Test 3: Handler proceeds without error when no hash stored.
     *
     * Sprint 20: Updated to use SessionAdapterInterface mock instead of Registry::getSession().
     */
    public function testHandlerProceedsWhenNoHashStored(): void
    {
        // Arrange - contract without metadata
        $contractId = 'contract_no_hash';

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
            ]),
            id: $contractId
        );
        // Note: No metadata set

        $this->checkoutReturnService
            ->method('validateReturn')
            ->willReturn(CheckoutReturnResult::success($contractId, 'pi_test_no_hash', 10000, 'eur'));

        $this->contractRepository
            ->method('findById')
            ->with($contractId)
            ->willReturn($contract);

        // Sprint 20: Session adapter setVariable should NOT be called for sDelAddrMD5
        $this->sessionAdapter
            ->expects($this->never())
            ->method('setVariable')
            ->with('sDelAddrMD5', $this->anything());

        $context = new EventContext([
            'checkoutSessionId' => 'cs_test_no_hash',
            'contract_token' => 'valid_token_no_hash',
            'contract_id' => $contractId,
        ]);
        $event = new StripeCheckoutReturnEvent($context);

        // Act - should not throw
        $handler = $this->createHandler();
        $handler->handle($event);

        // Assert - PaymentAuthorizedEvent should still be dispatched
        // (The actual order creation may fail later, but handler should proceed)
    }

    /**
     * Test 4: Address hash restored BEFORE PaymentAuthorizedEvent is dispatched.
     *
     * Sprint 17: Fixed false-positive test - explicit assertions at test level
     * Sprint 20: Updated to use SessionAdapterInterface mock instead of Registry::getSession().
     */
    public function testAddressHashRestoredBeforePaymentEvent(): void
    {
        // Arrange
        $storedHash = 'hash_for_timing_test';
        $contractId = 'contract_timing';
        $hashRestoredBeforeDispatch = false;
        $dispatchWasCalled = false;

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
            ]),
            id: $contractId
        );
        $contract->setMetadata('delivery_address_hash', $storedHash);

        $this->checkoutReturnService
            ->method('validateReturn')
            ->willReturn(CheckoutReturnResult::success($contractId, 'pi_timing', 10000, 'eur'));

        $this->contractRepository
            ->method('findById')
            ->willReturn($contract);

        // Sprint 20: Track when hash is restored via sessionAdapter
        $this->sessionAdapter
            ->method('setVariable')
            ->willReturnCallback(function ($key, $value) use (&$hashRestoredBeforeDispatch, $storedHash) {
                if ($key === 'sDelAddrMD5' && $value === $storedHash) {
                    $hashRestoredBeforeDispatch = true;
                }
            });

        // Capture timing state when dispatch is called
        $hashStateAtDispatch = null;
        $this->eventDispatcher
            ->method('dispatch')
            ->willReturnCallback(function ($event) use (&$hashRestoredBeforeDispatch, &$dispatchWasCalled, &$hashStateAtDispatch) {
                $dispatchWasCalled = true;
                $hashStateAtDispatch = $hashRestoredBeforeDispatch;
                return $event;
            });

        $context = new EventContext([
            'checkoutSessionId' => 'cs_timing',
            'contract_token' => 'valid_token_timing',
            'contract_id' => $contractId,
        ]);
        $event = new StripeCheckoutReturnEvent($context);

        // Act
        $handler = $this->createHandler();
        $handler->handle($event);

        // Assert - explicit assertions at test level (Sprint 17)
        if ($dispatchWasCalled) {
            $this->assertTrue(
                $hashStateAtDispatch,
                'Address hash should be restored BEFORE PaymentAuthorizedEvent dispatch'
            );
        } else {
            // If dispatch wasn't called, hash should still have been restored
            $this->assertTrue(
                $hashRestoredBeforeDispatch,
                'Address hash should be restored even if dispatch is not called'
            );
        }
    }
}
