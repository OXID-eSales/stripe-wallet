<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Unit\Stripe\EventSystem\Handler;

use OxidEsales\Eshop\Core\Registry;
use OxidEsales\Eshop\Core\Session;
use OxidSolutionCatalysts\Payments\Component\Contract\PaymentContract;
use OxidSolutionCatalysts\Payments\Component\Contract\BasketSnapshot;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\EventContext;
use OxidSolutionCatalysts\Payments\Component\EventSystem\EventDispatcherInterface;
use OxidSolutionCatalysts\Payments\Component\Repository\ContractRepositoryInterface;
use OxidSolutionCatalysts\Payments\Stripe\EventSystem\Event\StripeCheckoutReturnEvent;
use OxidSolutionCatalysts\Payments\Stripe\EventSystem\Handler\StripeCheckoutReturnHandler;
use OxidSolutionCatalysts\Payments\Stripe\Service\Factory\StripeAdapterFactoryInterface;
use PHPUnit\Framework\TestCase;
use Stripe\StripeClient;
use Stripe\Service\Checkout\SessionService;

/**
 * Testable subclass for address hash restoration tests.
 */
class TestableAddressHashHandler extends StripeCheckoutReturnHandler
{
    private ?EventDispatcherInterface $testEventDispatcher = null;

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
}

/**
 * Tests for address hash restoration from contract when returning from Stripe.
 *
 * TDD Test Suite - Phase 1 (Red):
 * These tests verify that the delivery address hash is properly restored
 * to the session when returning from Stripe checkout.
 */
class AddressHashRestorationTest extends TestCase
{
    private ContractRepositoryInterface $contractRepository;
    private StripeAdapterFactoryInterface $adapterFactory;
    private EventDispatcherInterface $eventDispatcher;
    private StripeClient $stripeClient;
    private SessionService $sessionService;
    private Session $session;

    protected function setUp(): void
    {
        $this->contractRepository = $this->createMock(ContractRepositoryInterface::class);
        $this->adapterFactory = $this->createMock(StripeAdapterFactoryInterface::class);
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->stripeClient = $this->createMock(StripeClient::class);
        $this->sessionService = $this->createMock(SessionService::class);
        $this->session = $this->createMock(Session::class);
    }

    private function createHandler(): TestableAddressHashHandler
    {
        $handler = new TestableAddressHashHandler(
            $this->contractRepository,
            $this->adapterFactory
        );
        $handler->setTestEventDispatcher($this->eventDispatcher);
        return $handler;
    }

    /**
     * Test 1: Address hash is restored to session from contract metadata.
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

        // Mock contract repository
        $this->contractRepository
            ->method('findById')
            ->with($contractId)
            ->willReturn($contract);

        // Mock Stripe checkout session
        $checkoutSession = $this->createCheckoutSessionMock(
            'cs_test_123',
            'paid',
            'pi_test_123',
            $contractId
        );

        $this->sessionService
            ->method('retrieve')
            ->willReturn($checkoutSession);

        $this->setupStripeClientMocks();

        // Expect session variable to be set
        $this->session
            ->expects($this->atLeastOnce())
            ->method('setVariable')
            ->with('sDelAddrMD5', $storedHash);

        Registry::set(Session::class, $this->session);

        $context = new EventContext(['checkoutSessionId' => 'cs_test_123']);
        $event = new StripeCheckoutReturnEvent($context);

        // Act
        $handler = $this->createHandler();
        $handler->handle($event);

        // Assert - verify session variable was set (via mock expectation)
    }

    /**
     * Test 2: Delivery address ID is also restored to session.
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

        $this->contractRepository
            ->method('findById')
            ->with($contractId)
            ->willReturn($contract);

        $checkoutSession = $this->createCheckoutSessionMock(
            'cs_test_456',
            'paid',
            'pi_test_456',
            $contractId
        );

        $this->sessionService
            ->method('retrieve')
            ->willReturn($checkoutSession);

        $this->setupStripeClientMocks();

        // Expect both session variables to be set
        $setVariableCalls = [];
        $this->session
            ->expects($this->atLeast(2))
            ->method('setVariable')
            ->willReturnCallback(function ($key, $value) use (&$setVariableCalls) {
                $setVariableCalls[$key] = $value;
            });

        Registry::set(Session::class, $this->session);

        $context = new EventContext(['checkoutSessionId' => 'cs_test_456']);
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

        $this->contractRepository
            ->method('findById')
            ->with($contractId)
            ->willReturn($contract);

        $checkoutSession = $this->createCheckoutSessionMock(
            'cs_test_no_hash',
            'paid',
            'pi_test_no_hash',
            $contractId
        );

        $this->sessionService
            ->method('retrieve')
            ->willReturn($checkoutSession);

        $this->setupStripeClientMocks();

        // Session setVariable should NOT be called for sDelAddrMD5
        $this->session
            ->expects($this->never())
            ->method('setVariable')
            ->with('sDelAddrMD5', $this->anything());

        Registry::set(Session::class, $this->session);

        $context = new EventContext(['checkoutSessionId' => 'cs_test_no_hash']);
        $event = new StripeCheckoutReturnEvent($context);

        // Act - should not throw
        $handler = $this->createHandler();
        $handler->handle($event);

        // Assert - PaymentAuthorizedEvent should still be dispatched
        // (The actual order creation may fail later, but handler should proceed)
    }

    /**
     * Test 4: Address hash restored BEFORE PaymentAuthorizedEvent is dispatched.
     */
    public function testAddressHashRestoredBeforePaymentEvent(): void
    {
        // Arrange
        $storedHash = 'hash_for_timing_test';
        $contractId = 'contract_timing';
        $hashRestoredBeforeDispatch = false;

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

        $this->contractRepository
            ->method('findById')
            ->willReturn($contract);

        $checkoutSession = $this->createCheckoutSessionMock(
            'cs_timing',
            'paid',
            'pi_timing',
            $contractId
        );

        $this->sessionService
            ->method('retrieve')
            ->willReturn($checkoutSession);

        $this->setupStripeClientMocks();

        // Track when hash is restored vs when event is dispatched
        $this->session
            ->method('setVariable')
            ->willReturnCallback(function ($key, $value) use (&$hashRestoredBeforeDispatch, $storedHash) {
                if ($key === 'sDelAddrMD5' && $value === $storedHash) {
                    $hashRestoredBeforeDispatch = true;
                }
            });

        $this->eventDispatcher
            ->method('dispatch')
            ->willReturnCallback(function ($event) use (&$hashRestoredBeforeDispatch) {
                // When dispatch is called, hash should already be restored
                $this->assertTrue(
                    $hashRestoredBeforeDispatch,
                    'Address hash should be restored BEFORE PaymentAuthorizedEvent dispatch'
                );
                return $event;
            });

        Registry::set(Session::class, $this->session);

        $context = new EventContext(['checkoutSessionId' => 'cs_timing']);
        $event = new StripeCheckoutReturnEvent($context);

        // Act
        $handler = $this->createHandler();
        $handler->handle($event);
    }

    // --- Helper methods ---

    private function createCheckoutSessionMock(
        string $sessionId,
        string $paymentStatus,
        string $paymentIntentId,
        string $contractId
    ): object {
        return new class($sessionId, $paymentStatus, $paymentIntentId, $contractId) {
            public string $id;
            public string $payment_status;
            public string $payment_intent;
            public int $amount_total;
            public string $currency;
            public object $metadata;

            public function __construct(
                string $id,
                string $paymentStatus,
                string $paymentIntentId,
                string $contractId
            ) {
                $this->id = $id;
                $this->payment_status = $paymentStatus;
                $this->payment_intent = $paymentIntentId;
                $this->amount_total = 10000;
                $this->currency = 'eur';
                $this->metadata = new class($contractId) {
                    public string $contract_id;
                    public function __construct(string $contractId)
                    {
                        $this->contract_id = $contractId;
                    }
                };
            }
        };
    }

    private function setupStripeClientMocks(): void
    {
        $checkoutService = new \stdClass();
        $checkoutService->sessions = $this->sessionService;

        $this->stripeClient->checkout = $checkoutService;

        $this->adapterFactory
            ->method('getStripeClient')
            ->willReturn($this->stripeClient);
    }
}
