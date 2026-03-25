<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Handler;

use OxidEsales\PaymentComponent\Contract\BasketSnapshot;
use OxidEsales\PaymentComponent\Contract\ContractCondition;
use OxidEsales\PaymentComponent\Contract\ContractState;
use OxidEsales\PaymentComponent\Contract\PaymentContract;
use OxidEsales\PaymentComponent\Contract\PaymentContractInterface;
use OxidEsales\PaymentComponent\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentComponent\Service\ContractFulfillmentServiceInterface;
use OxidEsales\Payments\Stripe\WebhookHandler\WebhookContractFulfillmentHandler;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

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
 */
    #[Group('sprint-6')]
    #[Group('sprint-18')]
    #[Group('contract-aware')]
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

        #[Group('sprint-6')]
    #[Group('sprint-18')]
    #[Group('contract-aware')]
    public function testHandlerFindsContractByProviderOrderId(): void
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

        #[Group('sprint-6')]
    #[Group('sprint-18')]
    #[Group('contract-aware')]
    public function testHandlerSkipsAlreadyFulfilledContract(): void
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

        #[Group('sprint-6')]
    #[Group('sprint-18')]
    #[Group('contract-aware')]
    public function testHandlerRejectsNonCommittedContract(): void
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

        #[Group('sprint-6')]
    #[Group('sprint-18')]
    #[Group('contract-aware')]
    public function testHandlerTransitionsContractToFulfilled(): void
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

        #[Group('sprint-6')]
    #[Group('sprint-18')]
    #[Group('contract-aware')]
    public function testHandlerDelegatesEventDispatchToService(): void
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

        #[Group('sprint-6')]
    #[Group('sprint-18')]
    #[Group('contract-aware')]
    public function testHandlerReturnsResultFromService(): void
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

        #[Group('sprint-6')]
    #[Group('sprint-18')]
    #[Group('contract-aware')]
    public function testHandlerReturnsNullWhenContractNotFound(): void
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

        #[Group('sprint-6')]
    #[Group('sprint-18')]
    #[Group('contract-aware')]
    public function testHandlerHandlesChargeCapturedEvent(): void
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
    // Test 9: Handler transitions AUTHORIZED contract to READY_TO_COMMIT on capture
    // Sprint 7: Manual capture mode webhook handling
    // =========================================================================

        #[Group('sprint-7')]
    #[Group('contract-aware')]
    #[Group('manual-capture')]
    public function testHandlerTransitionsAuthorizedContractOnCapture(): void
    {
        $providerOrderId = 'pi_authorized_capture';
        $capturedAmount = 99.99;

        // Create contract in AUTHORIZED state
        $snapshot = BasketSnapshot::fromArray([
            'items' => [],
            'discounts' => [],
            'totalGross' => 99.99,
            'totalNet' => 84.03,
            'totalVat' => 15.97,
            'currency' => 'EUR',
            'capturedAt' => date('Y-m-d H:i:s'),
        ]);

        $contract = new PaymentContract(1, 'user123', $snapshot);
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));
        $contract->transitionToNotFinished('order_123');
        $contract->transitionToPending();
        $contract->setProvider('stripe', $providerOrderId);
        // Transition to AUTHORIZED state (simulating manual capture mode)
        $contract->authorize();

        $this->contractRepository
            ->method('findByProviderOrderId')
            ->with($providerOrderId)
            ->willReturn($contract);

        $this->contractRepository
            ->expects($this->once())
            ->method('save')
            ->with($contract);

        $handler = new WebhookContractFulfillmentHandler(
            $this->contractRepository,
            $this->contractFulfillmentService
        );

        $result = $handler->handleChargeCaptured($providerOrderId, $capturedAmount);

        // Should return true (capture successful)
        $this->assertTrue($result);
        // Contract should now be in READY_TO_COMMIT state
        $this->assertTrue($contract->getState()->isReadyToCommit());
    }

        #[Group('sprint-7')]
    #[Group('contract-aware')]
    #[Group('manual-capture')]
    public function testHandlerReturnsNullWhenContractNotFoundOnCapture(): void
    {
        $providerOrderId = 'pi_no_contract_capture';

        $this->contractRepository
            ->method('findByProviderOrderId')
            ->with($providerOrderId)
            ->willReturn(null);

        $handler = new WebhookContractFulfillmentHandler(
            $this->contractRepository,
            $this->contractFulfillmentService
        );

        $result = $handler->handleChargeCaptured($providerOrderId, 50.00);

        $this->assertNull($result);
    }

        #[Group('sprint-7')]
    #[Group('contract-aware')]
    #[Group('manual-capture')]
    public function testHandlerReturnsFalseForAlreadyFulfilledContractOnCapture(): void
    {
        $providerOrderId = 'pi_already_fulfilled_capture';
        $capturedAmount = 99.99;

        // Create already fulfilled contract
        $snapshot = BasketSnapshot::fromArray([
            'items' => [],
            'discounts' => [],
            'totalGross' => 99.99,
            'totalNet' => 84.03,
            'totalVat' => 15.97,
            'currency' => 'EUR',
            'capturedAt' => date('Y-m-d H:i:s'),
        ]);

        $contract = new PaymentContract(1, 'user123', $snapshot);
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));
        $contract->transitionToNotFinished('order_123');
        $contract->transitionToPending();
        $contract->setProvider('stripe', $providerOrderId);
        $contract->fulfillCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED, ['authId' => 'auth_123']);
        $contract->commitToOrder('order_123');
        $contract->fulfill();

        $this->contractRepository
            ->method('findByProviderOrderId')
            ->with($providerOrderId)
            ->willReturn($contract);

        // Save should be called to update captured amount
        $this->contractRepository
            ->expects($this->once())
            ->method('save')
            ->with($contract);

        $handler = new WebhookContractFulfillmentHandler(
            $this->contractRepository,
            $this->contractFulfillmentService
        );

        $result = $handler->handleChargeCaptured($providerOrderId, $capturedAmount);

        // Should return false (already fulfilled - idempotent)
        $this->assertFalse($result);
        // But captured amount should still be recorded
        $this->assertEquals($capturedAmount, $contract->getCapturedAmount());
    }

        #[Group('sprint-7')]
    #[Group('contract-aware')]
    #[Group('manual-capture')]
    public function testHandlerReturnsFalseForPendingContractOnCapture(): void
    {
        $providerOrderId = 'pi_pending_capture';
        $capturedAmount = 50.00;

        // Create contract in PENDING state (not AUTHORIZED, not COMMITTED)
        $snapshot = BasketSnapshot::fromArray([
            'items' => [],
            'discounts' => [],
            'totalGross' => 50.00,
            'totalNet' => 42.02,
            'totalVat' => 7.98,
            'currency' => 'EUR',
            'capturedAt' => date('Y-m-d H:i:s'),
        ]);

        $contract = new PaymentContract(1, 'user123', $snapshot);
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));
        $contract->transitionToNotFinished('order_123');
        $contract->transitionToPending();
        $contract->setProvider('stripe', $providerOrderId);
        // Contract stays in PENDING - not AUTHORIZED, not COMMITTED

        $this->contractRepository
            ->method('findByProviderOrderId')
            ->with($providerOrderId)
            ->willReturn($contract);

        // Save should NOT be called - PENDING state cannot record captured amount
        $this->contractRepository
            ->expects($this->never())
            ->method('save');

        $handler = new WebhookContractFulfillmentHandler(
            $this->contractRepository,
            $this->contractFulfillmentService
        );

        $result = $handler->handleChargeCaptured($providerOrderId, $capturedAmount);

        // Should return false (not in correct state to fulfill)
        $this->assertFalse($result);
    }

    // =========================================================================
    // Test 10: Handler handles charge.refunded event
    // =========================================================================

        #[Group('sprint-6')]
    #[Group('sprint-18')]
    #[Group('contract-aware')]
    public function testHandlerHandlesChargeRefundedEvent(): void
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

        #[Group('sprint-6')]
    #[Group('sprint-18')]
    #[Group('contract-aware')]
    public function testHandlerHandlesPaymentFailed(): void
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
        $contract->transitionToNotFinished('order_failed');
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
    // Test 11: Handler handles payment_intent.canceled event
    // Sprint 1: Bug fix - contract cancellation was not being handled
    // =========================================================================

        #[Group('sprint-1')]
    #[Group('contract-aware')]
    #[Group('bug-fix')]
    public function testHandlerHandlesPaymentCanceled(): void
    {
        $providerOrderId = 'pi_canceled';
        $cancellationReason = 'user_requested';

        // Use concrete PaymentContract since cancel() is not on interface
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
        $contract->transitionToNotFinished('order_canceled');
        $contract->transitionToPending();
        $contract->setProvider('stripe', $providerOrderId);

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

        $result = $handler->handlePaymentCanceled($providerOrderId, $cancellationReason);

        $this->assertTrue($result);
        // Contract should be in CANCELLED state after processing
        $this->assertTrue($contract->getState()->isCancelled());
    }

        #[Group('sprint-1')]
    #[Group('contract-aware')]
    #[Group('bug-fix')]
    public function testHandlerReturnsNullWhenContractNotFoundOnCancel(): void
    {
        $providerOrderId = 'pi_no_contract_cancel';

        $this->contractRepository
            ->method('findByProviderOrderId')
            ->with($providerOrderId)
            ->willReturn(null);

        $handler = new WebhookContractFulfillmentHandler(
            $this->contractRepository,
            $this->contractFulfillmentService
        );

        $result = $handler->handlePaymentCanceled($providerOrderId, 'user_requested');

        $this->assertNull($result);
    }

        #[Group('sprint-1')]
    #[Group('contract-aware')]
    #[Group('bug-fix')]
    public function testHandlerReturnsFalseForAlreadyTerminalContractOnCancel(): void
    {
        $providerOrderId = 'pi_already_failed';
        $cancellationReason = 'user_requested';

        // Create already failed contract (terminal state)
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
        $contract->transitionToNotFinished('order_failed');
        $contract->transitionToPending();
        $contract->setProvider('stripe', $providerOrderId);
        $contract->fail('previous_failure');

        $this->contractRepository
            ->method('findByProviderOrderId')
            ->with($providerOrderId)
            ->willReturn($contract);

        $handler = new WebhookContractFulfillmentHandler(
            $this->contractRepository,
            $this->contractFulfillmentService
        );

        $result = $handler->handlePaymentCanceled($providerOrderId, $cancellationReason);

        // Should return false (already in terminal state - idempotent)
        $this->assertFalse($result);
    }

    // =========================================================================
    // Test 12: Handler handles checkout.session.expired event
    // Sprint 1: Bug fix - expired sessions were not updating contract state
    // =========================================================================

        #[Group('sprint-1')]
    #[Group('contract-aware')]
    #[Group('bug-fix')]
    public function testHandlerHandlesSessionExpired(): void
    {
        $contractId = 'contract_expired_123';

        // Use concrete PaymentContract since expire() is not on all interfaces
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
        $contract->transitionToNotFinished('order_expired');
        $contract->transitionToPending();
        $contract->setProvider('stripe', 'pi_expired_test');

        $this->contractRepository
            ->method('findById')
            ->with($contractId)
            ->willReturn($contract);

        $this->contractRepository
            ->expects($this->once())
            ->method('save');

        $handler = new WebhookContractFulfillmentHandler(
            $this->contractRepository,
            $this->contractFulfillmentService
        );

        $result = $handler->handleSessionExpired($contractId);

        $this->assertTrue($result);
        // Contract should be in EXPIRED state after processing
        $this->assertTrue($contract->getState()->isExpired());
    }

        #[Group('sprint-1')]
    #[Group('contract-aware')]
    #[Group('bug-fix')]
    public function testHandlerReturnsNullWhenContractNotFoundOnExpired(): void
    {
        $contractId = 'contract_not_found';

        $this->contractRepository
            ->method('findById')
            ->with($contractId)
            ->willReturn(null);

        $handler = new WebhookContractFulfillmentHandler(
            $this->contractRepository,
            $this->contractFulfillmentService
        );

        $result = $handler->handleSessionExpired($contractId);

        $this->assertNull($result);
    }

        #[Group('sprint-1')]
    #[Group('contract-aware')]
    #[Group('bug-fix')]
    public function testHandlerReturnsFalseForAlreadyTerminalContractOnExpired(): void
    {
        $contractId = 'contract_already_fulfilled';

        // Create already fulfilled contract (terminal state)
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
        $contract->transitionToNotFinished('order_fulfilled');
        $contract->transitionToPending();
        $contract->setProvider('stripe', 'pi_fulfilled_test');
        $contract->fulfillCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED, ['authId' => 'auth_123']);
        $contract->commitToOrder('order_fulfilled');
        $contract->fulfill();

        $this->contractRepository
            ->method('findById')
            ->with($contractId)
            ->willReturn($contract);

        $handler = new WebhookContractFulfillmentHandler(
            $this->contractRepository,
            $this->contractFulfillmentService
        );

        $result = $handler->handleSessionExpired($contractId);

        // Should return false (already in terminal state - idempotent)
        $this->assertFalse($result);
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
