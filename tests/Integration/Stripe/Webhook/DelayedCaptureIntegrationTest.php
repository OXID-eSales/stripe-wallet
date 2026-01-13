<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Integration\Stripe\Webhook;

use OxidSolutionCatalysts\Payments\Component\Contract\BasketSnapshot;
use OxidSolutionCatalysts\Payments\Component\Contract\ContractCondition;
use OxidSolutionCatalysts\Payments\Component\Contract\ContractState;
use OxidSolutionCatalysts\Payments\Component\Contract\PaymentContract;
use OxidSolutionCatalysts\Payments\Component\Contract\PaymentContractInterface;
use OxidSolutionCatalysts\Payments\Component\Repository\ContractRepositoryInterface;
use OxidSolutionCatalysts\Payments\Component\Service\ContractFulfillmentServiceInterface;
use OxidSolutionCatalysts\Payments\Stripe\Handler\WebhookContractFulfillmentHandler;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for delayed/manual capture feature.
 *
 * Sprint 9: Tests the full delayed capture flow including:
 * - AUTHORIZED state transitions
 * - charge.captured webhook handling
 * - Contract state machine integration
 *
 * @covers \OxidSolutionCatalysts\Payments\Stripe\Handler\WebhookContractFulfillmentHandler
 * @group sprint-7
 * @group sprint-9
 * @group delayed-capture
 * @group webhook
 * @group contract
 */
final class DelayedCaptureIntegrationTest extends TestCase
{
    private ContractRepositoryInterface&MockObject $contractRepository;
    private ContractFulfillmentServiceInterface&MockObject $contractFulfillmentService;
    private WebhookContractFulfillmentHandler $handler;
    private BasketSnapshot $basketSnapshot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contractRepository = $this->createMock(ContractRepositoryInterface::class);
        $this->contractFulfillmentService = $this->createMock(ContractFulfillmentServiceInterface::class);

        $this->handler = new WebhookContractFulfillmentHandler(
            $this->contractRepository,
            $this->contractFulfillmentService
        );

        $this->basketSnapshot = BasketSnapshot::fromArray([
            'items' => [['id' => 'item1', 'qty' => 1, 'price' => 99.99]],
            'discounts' => [],
            'totalGross' => 99.99,
            'totalNet' => 84.03,
            'totalVat' => 15.97,
            'currency' => 'EUR',
            'capturedAt' => date('Y-m-d H:i:s'),
        ]);
    }

    // =========================================================================
    // Delayed Capture: AUTHORIZED -> READY_TO_COMMIT Flow
    // =========================================================================

    /**
     * @test
     * @group sprint-7
     * @group delayed-capture
     *
     * Tests the complete delayed capture flow:
     * 1. Contract created in PENDING state
     * 2. Payment authorized -> Contract transitions to AUTHORIZED
     * 3. Admin captures payment -> charge.captured webhook received
     * 4. Contract transitions from AUTHORIZED -> READY_TO_COMMIT
     */
    public function chargeCapturedTransitionsAuthorizedContractToReadyToCommit(): void
    {
        $paymentIntentId = 'pi_delayed_capture_' . uniqid();
        $capturedAmount = 99.99;

        // Given: Contract in AUTHORIZED state (manual capture mode)
        $contract = $this->createAuthorizedContract($paymentIntentId);

        $this->contractRepository
            ->method('findByProviderOrderId')
            ->with($paymentIntentId)
            ->willReturn($contract);

        // Contract should be saved after transition
        $this->contractRepository
            ->expects($this->once())
            ->method('save')
            ->with($contract);

        // When: charge.captured webhook is received
        $result = $this->handler->handleChargeCaptured($paymentIntentId, $capturedAmount);

        // Then: Contract transitions to READY_TO_COMMIT
        $this->assertTrue($result);
        $this->assertTrue($contract->getState()->isReadyToCommit());
        $this->assertEquals($capturedAmount, $contract->getCapturedAmount());
        $this->assertNotNull($contract->getCapturedAt());
    }

    /**
     * @test
     * @group sprint-7
     * @group delayed-capture
     *
     * Tests idempotency: multiple charge.captured webhooks for same contract.
     */
    public function chargeCapturedIsIdempotentForFulfilledContract(): void
    {
        $paymentIntentId = 'pi_idempotent_' . uniqid();
        $capturedAmount = 50.00;

        // Given: Contract already FULFILLED
        $contract = $this->createFulfilledContract($paymentIntentId);

        $this->contractRepository
            ->method('findByProviderOrderId')
            ->with($paymentIntentId)
            ->willReturn($contract);

        // Contract should still be saved to record captured amount
        $this->contractRepository
            ->expects($this->once())
            ->method('save')
            ->with($contract);

        // When: charge.captured webhook received (again)
        $result = $this->handler->handleChargeCaptured($paymentIntentId, $capturedAmount);

        // Then: Returns false (already fulfilled) but captured amount is recorded
        $this->assertFalse($result);
        $this->assertTrue($contract->getState()->isFulfilled());
        $this->assertEquals($capturedAmount, $contract->getCapturedAmount());
    }

    /**
     * @test
     * @group sprint-7
     * @group delayed-capture
     *
     * Tests capture of COMMITTED contract (auto-capture mode or late webhook).
     */
    public function chargeCapturedFulfillsCommittedContract(): void
    {
        $paymentIntentId = 'pi_committed_' . uniqid();
        $capturedAmount = 75.00;

        // Given: Contract in COMMITTED state (ready for fulfillment)
        $contract = $this->createCommittedContract($paymentIntentId);

        $this->contractRepository
            ->method('findByProviderOrderId')
            ->with($paymentIntentId)
            ->willReturn($contract);

        // ContractFulfillmentService should handle fulfillment
        $this->contractFulfillmentService
            ->expects($this->once())
            ->method('fulfill')
            ->with($contract)
            ->willReturn(true);

        // When: charge.captured webhook received
        $result = $this->handler->handleChargeCaptured($paymentIntentId, $capturedAmount);

        // Then: Contract is fulfilled via service
        $this->assertTrue($result);
    }

    /**
     * @test
     * @group sprint-7
     * @group delayed-capture
     *
     * Tests that capture amount is recorded even when contract is not in expected state.
     */
    public function chargeCapturedRecordsAmountForPendingContract(): void
    {
        $paymentIntentId = 'pi_pending_' . uniqid();
        $capturedAmount = 100.00;

        // Given: Contract in PENDING state (unexpected - should be AUTHORIZED or COMMITTED)
        $contract = $this->createPendingContract($paymentIntentId);

        $this->contractRepository
            ->method('findByProviderOrderId')
            ->with($paymentIntentId)
            ->willReturn($contract);

        // Contract should be saved to record captured amount
        $this->contractRepository
            ->expects($this->once())
            ->method('save')
            ->with($contract);

        // When: charge.captured webhook received
        $result = $this->handler->handleChargeCaptured($paymentIntentId, $capturedAmount);

        // Then: Returns false (wrong state) but captured amount is recorded
        $this->assertFalse($result);
        $this->assertEquals($capturedAmount, $contract->getCapturedAmount());
    }

    /**
     * @test
     * @group sprint-7
     * @group delayed-capture
     *
     * Tests handling when no contract exists for the payment intent.
     */
    public function chargeCapturedReturnsNullWhenNoContract(): void
    {
        $paymentIntentId = 'pi_no_contract_' . uniqid();

        // Given: No contract exists
        $this->contractRepository
            ->method('findByProviderOrderId')
            ->with($paymentIntentId)
            ->willReturn(null);

        // When: charge.captured webhook received
        $result = $this->handler->handleChargeCaptured($paymentIntentId, 50.00);

        // Then: Returns null (contract not found)
        $this->assertNull($result);
    }

    // =========================================================================
    // Payment Succeeded: Auto-capture mode
    // =========================================================================

    /**
     * @test
     * @group sprint-7
     * @group delayed-capture
     *
     * Tests payment_intent.succeeded handling (auto-capture mode).
     */
    public function paymentSucceededDelegatesToFulfillmentService(): void
    {
        $paymentIntentId = 'pi_auto_capture_' . uniqid();

        // ContractFulfillmentService handles the lookup and fulfillment
        $this->contractFulfillmentService
            ->expects($this->once())
            ->method('fulfillByProviderOrderId')
            ->with($paymentIntentId)
            ->willReturn(true);

        // When: payment_intent.succeeded webhook received
        $result = $this->handler->handlePaymentSucceeded($paymentIntentId);

        // Then: Delegates to service
        $this->assertTrue($result);
    }

    // =========================================================================
    // Refund handling
    // =========================================================================

    /**
     * @test
     * @group sprint-7
     * @group delayed-capture
     *
     * Tests refund handling accumulates partial refunds.
     */
    public function chargeRefundedAccumulatesPartialRefunds(): void
    {
        $paymentIntentId = 'pi_partial_refund_' . uniqid();

        // Given: Fulfilled contract with previous refund
        $contract = $this->createFulfilledContract($paymentIntentId);
        $contract->addRefundedAmount(25.00); // Previous refund

        $this->contractRepository
            ->method('findByProviderOrderId')
            ->with($paymentIntentId)
            ->willReturn($contract);

        $this->contractRepository
            ->expects($this->once())
            ->method('save')
            ->with($contract);

        // When: Second refund webhook received
        $result = $this->handler->handleChargeRefunded($paymentIntentId, 30.00);

        // Then: Refunds accumulated
        $this->assertTrue($result);
        $this->assertEquals(55.00, $contract->getRefundedAmount());
        $this->assertNotNull($contract->getRefundedAt());
    }

    /**
     * @test
     * @group sprint-7
     * @group delayed-capture
     *
     * Tests refund is rejected for non-fulfilled contract.
     */
    public function chargeRefundedRejectsNonFulfilledContract(): void
    {
        $paymentIntentId = 'pi_refund_pending_' . uniqid();

        // Given: Contract in COMMITTED state (not yet fulfilled)
        $contract = $this->createCommittedContract($paymentIntentId);

        $this->contractRepository
            ->method('findByProviderOrderId')
            ->with($paymentIntentId)
            ->willReturn($contract);

        // When: refund webhook received
        $result = $this->handler->handleChargeRefunded($paymentIntentId, 50.00);

        // Then: Refund rejected (wrong state)
        $this->assertFalse($result);
    }

    // =========================================================================
    // Payment Failed handling
    // =========================================================================

    /**
     * @test
     * @group sprint-7
     * @group delayed-capture
     *
     * Tests payment failure transitions contract to FAILED state.
     */
    public function paymentFailedTransitionsContractToFailed(): void
    {
        $paymentIntentId = 'pi_failed_' . uniqid();
        $failureReason = 'card_declined';

        // Given: Contract in PENDING state
        $contract = $this->createPendingContract($paymentIntentId);

        $this->contractRepository
            ->method('findByProviderOrderId')
            ->with($paymentIntentId)
            ->willReturn($contract);

        $this->contractRepository
            ->expects($this->once())
            ->method('save')
            ->with($contract);

        // When: payment_intent.payment_failed webhook received
        $result = $this->handler->handlePaymentFailed($paymentIntentId, $failureReason);

        // Then: Contract is marked as failed
        $this->assertTrue($result);
        $this->assertTrue($contract->getState()->isFailed());
    }

    /**
     * @test
     * @group sprint-7
     * @group delayed-capture
     *
     * Tests payment failure is ignored for terminal contracts.
     */
    public function paymentFailedIgnoresTerminalContract(): void
    {
        $paymentIntentId = 'pi_fail_terminal_' . uniqid();

        // Given: Contract already FULFILLED (terminal state)
        $contract = $this->createFulfilledContract($paymentIntentId);

        $this->contractRepository
            ->method('findByProviderOrderId')
            ->with($paymentIntentId)
            ->willReturn($contract);

        // When: payment failure webhook received
        $result = $this->handler->handlePaymentFailed($paymentIntentId, 'expired');

        // Then: Failure ignored (already terminal)
        $this->assertFalse($result);
        $this->assertTrue($contract->getState()->isFulfilled()); // Unchanged
    }

    // =========================================================================
    // Helper Methods
    // =========================================================================

    private function createAuthorizedContract(string $paymentIntentId): PaymentContract
    {
        $contract = new PaymentContract(1, 'user_' . uniqid(), $this->basketSnapshot);
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));
        $contract->transitionToNotFinished('order_123');
        $contract->transitionToPending();
        $contract->setProvider('stripe', $paymentIntentId);
        $contract->authorize();

        return $contract;
    }

    private function createPendingContract(string $paymentIntentId): PaymentContract
    {
        $contract = new PaymentContract(1, 'user_' . uniqid(), $this->basketSnapshot);
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));
        $contract->transitionToNotFinished('order_123');
        $contract->transitionToPending();
        $contract->setProvider('stripe', $paymentIntentId);

        return $contract;
    }

    private function createCommittedContract(string $paymentIntentId): PaymentContract
    {
        $orderId = 'order_' . uniqid();
        $contract = new PaymentContract(1, 'user_' . uniqid(), $this->basketSnapshot);
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));
        $contract->transitionToNotFinished($orderId);
        $contract->transitionToPending();
        $contract->setProvider('stripe', $paymentIntentId);
        $contract->fulfillCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED, ['authId' => 'auth_' . uniqid()]);
        $contract->commitToOrder($orderId);

        return $contract;
    }

    private function createFulfilledContract(string $paymentIntentId): PaymentContract
    {
        $contract = $this->createCommittedContract($paymentIntentId);
        $contract->fulfill();

        return $contract;
    }
}
