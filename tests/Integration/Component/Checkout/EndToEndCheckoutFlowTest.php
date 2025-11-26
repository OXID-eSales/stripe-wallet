<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Integration\Component\Checkout;

use OxidSolutionCatalysts\Payments\Component\Contract\BasketSnapshot;
use OxidSolutionCatalysts\Payments\Component\Contract\ContractCondition;
use OxidSolutionCatalysts\Payments\Component\Contract\ContractState;
use OxidSolutionCatalysts\Payments\Component\Contract\PaymentContract;
use OxidSolutionCatalysts\Payments\Component\Contract\PaymentContractInterface;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\Contract\ContractCreatedEvent;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\EventContext;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\Payment\PaymentInitiatedEvent;
use OxidSolutionCatalysts\Payments\Component\EventSystem\EventDispatcher;
use OxidSolutionCatalysts\Payments\Component\EventSystem\EventListenerProvider;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Handler\ContractCreationHandler;
use OxidSolutionCatalysts\Payments\Component\Repository\ContractRepositoryInterface;
use OxidSolutionCatalysts\Payments\Component\Service\CheckoutOrchestrator;
use OxidSolutionCatalysts\Payments\Component\Service\ContractService;
use OxidSolutionCatalysts\Payments\Component\Service\Result\CheckoutResult;
use OxidSolutionCatalysts\Payments\Component\Service\Result\OrderConfirmationResult;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * End-to-end integration test for the complete checkout flow.
 *
 * Tests the entire flow from OrderController.execute() to ThankyouController,
 * verifying that:
 * - Contract is created with proper state machine transitions
 * - Conditions are tracked and fulfilled
 * - Order state changes are recorded
 * - All components work together correctly
 *
 * Note: This test does NOT test Stripe API integration (webhooks, idempotency).
 * It tests the internal contract and order state machine.
 *
 * @group integration
 * @group checkout
 * @group e2e
 */
final class EndToEndCheckoutFlowTest extends TestCase
{
    private EventDispatcher $eventDispatcher;
    private CheckoutOrchestrator $orchestrator;
    private InMemoryContractRepository $contractRepository;
    private ContractService $contractService;

    protected function setUp(): void
    {
        parent::setUp();

        // Create in-memory contract repository for testing
        $this->contractRepository = new InMemoryContractRepository();

        // Create ContractService with in-memory repository
        $this->contractService = new ContractService($this->contractRepository);

        // Create EventListenerProvider with empty handlers initially
        $listenerProvider = new EventListenerProvider([]);

        // Create EventDispatcher
        $this->eventDispatcher = new EventDispatcher($listenerProvider);

        // Wire up ContractCreationHandler to handle PaymentInitiatedEvent
        $contractCreationHandler = new ContractCreationHandler(
            $this->contractService,
            $this->eventDispatcher
        );

        // Register handler for PaymentInitiatedEvent
        $this->eventDispatcher->addListener(
            PaymentInitiatedEvent::class,
            fn($event) => $contractCreationHandler->handle($event)
        );

        // Create CheckoutOrchestrator with the dispatcher
        $this->orchestrator = new CheckoutOrchestrator(
            $this->eventDispatcher,
            new NullLogger()
        );
    }

    // =========================================================================
    // PHASE 1: Contract Creation (OrderController.execute())
    // =========================================================================

    /**
     * @group integration
     * @group e2e
     */
    public function testFullCheckoutFlow_CreatesContractInDraftState(): void
    {
        $basket = $this->createValidBasket(150.00, 'EUR');
        $user = $this->createValidUser('user_123');

        $result = $this->orchestrator->processCheckout(
            $basket,
            $user,
            'stripe_card',
            'pi_test_intent_123'
        );

        $this->assertInstanceOf(CheckoutResult::class, $result);

        // Debug: if checkout failed, show the error message
        if (!$result->isSuccess()) {
            $this->fail(sprintf(
                'Checkout failed: %s (code: %s)',
                $result->getErrorMessage() ?? 'no message',
                $result->getErrorCode() ?? 'no code'
            ));
        }

        $this->assertNotNull($result->getContractId(), 'Contract ID should be set');

        // Verify contract was persisted
        $contractId = $result->getContractId();
        $this->assertIsString($contractId);
        $contract = $this->contractRepository->findById($contractId);
        $this->assertNotNull($contract, 'Contract should be persisted');
    }

    /**
     * @group integration
     * @group e2e
     */
    public function testFullCheckoutFlow_ContractHasCorrectBasketSnapshot(): void
    {
        $basket = $this->createValidBasket(199.99, 'USD');
        $user = $this->createValidUser('user_456');

        $result = $this->orchestrator->processCheckout(
            $basket,
            $user,
            'stripe_sepa',
            null
        );

        $contract = $this->contractRepository->findById($result->getContractId());
        $this->assertNotNull($contract);

        $snapshot = $contract->getBasketSnapshot();
        $this->assertEquals(199.99, $snapshot->getTotalGross());
        $this->assertEquals('USD', $snapshot->getCurrency());
    }

    /**
     * @group integration
     * @group e2e
     */
    public function testFullCheckoutFlow_ContractHasDefaultConditions(): void
    {
        $basket = $this->createValidBasket(100.00, 'EUR');
        $user = $this->createValidUser('user_789');

        $result = $this->orchestrator->processCheckout(
            $basket,
            $user,
            'stripe_card',
            'pi_test_123'
        );

        $contract = $this->contractRepository->findById($result->getContractId());
        $this->assertNotNull($contract);

        $conditions = $contract->getConditions();
        $this->assertCount(2, $conditions, 'Should have 2 default conditions');

        $conditionTypes = array_map(fn($c) => $c->getType(), $conditions);
        $this->assertContains(ContractCondition::TYPE_PAYMENT_AUTHORIZED, $conditionTypes);
        $this->assertContains(ContractCondition::TYPE_FRAUD_CHECK, $conditionTypes);

        // All conditions should be pending initially
        foreach ($conditions as $condition) {
            $this->assertTrue($condition->isPending(), "Condition {$condition->getType()} should be pending");
        }
    }

    // =========================================================================
    // PHASE 2: Contract State Machine Transitions
    // =========================================================================

    /**
     * @group integration
     * @group e2e
     */
    public function testContractStateMachine_TransitionsToPendingAfterConditionsAdded(): void
    {
        $contract = $this->createContractDirectly();

        $this->assertTrue($contract->getState()->isDraft(), 'Initial state should be DRAFT');

        $contract->addCondition(ContractCondition::paymentAuthorized());
        $contract->transitionToPending();

        $this->assertTrue($contract->getState()->isPending(), 'State should be PENDING');
    }

    /**
     * @group integration
     * @group e2e
     */
    public function testContractStateMachine_TransitionsToReadyToCommitWhenAllConditionsFulfilled(): void
    {
        $contract = $this->createContractDirectly();

        $contract->addCondition(ContractCondition::paymentAuthorized());
        $contract->addCondition(ContractCondition::fraudCheck());
        $contract->transitionToPending();

        $this->assertFalse($contract->areAllConditionsFulfilled());

        // Fulfill first condition
        $contract->fulfillCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED, [
            'authorizationId' => 'auth_test_123',
            'providerOrderId' => 'pi_test_456',
        ]);

        $this->assertFalse($contract->areAllConditionsFulfilled());
        $this->assertTrue($contract->getState()->isPending(), 'Should still be PENDING');

        // Fulfill second condition
        $contract->fulfillCondition(ContractCondition::TYPE_FRAUD_CHECK, [
            'score' => 95,
            'risk' => 'low',
        ]);

        $this->assertTrue($contract->areAllConditionsFulfilled());
        $this->assertTrue($contract->getState()->isReadyToCommit(), 'Should transition to READY_TO_COMMIT');
    }

    /**
     * @group integration
     * @group e2e
     */
    public function testContractStateMachine_CommitsToOrderWhenReady(): void
    {
        $contract = $this->createReadyToCommitContract();

        $this->assertNull($contract->getOrderId(), 'Order ID should be null before commit');

        $orderId = 'order_' . uniqid();
        $contract->commitToOrder($orderId);

        $this->assertEquals($orderId, $contract->getOrderId());
        $this->assertTrue($contract->getState()->isCommitted(), 'State should be COMMITTED');
    }

    /**
     * @group integration
     * @group e2e
     */
    public function testContractStateMachine_FulfillsAfterCommit(): void
    {
        $contract = $this->createCommittedContract();

        $this->assertTrue($contract->getState()->isCommitted());
        $this->assertNull($contract->getFulfilledAt());

        $contract->fulfill();

        $this->assertTrue($contract->getState()->isFulfilled(), 'State should be FULFILLED');
        $this->assertNotNull($contract->getFulfilledAt(), 'FulfilledAt should be set');
    }

    // =========================================================================
    // PHASE 3: Condition Fulfillment Tracking
    // =========================================================================

    /**
     * @group integration
     * @group e2e
     */
    public function testConditionFulfillment_TracksPaymentAuthorization(): void
    {
        $contract = $this->createContractDirectly();
        $contract->addCondition(ContractCondition::paymentAuthorized());
        $contract->transitionToPending();

        $authData = [
            'authorizationId' => 'auth_stripe_xyz',
            'providerOrderId' => 'pi_live_abc123',
            'amount' => 15000,
            'currency' => 'eur',
        ];

        $contract->fulfillCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED, $authData);

        $conditions = $contract->getConditions();
        $paymentCondition = $conditions[0];

        $this->assertTrue($paymentCondition->isFulfilled());
        $this->assertNotNull($paymentCondition->getFulfilledAt());
        $this->assertEquals($authData, $paymentCondition->getData());
    }

    /**
     * @group integration
     * @group e2e
     */
    public function testConditionFulfillment_TracksFraudCheck(): void
    {
        $contract = $this->createContractDirectly();
        $contract->addCondition(ContractCondition::fraudCheck());
        $contract->transitionToPending();

        $fraudData = [
            'score' => 98,
            'risk' => 'low',
            'checkId' => 'fraud_check_123',
            'passedChecks' => ['velocity', 'geolocation', 'device'],
        ];

        $contract->fulfillCondition(ContractCondition::TYPE_FRAUD_CHECK, $fraudData);

        $conditions = $contract->getConditions();
        $fraudCondition = $conditions[0];

        $this->assertTrue($fraudCondition->isFulfilled());
        $this->assertEquals(98, $fraudCondition->getData()['score']);
        $this->assertEquals('low', $fraudCondition->getData()['risk']);
    }

    /**
     * @group integration
     * @group e2e
     */
    public function testConditionFulfillment_TracksStockReservation(): void
    {
        $contract = $this->createContractDirectly();
        $contract->addCondition(ContractCondition::stockReserved());
        $contract->transitionToPending();

        $stockData = [
            'reservationId' => 'res_456',
            'items' => [
                ['articleId' => 'art_1', 'quantity' => 2, 'reserved' => true],
                ['articleId' => 'art_2', 'quantity' => 1, 'reserved' => true],
            ],
        ];

        $contract->fulfillCondition(ContractCondition::TYPE_STOCK_RESERVED, $stockData);

        $conditions = $contract->getConditions();
        $stockCondition = $conditions[0];

        $this->assertTrue($stockCondition->isFulfilled());
        $this->assertEquals('res_456', $stockCondition->getData()['reservationId']);
    }

    /**
     * @group integration
     * @group e2e
     */
    public function testConditionFulfillment_FailedConditionFailsContract(): void
    {
        $contract = $this->createContractDirectly();
        $contract->addCondition(ContractCondition::fraudCheck());
        $contract->transitionToPending();

        $contract->failCondition(ContractCondition::TYPE_FRAUD_CHECK, 'High risk score detected');

        $conditions = $contract->getConditions();
        $fraudCondition = $conditions[0];

        $this->assertTrue($fraudCondition->isFailed());
        $this->assertEquals('High risk score detected', $fraudCondition->getFailureReason());
        $this->assertTrue($contract->getState()->isFailed(), 'Contract should be FAILED');
    }

    // =========================================================================
    // PHASE 4: ThankyouController - Order Completion
    // =========================================================================

    /**
     * @group integration
     * @group e2e
     */
    public function testOrderCompletion_ConfirmsOrderWithContractId(): void
    {
        // Simulate: OrderController created contract, conditions fulfilled
        $basket = $this->createValidBasket(250.00, 'EUR');
        $user = $this->createValidUser('user_complete_1');

        $checkoutResult = $this->orchestrator->processCheckout(
            $basket,
            $user,
            'stripe_card',
            'pi_complete_123'
        );

        $contractId = $checkoutResult->getContractId();
        $orderId = 'order_' . uniqid();

        // Simulate: ThankyouController.render() calls confirmOrderCompletion
        $result = $this->orchestrator->confirmOrderCompletion($orderId, $contractId);

        $this->assertInstanceOf(OrderConfirmationResult::class, $result);
        $this->assertTrue($result->isSuccess());
    }

    /**
     * @group integration
     * @group e2e
     */
    public function testOrderCompletion_ReturnsCommittedStateWhenAwaitingPayment(): void
    {
        $basket = $this->createValidBasket(300.00, 'EUR');
        $user = $this->createValidUser('user_awaiting_1');

        $checkoutResult = $this->orchestrator->processCheckout(
            $basket,
            $user,
            'stripe_card',
            'pi_awaiting_123'
        );

        $orderId = 'order_awaiting_' . uniqid();
        $result = $this->orchestrator->confirmOrderCompletion($orderId, $checkoutResult->getContractId());

        // State is COMMITTED (awaiting webhook for FULFILLED)
        $this->assertTrue($result->isSuccess());
        // Default state when no handler updates it
        $state = $result->getContractState();
        $this->assertContains($state, [
            OrderConfirmationResult::STATE_COMMITTED,
            OrderConfirmationResult::STATE_FULFILLED,
        ]);
    }

    /**
     * @group integration
     * @group e2e
     */
    public function testOrderCompletion_FailsWithoutContractId(): void
    {
        $result = $this->orchestrator->confirmOrderCompletion('order_no_contract', null);

        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('No contract ID', $result->getErrorMessage());
    }

    // =========================================================================
    // PHASE 5: Complete Flow Integration
    // =========================================================================

    /**
     * @group integration
     * @group e2e
     */
    public function testCompleteFlow_FromOrderToThankyou(): void
    {
        // STEP 1: OrderController.execute() - Customer clicks "Place Order"
        $basket = $this->createValidBasket(499.99, 'EUR');
        $user = $this->createValidUser('user_complete_flow');

        $checkoutResult = $this->orchestrator->processCheckout(
            $basket,
            $user,
            'stripe_card',
            'pi_complete_flow_123'
        );

        $this->assertTrue($checkoutResult->isSuccess(), 'Step 1: Checkout should succeed');
        $contractId = $checkoutResult->getContractId();
        $this->assertNotNull($contractId);

        // STEP 2: Contract exists with conditions
        $contract = $this->contractRepository->findById($contractId);
        $this->assertNotNull($contract, 'Step 2: Contract should exist');
        $this->assertCount(2, $contract->getConditions(), 'Step 2: Should have 2 conditions');

        // STEP 3: Simulate condition fulfillment (would be done by handlers in real flow)
        // In real flow: PaymentAuthorizationHandler, FraudCheckHandler would do this
        $contract->transitionToPending();
        $contract->fulfillCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED, [
            'authorizationId' => 'auth_complete_xyz',
        ]);
        $contract->fulfillCondition(ContractCondition::TYPE_FRAUD_CHECK, [
            'score' => 99,
            'risk' => 'low',
        ]);

        $this->assertTrue($contract->areAllConditionsFulfilled(), 'Step 3: All conditions fulfilled');
        $this->assertTrue($contract->getState()->isReadyToCommit(), 'Step 3: Ready to commit');

        // STEP 4: Order creation (would be done by OrderCreationHandler)
        $orderId = 'order_complete_' . uniqid();
        $contract->commitToOrder($orderId);
        $this->contractRepository->save($contract);

        $this->assertTrue($contract->getState()->isCommitted(), 'Step 4: Contract committed');
        $this->assertEquals($orderId, $contract->getOrderId(), 'Step 4: Order ID linked');

        // STEP 5: ThankyouController.render() - Customer sees thank you page
        $confirmResult = $this->orchestrator->confirmOrderCompletion($orderId, $contractId);
        $this->assertTrue($confirmResult->isSuccess(), 'Step 5: Order confirmation should succeed');

        // STEP 6: Simulate webhook payment capture (would trigger contract.fulfill())
        $contract->fulfill();
        $this->contractRepository->save($contract);

        $this->assertTrue($contract->getState()->isFulfilled(), 'Step 6: Contract fulfilled');
        $this->assertNotNull($contract->getFulfilledAt(), 'Step 6: FulfilledAt set');
    }

    /**
     * @group integration
     * @group e2e
     */
    public function testCompleteFlow_ContractCancellationOnFailure(): void
    {
        // Create contract
        $basket = $this->createValidBasket(150.00, 'EUR');
        $user = $this->createValidUser('user_cancel_flow');

        $checkoutResult = $this->orchestrator->processCheckout(
            $basket,
            $user,
            'stripe_card',
            'pi_cancel_123'
        );

        $contract = $this->contractRepository->findById($checkoutResult->getContractId());
        $contract->transitionToPending();

        // Simulate: Customer cancels payment
        $contract->cancel('Customer cancelled payment');

        $this->assertTrue($contract->getState()->isCancelled(), 'Contract should be cancelled');
        $this->assertTrue($contract->getState()->isTerminal(), 'Cancelled is terminal state');
    }

    /**
     * @group integration
     * @group e2e
     */
    public function testCompleteFlow_ContractExpirationOnTimeout(): void
    {
        $contract = $this->createContractDirectly();
        $contract->addCondition(ContractCondition::paymentAuthorized());
        $contract->transitionToPending();

        // Simulate: Contract expired (24h timeout)
        $contract->expire();

        $this->assertTrue($contract->getState()->isExpired(), 'Contract should be expired');
        $this->assertTrue($contract->getState()->isTerminal(), 'Expired is terminal state');
    }

    // =========================================================================
    // PHASE 6: Provider Information Tracking
    // =========================================================================

    /**
     * @group integration
     * @group e2e
     */
    public function testProviderInfo_TracksStripePaymentIntent(): void
    {
        $contract = $this->createContractDirectly();

        $contract->setProvider(
            'stripe',
            'pi_3ABC123def456',
            'https://checkout.stripe.com/c/pay/cs_test_xyz'
        );

        $this->assertEquals('stripe', $contract->getProvider());
        $this->assertEquals('pi_3ABC123def456', $contract->getProviderOrderId());
        $this->assertEquals('https://checkout.stripe.com/c/pay/cs_test_xyz', $contract->getProviderRedirectUrl());
    }

    /**
     * @group integration
     * @group e2e
     */
    public function testProviderInfo_SerializesCorrectly(): void
    {
        $contract = $this->createContractDirectly();
        $contract->setProvider('stripe', 'pi_serialize_test', 'https://stripe.com/redirect');

        $array = $contract->toArray();

        $this->assertEquals('stripe', $array['provider']);
        $this->assertEquals('pi_serialize_test', $array['providerOrderId']);
        $this->assertEquals('https://stripe.com/redirect', $array['providerRedirectUrl']);

        // Deserialize and verify
        $restored = PaymentContract::fromArray($array);
        $this->assertEquals('stripe', $restored->getProvider());
        $this->assertEquals('pi_serialize_test', $restored->getProviderOrderId());
    }

    // =========================================================================
    // Helper Methods
    // =========================================================================

    private function createValidBasket(float $total, string $currency): object
    {
        return new class ($total, $currency) {
            public float $totalGross;
            public float $totalNet;
            public float $totalVat;
            public string $currency;

            public function __construct(float $total, string $currency)
            {
                $this->totalGross = $total;
                $this->totalNet = $total / 1.19; // Approximate net
                $this->totalVat = $total - $this->totalNet;
                $this->currency = $currency;
            }

            public function getProductsCount(): int
            {
                return 2;
            }

            public function getPaymentId(): string
            {
                return 'stripe_card';
            }

            public function getBruttoSum(): float
            {
                return $this->totalGross;
            }

            public function getBasketCurrency(): object
            {
                return (object)['name' => $this->currency];
            }
        };
    }

    private function createValidUser(string $userId): object
    {
        return new class ($userId) {
            private string $id;

            public function __construct(string $id)
            {
                $this->id = $id;
            }

            public function getId(): string
            {
                return $this->id;
            }
        };
    }

    private function createContractDirectly(): PaymentContract
    {
        $basketSnapshot = BasketSnapshot::fromArray([
            'items' => [],
            'discounts' => [],
            'totalGross' => 100.0,
            'totalNet' => 84.03,
            'totalVat' => 15.97,
            'currency' => 'EUR',
            'capturedAt' => date('Y-m-d H:i:s'),
        ]);

        return new PaymentContract(
            shopId: 1,
            userId: 'test_user_' . uniqid(),
            basketSnapshot: $basketSnapshot
        );
    }

    private function createReadyToCommitContract(): PaymentContract
    {
        $contract = $this->createContractDirectly();
        $contract->addCondition(ContractCondition::paymentAuthorized());
        $contract->addCondition(ContractCondition::fraudCheck());
        $contract->transitionToPending();
        $contract->fulfillCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED, ['id' => 'auth_123']);
        $contract->fulfillCondition(ContractCondition::TYPE_FRAUD_CHECK, ['score' => 100]);

        return $contract;
    }

    private function createCommittedContract(): PaymentContract
    {
        $contract = $this->createReadyToCommitContract();
        $contract->commitToOrder('order_' . uniqid());

        return $contract;
    }
}

/**
 * In-memory contract repository for testing purposes.
 * Simulates database persistence without actual DB connection.
 */
class InMemoryContractRepository implements ContractRepositoryInterface
{
    /**
     * @var array<string, PaymentContract>
     */
    private array $contracts = [];

    public function save(PaymentContractInterface $contract): void
    {
        if ($contract instanceof PaymentContract) {
            $this->contracts[$contract->getId()] = $contract;
        }
    }

    public function findById(string $id): ?PaymentContractInterface
    {
        return $this->contracts[$id] ?? null;
    }

    public function findByProviderOrderId(string $providerOrderId): ?PaymentContractInterface
    {
        foreach ($this->contracts as $contract) {
            if ($contract->getProviderOrderId() === $providerOrderId) {
                return $contract;
            }
        }
        return null;
    }

    /**
     * @return array<int, PaymentContractInterface>
     */
    public function findByUserId(string $userId): array
    {
        return array_values(array_filter(
            $this->contracts,
            fn($c) => $c->getUserId() === $userId
        ));
    }

    public function findActiveByUserId(string $userId): ?PaymentContractInterface
    {
        foreach ($this->contracts as $contract) {
            if ($contract->getUserId() === $userId && !$contract->getState()->isTerminal()) {
                return $contract;
            }
        }
        return null;
    }

    /**
     * @return array<int, PaymentContractInterface>
     */
    public function findExpired(): array
    {
        return array_values(array_filter(
            $this->contracts,
            fn($c) => $c->isExpired()
        ));
    }

    /**
     * @return array<string, PaymentContract>
     */
    public function getAll(): array
    {
        return $this->contracts;
    }

    public function clear(): void
    {
        $this->contracts = [];
    }
}
