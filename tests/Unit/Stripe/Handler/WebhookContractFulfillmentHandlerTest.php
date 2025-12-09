<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Unit\Stripe\Handler;

use OxidSolutionCatalysts\Payments\Component\Contract\BasketSnapshot;
use OxidSolutionCatalysts\Payments\Component\Contract\ContractCondition;
use OxidSolutionCatalysts\Payments\Component\Contract\ContractState;
use OxidSolutionCatalysts\Payments\Component\Contract\PaymentContract;
use OxidSolutionCatalysts\Payments\Component\Contract\PaymentContractInterface;
use OxidSolutionCatalysts\Payments\Component\Repository\ContractRepositoryInterface;
use OxidSolutionCatalysts\Payments\Component\Service\ContractFulfillmentServiceInterface;
use OxidSolutionCatalysts\Payments\Stripe\Handler\WebhookContractFulfillmentHandler;
use PHPUnit\Framework\TestCase;

/**
 * TDD Tests for WebhookContractFulfillmentHandler
 *
 * Sprint 6: Contract-Aware Webhook Processing
 * Sprint 18: Uses ContractFulfillmentService for DRY fulfillment
 *
 * This handler bridges Stripe webhooks to the contract state machine by:
 * 1. Looking up contract by providerOrderId (payment intent ID)
 * 2. Delegating fulfillment to ContractFulfillmentService
 * 3. Handling capture/refund amounts
 *
 * @group sprint-6
 * @group sprint-18
 * @group contract-aware
 */
class WebhookContractFulfillmentHandlerTest extends TestCase
{
    private ContractRepositoryInterface $contractRepository;
    private ContractFulfillmentServiceInterface $contractFulfillmentService;

    protected function setUp(): void
    {
        $this->contractRepository = $this->createMock(ContractRepositoryInterface::class);
        $this->contractFulfillmentService = $this->createMock(ContractFulfillmentServiceInterface::class);
    }

    // =========================================================================
    // Test 1: Handler finds contract by provider order ID
    // =========================================================================

    /**
     * @test
     * @group sprint-6
     * @group sprint-18
     * @group contract-aware
     */
    public function handlerFindsContractByProviderOrderId(): void
    {
        $providerOrderId = 'pi_test_123';

        // Sprint 18: Delegates to ContractFulfillmentService
        $this->contractFulfillmentService
            ->expects($this->once())
            ->method('fulfillByProviderOrderId')
            ->with($providerOrderId)
            ->willReturn(true);

        $handler = new WebhookContractFulfillmentHandler(
            $this->contractRepository,
            $this->contractFulfillmentService
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
     * @group sprint-18
     * @group contract-aware
     */
    public function handlerSkipsAlreadyFulfilledContract(): void
    {
        $providerOrderId = 'pi_already_fulfilled';

        // Sprint 18: Delegates to ContractFulfillmentService which returns false
        $this->contractFulfillmentService
            ->expects($this->once())
            ->method('fulfillByProviderOrderId')
            ->with($providerOrderId)
            ->willReturn(false);

        $handler = new WebhookContractFulfillmentHandler(
            $this->contractRepository,
            $this->contractFulfillmentService
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
     * @group sprint-18
     * @group contract-aware
     */
    public function handlerRejectsNonCommittedContract(): void
    {
        $providerOrderId = 'pi_pending_contract';

        // Sprint 18: Delegates to ContractFulfillmentService which returns false for non-committed
        $this->contractFulfillmentService
            ->expects($this->once())
            ->method('fulfillByProviderOrderId')
            ->with($providerOrderId)
            ->willReturn(false);

        $handler = new WebhookContractFulfillmentHandler(
            $this->contractRepository,
            $this->contractFulfillmentService
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
     * @group sprint-18
     * @group contract-aware
     */
    public function handlerTransitionsContractToFulfilled(): void
    {
        $providerOrderId = 'pi_to_fulfill';

        // Sprint 18: Delegates to ContractFulfillmentService
        $this->contractFulfillmentService
            ->expects($this->once())
            ->method('fulfillByProviderOrderId')
            ->with($providerOrderId)
            ->willReturn(true);

        $handler = new WebhookContractFulfillmentHandler(
            $this->contractRepository,
            $this->contractFulfillmentService
        );

        $result = $handler->handlePaymentSucceeded($providerOrderId);

        $this->assertTrue($result);
    }

    // =========================================================================
    // Test 5: Handler dispatches ContractFulfilledEvent
    // =========================================================================

    /**
     * @test
     * @group sprint-6
     * @group sprint-18
     * @group contract-aware
     */
    public function handlerDelegatesEventDispatchToService(): void
    {
        $providerOrderId = 'pi_dispatch_event';

        // Sprint 18: Event dispatch is handled by ContractFulfillmentService
        $this->contractFulfillmentService
            ->expects($this->once())
            ->method('fulfillByProviderOrderId')
            ->with($providerOrderId)
            ->willReturn(true);

        $handler = new WebhookContractFulfillmentHandler(
            $this->contractRepository,
            $this->contractFulfillmentService
        );

        $result = $handler->handlePaymentSucceeded($providerOrderId);

        $this->assertTrue($result);
    }

    // =========================================================================
    // Test 6: Handler returns order ID from contract
    // =========================================================================

    /**
     * @test
     * @group sprint-6
     * @group sprint-18
     * @group contract-aware
     */
    public function handlerReturnsResultFromService(): void
    {
        $providerOrderId = 'pi_get_order_id';

        // Sprint 18: Return value comes from service
        $this->contractFulfillmentService
            ->expects($this->once())
            ->method('fulfillByProviderOrderId')
            ->with($providerOrderId)
            ->willReturn(true);

        $handler = new WebhookContractFulfillmentHandler(
            $this->contractRepository,
            $this->contractFulfillmentService
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
     * @group sprint-18
     * @group contract-aware
     */
    public function handlerReturnsNullWhenContractNotFound(): void
    {
        $providerOrderId = 'pi_no_contract';

        // Sprint 18: Service returns null when contract not found
        $this->contractFulfillmentService
            ->expects($this->once())
            ->method('fulfillByProviderOrderId')
            ->with($providerOrderId)
            ->willReturn(null);

        $handler = new WebhookContractFulfillmentHandler(
            $this->contractRepository,
            $this->contractFulfillmentService
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
     * @group sprint-18
     * @group contract-aware
     */
    public function handlerHandlesChargeCapturedEvent(): void
    {
        $providerOrderId = 'pi_charge_captured';

        $contract = $this->createCommittedContractMock($providerOrderId, 'order_captured');

        $this->contractRepository
            ->method('findByProviderOrderId')
            ->willReturn($contract);

        // Sprint 18: Uses fulfill() method which delegates to service
        $this->contractFulfillmentService
            ->expects($this->once())
            ->method('fulfill')
            ->with($contract)
            ->willReturn(true);

        $handler = new WebhookContractFulfillmentHandler(
            $this->contractRepository,
            $this->contractFulfillmentService
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
     * @group sprint-18
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
            $this->contractFulfillmentService
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
     * @group sprint-18
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
            $this->contractFulfillmentService
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
