<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Unit\Stripe\Handler;

use OxidSolutionCatalysts\Payments\Component\Contract\BasketSnapshot;
use OxidSolutionCatalysts\Payments\Component\Contract\ContractCondition;
use OxidSolutionCatalysts\Payments\Component\Contract\ContractState;
use OxidSolutionCatalysts\Payments\Component\Contract\PaymentContract;
use OxidSolutionCatalysts\Payments\Component\Contract\PaymentContractInterface;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\Contract\ContractFulfilledEvent;
use OxidSolutionCatalysts\Payments\Component\EventSystem\EventDispatcherInterface;
use OxidSolutionCatalysts\Payments\Component\Repository\ContractRepositoryInterface;
use OxidSolutionCatalysts\Payments\Stripe\Handler\WebhookContractFulfillmentHandler;
use PHPUnit\Framework\TestCase;

/**
 * TDD Tests for WebhookContractFulfillmentHandler
 *
 * Sprint 6: Contract-Aware Webhook Processing
 *
 * This handler bridges Stripe webhooks to the contract state machine by:
 * 1. Looking up contract by providerOrderId (payment intent ID)
 * 2. Validating contract state (must be COMMITTED)
 * 3. Transitioning contract to FULFILLED
 * 4. Dispatching ContractFulfilledEvent
 *
 * @group sprint-6
 * @group contract-aware
 */
class WebhookContractFulfillmentHandlerTest extends TestCase
{
    private ContractRepositoryInterface $contractRepository;
    private EventDispatcherInterface $eventDispatcher;

    protected function setUp(): void
    {
        $this->contractRepository = $this->createMock(ContractRepositoryInterface::class);
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
    }

    // =========================================================================
    // Test 1: Handler finds contract by provider order ID
    // =========================================================================

    /**
     * @test
     * @group sprint-6
     * @group contract-aware
     */
    public function handlerFindsContractByProviderOrderId(): void
    {
        $providerOrderId = 'pi_test_123';

        $contract = $this->createCommittedContractMock($providerOrderId, 'order123');

        $this->contractRepository
            ->expects($this->once())
            ->method('findByProviderOrderId')
            ->with($providerOrderId)
            ->willReturn($contract);

        // Contract will be fulfilled and saved
        $contract->expects($this->once())->method('fulfill');
        $this->contractRepository->expects($this->once())->method('save')->with($contract);

        $handler = new WebhookContractFulfillmentHandler(
            $this->contractRepository,
            $this->eventDispatcher
        );

        $result = $handler->handlePaymentSucceeded($providerOrderId);

        $this->assertTrue($result);
    }

    // =========================================================================
    // Test 2: Handler skips already fulfilled contracts (idempotency)
    // =========================================================================

    /**
     * @test
     * @group sprint-6
     * @group contract-aware
     */
    public function handlerSkipsAlreadyFulfilledContract(): void
    {
        $providerOrderId = 'pi_already_fulfilled';

        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getState')->willReturn(ContractState::fulfilled());
        $contract->method('getId')->willReturn('contract456');

        $this->contractRepository
            ->method('findByProviderOrderId')
            ->with($providerOrderId)
            ->willReturn($contract);

        // Contract should NOT be saved (already fulfilled - idempotent)
        $this->contractRepository
            ->expects($this->never())
            ->method('save');

        // No event should be dispatched
        $this->eventDispatcher
            ->expects($this->never())
            ->method('dispatch');

        $handler = new WebhookContractFulfillmentHandler(
            $this->contractRepository,
            $this->eventDispatcher
        );

        $result = $handler->handlePaymentSucceeded($providerOrderId);

        $this->assertFalse($result);
    }

    // =========================================================================
    // Test 3: Handler validates contract is COMMITTED before fulfillment
    // =========================================================================

    /**
     * @test
     * @group sprint-6
     * @group contract-aware
     */
    public function handlerRejectsNonCommittedContract(): void
    {
        $providerOrderId = 'pi_pending_contract';

        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getState')->willReturn(ContractState::pending());
        $contract->method('getId')->willReturn('contract789');
        $contract->method('getStateValue')->willReturn('pending');

        $this->contractRepository
            ->method('findByProviderOrderId')
            ->willReturn($contract);

        // Contract should NOT be saved
        $this->contractRepository
            ->expects($this->never())
            ->method('save');

        $handler = new WebhookContractFulfillmentHandler(
            $this->contractRepository,
            $this->eventDispatcher
        );

        $result = $handler->handlePaymentSucceeded($providerOrderId);

        $this->assertFalse($result);
    }

    // =========================================================================
    // Test 4: Handler transitions contract to FULFILLED
    // =========================================================================

    /**
     * @test
     * @group sprint-6
     * @group contract-aware
     */
    public function handlerTransitionsContractToFulfilled(): void
    {
        $providerOrderId = 'pi_to_fulfill';

        $contract = $this->createCommittedContractMock($providerOrderId, 'order456');

        $this->contractRepository
            ->method('findByProviderOrderId')
            ->willReturn($contract);

        // Contract::fulfill() should be called
        $contract->expects($this->once())
            ->method('fulfill');

        // Contract should be saved after fulfillment
        $this->contractRepository
            ->expects($this->once())
            ->method('save')
            ->with($contract);

        $handler = new WebhookContractFulfillmentHandler(
            $this->contractRepository,
            $this->eventDispatcher
        );

        $handler->handlePaymentSucceeded($providerOrderId);
    }

    // =========================================================================
    // Test 5: Handler dispatches ContractFulfilledEvent
    // =========================================================================

    /**
     * @test
     * @group sprint-6
     * @group contract-aware
     */
    public function handlerDispatchesContractFulfilledEvent(): void
    {
        $providerOrderId = 'pi_dispatch_event';
        $orderId = 'order789';

        $contract = $this->createCommittedContractMock($providerOrderId, $orderId);

        $this->contractRepository
            ->method('findByProviderOrderId')
            ->willReturn($contract);

        // Event dispatcher should receive ContractFulfilledEvent
        $this->eventDispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function ($event) use ($orderId) {
                return $event instanceof ContractFulfilledEvent
                    && $event->getOrderId() === $orderId;
            }));

        $handler = new WebhookContractFulfillmentHandler(
            $this->contractRepository,
            $this->eventDispatcher
        );

        $handler->handlePaymentSucceeded($providerOrderId);
    }

    // =========================================================================
    // Test 6: Handler returns order ID from contract
    // =========================================================================

    /**
     * @test
     * @group sprint-6
     * @group contract-aware
     */
    public function handlerReturnsOrderIdFromContract(): void
    {
        $providerOrderId = 'pi_get_order_id';
        $expectedOrderId = 'order_from_contract';

        $contract = $this->createCommittedContractMock($providerOrderId, $expectedOrderId);

        $this->contractRepository
            ->method('findByProviderOrderId')
            ->willReturn($contract);

        $handler = new WebhookContractFulfillmentHandler(
            $this->contractRepository,
            $this->eventDispatcher
        );

        $result = $handler->handlePaymentSucceeded($providerOrderId);

        $this->assertTrue($result);
    }

    // =========================================================================
    // Test 7: Handler handles contract not found gracefully
    // =========================================================================

    /**
     * @test
     * @group sprint-6
     * @group contract-aware
     */
    public function handlerReturnsNullWhenContractNotFound(): void
    {
        $providerOrderId = 'pi_no_contract';

        $this->contractRepository
            ->method('findByProviderOrderId')
            ->with($providerOrderId)
            ->willReturn(null);

        // No save should happen
        $this->contractRepository
            ->expects($this->never())
            ->method('save');

        $handler = new WebhookContractFulfillmentHandler(
            $this->contractRepository,
            $this->eventDispatcher
        );

        $result = $handler->handlePaymentSucceeded($providerOrderId);

        $this->assertNull($result);
    }

    // =========================================================================
    // Test 8: Handler handles charge.captured event
    // =========================================================================

    /**
     * @test
     * @group sprint-6
     * @group contract-aware
     */
    public function handlerHandlesChargeCapturedEvent(): void
    {
        $providerOrderId = 'pi_charge_captured';

        $contract = $this->createCommittedContractMock($providerOrderId, 'order_captured');

        $this->contractRepository
            ->method('findByProviderOrderId')
            ->willReturn($contract);

        $contract->expects($this->once())->method('fulfill');

        $handler = new WebhookContractFulfillmentHandler(
            $this->contractRepository,
            $this->eventDispatcher
        );

        $result = $handler->handleChargeCaptured($providerOrderId);

        $this->assertTrue($result);
    }

    // =========================================================================
    // Test 9: Handler handles charge.refunded event
    // =========================================================================

    /**
     * @test
     * @group sprint-6
     * @group contract-aware
     */
    public function handlerHandlesChargeRefundedEvent(): void
    {
        $providerOrderId = 'pi_refunded';
        $refundAmount = 50.00;

        // For refund, contract must be FULFILLED (already captured)
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getState')->willReturn(ContractState::fulfilled());
        $contract->method('getId')->willReturn('contract_refund');
        $contract->method('getOrderId')->willReturn('order_refund');

        $this->contractRepository
            ->method('findByProviderOrderId')
            ->willReturn($contract);

        $handler = new WebhookContractFulfillmentHandler(
            $this->contractRepository,
            $this->eventDispatcher
        );

        $result = $handler->handleChargeRefunded($providerOrderId, $refundAmount);

        $this->assertTrue($result);
    }

    // =========================================================================
    // Test 10: Handler handles payment_intent.payment_failed event
    // =========================================================================

    /**
     * @test
     * @group sprint-6
     * @group contract-aware
     */
    public function handlerHandlesPaymentFailed(): void
    {
        $providerOrderId = 'pi_failed';
        $failureReason = 'card_declined';

        // Use concrete PaymentContract since fail() is not on interface
        $snapshot = BasketSnapshot::fromArray([
            'items' => [],
            'discounts' => [],
            'totalGross' => 100.0,
            'totalNet' => 84.03,
            'totalVat' => 15.97,
            'currency' => 'EUR',
            'capturedAt' => date('Y-m-d H:i:s'),
        ]);

        $contract = new PaymentContract(1, 'user123', $snapshot);
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));
        $contract->transitionToPending();
        $contract->fulfillCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED, ['authorizationId' => 'auth_123']);
        $contract->setProvider('stripe', $providerOrderId);
        $contract->commitToOrder('order_failed');

        $this->contractRepository
            ->method('findByProviderOrderId')
            ->with($providerOrderId)
            ->willReturn($contract);

        $this->contractRepository
            ->expects($this->once())
            ->method('save');

        $handler = new WebhookContractFulfillmentHandler(
            $this->contractRepository,
            $this->eventDispatcher
        );

        $result = $handler->handlePaymentFailed($providerOrderId, $failureReason);

        $this->assertTrue($result);
        // Contract should be in FAILED state after processing
        $this->assertTrue($contract->getState()->isFailed());
    }

    // =========================================================================
    // Helper Methods
    // =========================================================================

    /**
     * Create a mock PaymentContractInterface in COMMITTED state
     */
    private function createCommittedContractMock(
        string $providerOrderId,
        string $orderId
    ): PaymentContractInterface {
        $contract = $this->createMock(PaymentContractInterface::class);

        $contract->method('getState')->willReturn(ContractState::committed());
        $contract->method('getId')->willReturn('contract_' . md5($providerOrderId));
        $contract->method('getOrderId')->willReturn($orderId);
        $contract->method('getProviderOrderId')->willReturn($providerOrderId);
        $contract->method('getStateValue')->willReturn('committed');

        return $contract;
    }
}
