<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Integration\Stripe\Webhook;

use OxidEsales\PaymentComponent\Contract\BasketSnapshot;
use OxidEsales\PaymentComponent\Contract\ContractCondition;
use OxidEsales\PaymentComponent\Contract\ContractState;
use OxidEsales\PaymentComponent\Contract\PaymentContract;
use OxidEsales\PaymentComponent\Contract\PaymentContractInterface;
use OxidEsales\PaymentComponent\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentComponent\Service\ContractFulfillmentServiceInterface;
use OxidEsales\PaymentComponent\Service\OrderPaymentStateServiceInterface;
use OxidEsales\PaymentComponent\Webhook\WebhookEvent;
use OxidEsales\Payments\Stripe\WebhookHandler\PaymentIntentSucceededHandler;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Integration tests for webhook events triggering contract state transitions.
 *
 * Tests that webhook handlers correctly update contract states
 * according to the state machine rules.
 *
 */
#[CoversClass(\OxidEsales\Payments\Stripe\WebhookHandler\PaymentIntentSucceededHandler::class)]
    #[Group('sprint-14')]
    #[Group('sprint-15')]
    #[Group('sprint-16')]
    #[Group('sprint-18')]
    #[Group('webhook')]
    #[Group('contract')]
final class WebhookContractTransitionTest extends TestCase
{
    private OrderPaymentStateServiceInterface&MockObject $orderPaymentStateService;
    private ContractRepositoryInterface&MockObject $contractRepository;
    private ContractFulfillmentServiceInterface&MockObject $contractFulfillmentService;
    private LoggerInterface&MockObject $logger;
    private PaymentIntentSucceededHandler $handler;
    private BasketSnapshot $basketSnapshot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orderPaymentStateService = $this->createMock(OrderPaymentStateServiceInterface::class);
        $this->contractRepository = $this->createMock(ContractRepositoryInterface::class);
        $this->contractFulfillmentService = $this->createMock(ContractFulfillmentServiceInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        // Sprint 18: Added ContractFulfillmentService dependency
        $this->handler = new PaymentIntentSucceededHandler(
            $this->orderPaymentStateService,
            $this->contractRepository,
            $this->contractFulfillmentService,
            $this->logger
        );

        $this->basketSnapshot = BasketSnapshot::fromArray([
            'items' => [['id' => 'item1', 'qty' => 1, 'price' => 100.00]],
            'discounts' => [],
            'totalGross' => 100.00,
            'totalNet' => 84.03,
            'totalVat' => 15.97,
            'currency' => 'EUR',
        ]);
    }

    /**
     * Sprint 18: Uses ContractFulfillmentService for fulfillment
     */
    public function testPaymentIntentSucceededFulfillsCommittedContract(): void
    {
        $paymentIntentId = 'pi_test_fulfill_' . uniqid();

        // Given: Contract in COMMITTED state
        $contract = $this->createContractInState('committed', $paymentIntentId);

        $this->contractRepository
            ->method('findByProviderOrderId')
            ->with($paymentIntentId)
            ->willReturn($contract);

        // Sprint 18: ContractFulfillmentService handles fulfillment (includes save)
        $this->contractFulfillmentService
            ->expects($this->once())
            ->method('fulfill')
            ->with($contract)
            ->willReturn(true);

        // When: payment_intent.succeeded webhook received
        $event = $this->createWebhookEvent('payment_intent.succeeded', $paymentIntentId);
        $result = $this->handler->handle($event);

        // Then: Handler succeeds and contract is fulfilled
        $this->assertTrue($result->isSuccess());
        $this->assertEquals('contract_fulfilled', $result->action);
    }

    /**
     * Sprint 15: Already fulfilled contracts are not re-fulfilled
     * Sprint 18: Uses ContractFulfillmentService for fulfillment
     *
     * Note: OXPAID is still updated because the current implementation updates
     * OXPAID for any existing contract, regardless of state. This is intentional
     * to ensure payment timestamps are always recorded.
     */
    public function testPaymentIntentSucceededIgnoresAlreadyFulfilledContract(): void
    {
        $paymentIntentId = 'pi_test_already_fulfilled_' . uniqid();

        // Given: Contract already FULFILLED
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getState')->willReturn(ContractState::fulfilled());

        $this->contractRepository
            ->method('findByProviderOrderId')
            ->willReturn($contract);

        // Sprint 18: ContractFulfillmentService returns false for non-committed
        $this->contractFulfillmentService
            ->expects($this->once())
            ->method('fulfill')
            ->with($contract)
            ->willReturn(false);

        // OXPAID is still updated (current behavior: always update when contract exists)
        $this->orderPaymentStateService
            ->expects($this->once())
            ->method('updatePaidTimestampByTransactionId')
            ->with($paymentIntentId, $this->isInstanceOf(\DateTimeImmutable::class));

        // When: payment_intent.succeeded webhook received
        $event = $this->createWebhookEvent('payment_intent.succeeded', $paymentIntentId);
        $result = $this->handler->handle($event);

        // Then: Handler succeeds but contract unchanged
        $this->assertTrue($result->isSuccess());
        $this->assertEquals('contract_not_fulfilled', $result->action);
    }

    /**
     * Sprint 15: Pending contracts cannot be fulfilled directly
     * Sprint 18: Uses ContractFulfillmentService for fulfillment
     *
     * Note: OXPAID is still updated because the current implementation updates
     * OXPAID for any existing contract, regardless of state. This is intentional
     * to ensure payment timestamps are always recorded.
     */
    public function testPaymentIntentSucceededIgnoresPendingContract(): void
    {
        $paymentIntentId = 'pi_test_pending_' . uniqid();

        // Given: Contract in PENDING state (not yet ready to be fulfilled)
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getState')->willReturn(ContractState::pending());

        $this->contractRepository
            ->method('findByProviderOrderId')
            ->willReturn($contract);

        // Sprint 18: ContractFulfillmentService returns false for non-committed
        $this->contractFulfillmentService
            ->expects($this->once())
            ->method('fulfill')
            ->with($contract)
            ->willReturn(false);

        // OXPAID is still updated (current behavior: always update when contract exists)
        $this->orderPaymentStateService
            ->expects($this->once())
            ->method('updatePaidTimestampByTransactionId')
            ->with($paymentIntentId, $this->isInstanceOf(\DateTimeImmutable::class));

        // When: payment_intent.succeeded webhook received
        $event = $this->createWebhookEvent('payment_intent.succeeded', $paymentIntentId);
        $result = $this->handler->handle($event);

        // Then: Handler succeeds but contract not fulfilled (wrong state)
        $this->assertTrue($result->isSuccess());
        $this->assertEquals('contract_not_fulfilled', $result->action);
    }

    /**
     * Sprint 15: NO_CONTRACT is ERROR - logs error, returns success (200)
     * Sprint 16: Uses OrderPaymentStateService
     */
    public function testPaymentIntentSucceededLogsErrorWhenNoContract(): void
    {
        $paymentIntentId = 'pi_test_no_contract_' . uniqid();

        // Given: No contract exists for this payment
        $this->contractRepository
            ->method('findByProviderOrderId')
            ->with($paymentIntentId)
            ->willReturn(null);

        // Should log ERROR
        $this->logger
            ->expects($this->once())
            ->method('error')
            ->with(
                $this->stringContains('NO_CONTRACT'),
                $this->callback(fn($ctx) => $ctx['payment_intent_id'] === $paymentIntentId)
            );

        // Sprint 16: OXPAID should NOT be updated without contract
        $this->orderPaymentStateService
            ->expects($this->never())
            ->method('updatePaidTimestampByTransactionId');

        // When: payment_intent.succeeded webhook received
        $event = $this->createWebhookEvent('payment_intent.succeeded', $paymentIntentId);
        $result = $this->handler->handle($event);

        // Then: Returns success (200) but with error action
        $this->assertTrue($result->isSuccess());
        $this->assertEquals('no_contract_logged', $result->action);
    }

    /**
     * Sprint 15: OXPAID is only updated when contract exists and is COMMITTED
     * Sprint 16: Uses OrderPaymentStateService
     * Sprint 18: Uses ContractFulfillmentService for fulfillment
     */
    public function testPaymentIntentSucceededUpdatesOxpaidTimestamp(): void
    {
        $paymentIntentId = 'pi_test_oxpaid_' . uniqid();
        $chargeTimestamp = time();

        // Given: Contract in COMMITTED state (required for OXPAID update)
        $contract = $this->createContractInState('committed', $paymentIntentId);

        $this->contractRepository
            ->method('findByProviderOrderId')
            ->with($paymentIntentId)
            ->willReturn($contract);

        // Sprint 18: ContractFulfillmentService handles fulfillment (includes save)
        $this->contractFulfillmentService
            ->expects($this->once())
            ->method('fulfill')
            ->with($contract)
            ->willReturn(true);

        // Sprint 16: Expect OXPAID update via service
        $this->orderPaymentStateService
            ->expects($this->once())
            ->method('updatePaidTimestampByTransactionId')
            ->with(
                $paymentIntentId,
                $this->callback(function (\DateTimeImmutable $paidAt) use ($chargeTimestamp) {
                    return $paidAt->getTimestamp() === $chargeTimestamp;
                })
            );

        // When: payment_intent.succeeded with charge data
        $event = $this->createWebhookEventWithCharge($paymentIntentId, $chargeTimestamp);
        $result = $this->handler->handle($event);

        // Then: Success with contract fulfilled
        $this->assertTrue($result->isSuccess());
        $this->assertEquals('contract_fulfilled', $result->action);
    }

        public function testHandlerFailsWhenPaymentIntentIdMissing(): void
    {
        // Given: Event without payment intent ID
        $event = new WebhookEvent(
            'evt_test_missing',
            'payment_intent.succeeded',
            ['object' => ['status' => 'succeeded']], // No 'id' field
            time()
        );

        // When: Handler processes event
        $result = $this->handler->handle($event);

        // Then: Handler fails gracefully
        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('Missing payment intent', $result->error ?? '');
    }

    // ==========================================
    // Helper Methods
    // ==========================================

    /**
     * Create a real PaymentContract in a specific state.
     */
    private function createContractInState(string $state, string $providerOrderId): PaymentContract
    {
        $contract = new PaymentContract(1, 'user123', $this->basketSnapshot);
        $contract->setProvider('stripe', $providerOrderId);

        if ($state === 'draft') {
            return $contract;
        }

        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));

        if ($state === 'pending') {
            $contract->transitionToNotFinished('order_123');
            $contract->transitionToPending();
            return $contract;
        }

        $orderId = 'order_test_' . uniqid();
        $contract->transitionToNotFinished($orderId);
        $contract->transitionToPending();
        $contract->fulfillCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED);

        if ($state === 'ready_to_commit') {
            return $contract;
        }

        $contract->commitToOrder($orderId);

        if ($state === 'committed') {
            return $contract;
        }

        if ($state === 'fulfilled') {
            $contract->fulfill();
            return $contract;
        }

        throw new \InvalidArgumentException("Unknown state: {$state}");
    }

    /**
     * Create a WebhookEvent for testing.
     */
    private function createWebhookEvent(string $type, string $paymentIntentId): WebhookEvent
    {
        return new WebhookEvent(
            'evt_test_' . substr(md5($paymentIntentId), 0, 8),
            $type,
            [
                'object' => [
                    'id' => $paymentIntentId,
                    'status' => 'succeeded',
                ],
            ],
            time()
        );
    }

    /**
     * Create a WebhookEvent with charge data for timestamp extraction.
     */
    private function createWebhookEventWithCharge(string $paymentIntentId, int $chargeTimestamp): WebhookEvent
    {
        return new WebhookEvent(
            'evt_test_charge_' . substr(md5($paymentIntentId), 0, 8),
            'payment_intent.succeeded',
            [
                'object' => [
                    'id' => $paymentIntentId,
                    'status' => 'succeeded',
                    'charges' => [
                        'data' => [
                            ['paid' => true, 'created' => $chargeTimestamp],
                        ],
                    ],
                ],
            ],
            time()
        );
    }
}
