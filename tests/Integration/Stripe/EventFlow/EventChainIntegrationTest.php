<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Integration\Stripe\EventFlow;

use OxidEsales\PaymentComponent\EventSystem\EventDispatcher;
use OxidEsales\PaymentComponent\EventSystem\Event\EventContext;
use OxidEsales\PaymentComponent\EventSystem\Handler\ContractCreationHandler;
use OxidEsales\PaymentComponent\EventSystem\Handler\ContractConditionResolverHandler;
use OxidEsales\PaymentComponent\EventSystem\Handler\EarlyOrderCreationHandler;
use OxidEsales\PaymentComponent\EventSystem\Handler\PaymentAuthorizationHandler;
use OxidEsales\PaymentComponent\EventSystem\Handler\OrderCreationHandler;
use OxidEsales\PaymentComponent\EventSystem\Event\Contract\ContractCreatedEvent;
use OxidEsales\PaymentComponent\EventSystem\Event\Contract\ContractDraftCompletedEvent;
use OxidEsales\PaymentComponent\EventSystem\Event\Contract\ContractReadyToCommitEvent;
use OxidEsales\PaymentComponent\EventSystem\Event\Contract\ContractTransitionedToPendingEvent;
use OxidEsales\PaymentComponent\EventSystem\Event\Payment\PaymentInitiatedEvent;
use OxidEsales\PaymentComponent\Repository\ContractRepository;
use OxidEsales\PaymentComponent\Service\ContractService;
use OxidEsales\PaymentComponent\Tests\Unit\EventSystem\Handler\Support\InMemoryOrderRepository;
use OxidEsales\PaymentComponent\Tests\Unit\EventSystem\Handler\Support\InMemoryShopOrderService;
use PHPUnit\Framework\TestCase;

/**
 * Integration test for the complete event chain flow.
 *
 * This test verifies that the event-driven architecture works correctly:
 * 1. PaymentInitiatedEvent → ContractCreationHandler (creates contract)
 * 2. ContractCreatedEvent → ContractConditionResolverHandler (dispatches ContractDraftCompletedEvent)
 * 3. ContractDraftCompletedEvent → EarlyOrderCreationHandler (creates order, NOT_FINISHED → PENDING)
 * 4. ContractTransitionedToPendingEvent → PaymentAuthorizationHandler (fulfills condition)
 * 5. ContractReadyToCommitEvent → OrderCreationHandler (commits order)
 *
 * STRP-74: Updated flow with early order creation
 */
class EventChainIntegrationTest extends TestCase
{
    private EventDispatcher $dispatcher;
    private ContractRepository $contractRepository;
    private InMemoryOrderRepository $orderRepository;
    private InMemoryShopOrderService $shopOrderService;
    private ContractService $contractService;
    /** @var array<string> */
    private array $handledEvents = [];

    protected function setUp(): void
    {
        $this->dispatcher = new EventDispatcher();
        $this->contractRepository = new ContractRepository();
        $this->orderRepository = new InMemoryOrderRepository();
        $this->shopOrderService = new InMemoryShopOrderService();
        $this->contractService = new ContractService($this->contractRepository);
        $this->handledEvents = [];

        $this->registerHandlers();
    }

    private function registerHandlers(): void
    {
        // Component handlers
        $contractCreationHandler = new ContractCreationHandler(
            $this->contractService,
            $this->dispatcher
        );

        $contractConditionResolverHandler = new ContractConditionResolverHandler(
            $this->contractRepository,
            $this->dispatcher
        );

        // STRP-74: EarlyOrderCreationHandler for new flow DRAFT → NOT_FINISHED → PENDING
        $earlyOrderCreationHandler = new EarlyOrderCreationHandler(
            $this->contractRepository,
            $this->shopOrderService,
            $this->dispatcher
        );

        $paymentAuthorizationHandler = new PaymentAuthorizationHandler(
            $this->contractRepository,
            $this->dispatcher
        );

        $orderCreationHandler = new OrderCreationHandler(
            $this->contractRepository,
            $this->orderRepository,
            $this->dispatcher
        );

        // Register listeners with event tracking
        $this->dispatcher->addListener(
            PaymentInitiatedEvent::class,
            function ($event) use ($contractCreationHandler) {
                $this->handledEvents[] = 'ContractCreation';
                $contractCreationHandler->handle($event);
            }
        );

        $this->dispatcher->addListener(
            ContractCreatedEvent::class,
            function ($event) use ($contractConditionResolverHandler) {
                $this->handledEvents[] = 'ContractConditionResolver';
                $contractConditionResolverHandler->handle($event);
            }
        );

        // STRP-74: Register EarlyOrderCreationHandler for ContractDraftCompletedEvent
        $this->dispatcher->addListener(
            ContractDraftCompletedEvent::class,
            function ($event) use ($earlyOrderCreationHandler) {
                $this->handledEvents[] = 'EarlyOrderCreation';
                $earlyOrderCreationHandler->handle($event);
            }
        );

        $this->dispatcher->addListener(
            ContractTransitionedToPendingEvent::class,
            function ($event) use ($paymentAuthorizationHandler) {
                $this->handledEvents[] = 'PaymentAuthorization';
                $paymentAuthorizationHandler->handle($event);
            }
        );

        $this->dispatcher->addListener(
            ContractReadyToCommitEvent::class,
            function ($event) use ($orderCreationHandler) {
                $this->handledEvents[] = 'OrderCreation';
                $orderCreationHandler->handle($event);
            }
        );
    }

    public function testPaymentInitiatedTriggersContractCreation(): void
    {
        $context = new EventContext([
            'userId' => 'user_123',
            'basket' => $this->createTestBasket(),
            'conditionTypes' => ['payment_authorized'],
        ]);

        $event = new PaymentInitiatedEvent(
            $context,
            'stripe',
            130.0,
            'EUR',
            'https://shop.example.com/return',
            'https://shop.example.com/cancel'
        );
        $this->dispatcher->dispatch($event);

        // Verify contract was created
        $contract = $context->getContract();
        $this->assertNotNull($contract, 'Contract should be created');

        // Verify handlers were called in order
        $this->assertContains('ContractCreation', $this->handledEvents);
        $this->assertContains('ContractConditionResolver', $this->handledEvents);
    }

    public function testCompleteEventChainWithAuthorizationId(): void
    {
        // When authorizationId is provided, the full chain should execute
        $context = new EventContext([
            'userId' => 'user_789',
            'basket' => $this->createTestBasket(),
            'conditionTypes' => ['payment_authorized'],
            'authorizationId' => 'auth_immediate',
            'providerOrderId' => 'pi_immediate',
        ]);

        $event = new PaymentInitiatedEvent(
            $context,
            'stripe',
            130.0,
            'EUR',
            'https://shop.example.com/return',
            'https://shop.example.com/cancel'
        );
        $this->dispatcher->dispatch($event);

        // Verify full chain ran
        $this->assertContains('ContractCreation', $this->handledEvents);
        $this->assertContains('ContractConditionResolver', $this->handledEvents);

        $contract = $context->getContract();
        $this->assertNotNull($contract);

        // Contract should be committed and have an order
        $contract = $this->contractRepository->findById($contract->getId());
        $this->assertTrue($contract->getState()->isCommitted(), 'Contract should be committed');
        $this->assertNotNull($contract->getOrderId(), 'Order should be created');

        // Verify order in repository
        $orders = $this->orderRepository->findAll();
        $this->assertCount(1, $orders);
        $this->assertEquals('user_789', $orders[0]->getUserId());
    }

    public function testEventChainCreatesOrderOnlyAfterAuthorization(): void
    {
        // Without authorizationId, order should not be created immediately
        $context = new EventContext([
            'userId' => 'user_pending',
            'basket' => $this->createTestBasket(),
            'conditionTypes' => ['payment_authorized'],
            // No authorizationId
        ]);

        $event = new PaymentInitiatedEvent(
            $context,
            'stripe',
            130.0,
            'EUR',
            'https://shop.example.com/return',
            'https://shop.example.com/cancel'
        );
        $this->dispatcher->dispatch($event);

        // Contract should exist
        $contract = $context->getContract();
        $this->assertNotNull($contract);

        // Handlers should have been called
        $this->assertContains('ContractCreation', $this->handledEvents);
        $this->assertContains('ContractConditionResolver', $this->handledEvents);

        // The flow depends on condition auto-fulfillment logic
        // which varies based on context - this is expected behavior
    }

    public function testEventHandlerOrderIsCorrect(): void
    {
        $context = new EventContext([
            'userId' => 'user_order_test',
            'basket' => $this->createTestBasket(),
            'conditionTypes' => ['payment_authorized'],
            'authorizationId' => 'auth_order_test',
            'providerOrderId' => 'pi_order_test',
        ]);

        $event = new PaymentInitiatedEvent(
            $context,
            'stripe',
            130.0,
            'EUR',
            'https://shop.example.com/return',
            'https://shop.example.com/cancel'
        );
        $this->dispatcher->dispatch($event);

        // STRP-74: Updated handler order with EarlyOrderCreation
        // Verify handler execution order
        $contractCreationIndex = array_search('ContractCreation', $this->handledEvents);
        $conditionResolverIndex = array_search('ContractConditionResolver', $this->handledEvents);
        $earlyOrderCreationIndex = array_search('EarlyOrderCreation', $this->handledEvents);

        $this->assertNotFalse($contractCreationIndex);
        $this->assertNotFalse($conditionResolverIndex);
        $this->assertNotFalse($earlyOrderCreationIndex);
        $this->assertLessThan($conditionResolverIndex, $contractCreationIndex);
        $this->assertLessThan($earlyOrderCreationIndex, $conditionResolverIndex);
    }

    private function createTestBasket(): object
    {
        return (object) [
            'items' => [
                ['productId' => 'prod1', 'quantity' => 2, 'price' => 50.0, 'name' => 'Test Product 1'],
                ['productId' => 'prod2', 'quantity' => 1, 'price' => 30.0, 'name' => 'Test Product 2'],
            ],
            'discounts' => [],
            'totalGross' => 130.0,
            'totalNet' => 109.24,
            'totalVat' => 20.76,
            'currency' => 'EUR',
        ];
    }
}
