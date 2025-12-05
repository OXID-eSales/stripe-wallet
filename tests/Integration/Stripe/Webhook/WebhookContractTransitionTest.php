<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Integration\Stripe\Webhook;

use Doctrine\DBAL\Connection;
use OxidSolutionCatalysts\Payments\Component\Contract\BasketSnapshot;
use OxidSolutionCatalysts\Payments\Component\Contract\ContractCondition;
use OxidSolutionCatalysts\Payments\Component\Contract\ContractState;
use OxidSolutionCatalysts\Payments\Component\Contract\PaymentContract;
use OxidSolutionCatalysts\Payments\Component\Contract\PaymentContractInterface;
use OxidSolutionCatalysts\Payments\Component\Repository\ContractRepositoryInterface;
use OxidSolutionCatalysts\Payments\Component\Webhook\WebhookEvent;
use OxidSolutionCatalysts\Payments\Stripe\Webhook\Handler\PaymentIntentSucceededHandler;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Integration tests for webhook events triggering contract state transitions.
 *
 * Tests that webhook handlers correctly update contract states
 * according to the state machine rules.
 *
 * @covers \OxidSolutionCatalysts\Payments\Stripe\Webhook\Handler\PaymentIntentSucceededHandler
 * @group sprint-14
 * @group sprint-15
 * @group webhook
 * @group contract
 */
final class WebhookContractTransitionTest extends TestCase
{
    private Connection $connection;
    private ContractRepositoryInterface $contractRepository;
    private LoggerInterface $logger;
    private PaymentIntentSucceededHandler $handler;
    private BasketSnapshot $basketSnapshot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = $this->createMock(Connection::class);
        $this->contractRepository = $this->createMock(ContractRepositoryInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->handler = new PaymentIntentSucceededHandler(
            $this->connection,
            $this->contractRepository,
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
     * @test
     */
    public function paymentIntentSucceededFulfillsCommittedContract(): void
    {
        $paymentIntentId = 'pi_test_fulfill_' . uniqid();

        // Given: Contract in COMMITTED state
        $contract = $this->createContractInState('committed', $paymentIntentId);

        $this->contractRepository
            ->method('findByProviderOrderId')
            ->with($paymentIntentId)
            ->willReturn($contract);

        $this->contractRepository
            ->expects($this->once())
            ->method('save')
            ->with($this->callback(function (PaymentContractInterface $saved) {
                // Verify contract was fulfilled
                return $saved->getState()->isFulfilled();
            }));

        // When: payment_intent.succeeded webhook received
        $event = $this->createWebhookEvent('payment_intent.succeeded', $paymentIntentId);
        $result = $this->handler->handle($event);

        // Then: Handler succeeds and contract is fulfilled
        $this->assertTrue($result->isSuccess());
        $this->assertEquals('contract_fulfilled', $result->action);
        $this->assertTrue($contract->getState()->isFulfilled());
    }

    /**
     * @test
     * Sprint 15: Already fulfilled contracts are not re-fulfilled
     */
    public function paymentIntentSucceededIgnoresAlreadyFulfilledContract(): void
    {
        $paymentIntentId = 'pi_test_already_fulfilled_' . uniqid();

        // Given: Contract already FULFILLED
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getState')->willReturn(ContractState::fulfilled());
        $contract->expects($this->never())->method('fulfill');

        $this->contractRepository
            ->method('findByProviderOrderId')
            ->willReturn($contract);

        // Repository save should NOT be called for fulfilled contracts
        $this->contractRepository
            ->expects($this->never())
            ->method('save');

        // OXPAID should NOT be updated
        $this->connection
            ->expects($this->never())
            ->method('executeStatement');

        // When: payment_intent.succeeded webhook received
        $event = $this->createWebhookEvent('payment_intent.succeeded', $paymentIntentId);
        $result = $this->handler->handle($event);

        // Then: Handler succeeds but contract unchanged
        $this->assertTrue($result->isSuccess());
        $this->assertEquals('contract_not_committed', $result->action);
    }

    /**
     * @test
     * Sprint 15: Pending contracts cannot be fulfilled directly
     */
    public function paymentIntentSucceededIgnoresPendingContract(): void
    {
        $paymentIntentId = 'pi_test_pending_' . uniqid();

        // Given: Contract in PENDING state (not yet ready to be fulfilled)
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getState')->willReturn(ContractState::pending());
        $contract->expects($this->never())->method('fulfill');

        $this->contractRepository
            ->method('findByProviderOrderId')
            ->willReturn($contract);

        $this->contractRepository
            ->expects($this->never())
            ->method('save');

        // OXPAID should NOT be updated
        $this->connection
            ->expects($this->never())
            ->method('executeStatement');

        // When: payment_intent.succeeded webhook received
        $event = $this->createWebhookEvent('payment_intent.succeeded', $paymentIntentId);
        $result = $this->handler->handle($event);

        // Then: Handler succeeds but contract not fulfilled (wrong state)
        $this->assertTrue($result->isSuccess());
        $this->assertEquals('contract_not_committed', $result->action);
    }

    /**
     * @test
     * Sprint 15: NO_CONTRACT is ERROR - logs error, returns success (200)
     */
    public function paymentIntentSucceededLogsErrorWhenNoContract(): void
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

        // OXPAID should NOT be updated without contract
        $this->connection
            ->expects($this->never())
            ->method('executeStatement');

        // When: payment_intent.succeeded webhook received
        $event = $this->createWebhookEvent('payment_intent.succeeded', $paymentIntentId);
        $result = $this->handler->handle($event);

        // Then: Returns success (200) but with error action
        $this->assertTrue($result->isSuccess());
        $this->assertEquals('no_contract_logged', $result->action);
    }

    /**
     * @test
     * Sprint 15: OXPAID is only updated when contract exists and is COMMITTED
     */
    public function paymentIntentSucceededUpdatesOxpaidTimestamp(): void
    {
        $paymentIntentId = 'pi_test_oxpaid_' . uniqid();
        $chargeTimestamp = time();

        // Given: Contract in COMMITTED state (required for OXPAID update)
        $contract = $this->createContractInState('committed', $paymentIntentId);

        $this->contractRepository
            ->method('findByProviderOrderId')
            ->with($paymentIntentId)
            ->willReturn($contract);

        $this->contractRepository
            ->expects($this->once())
            ->method('save');

        // Expect OXPAID update
        $this->connection
            ->expects($this->once())
            ->method('executeStatement')
            ->with(
                $this->stringContains('UPDATE oxorder SET OXPAID'),
                $this->callback(function (array $params) use ($paymentIntentId) {
                    return $params['transid'] === $paymentIntentId
                        && isset($params['paid'])
                        && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $params['paid']);
                })
            );

        // When: payment_intent.succeeded with charge data
        $event = $this->createWebhookEventWithCharge($paymentIntentId, $chargeTimestamp);
        $result = $this->handler->handle($event);

        // Then: Success with contract fulfilled
        $this->assertTrue($result->isSuccess());
        $this->assertEquals('contract_fulfilled', $result->action);
    }

    /**
     * @test
     */
    public function handlerFailsWhenPaymentIntentIdMissing(): void
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
            $contract->transitionToPending();
            return $contract;
        }

        $contract->transitionToPending();
        $contract->fulfillCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED);

        if ($state === 'ready_to_commit') {
            return $contract;
        }

        $contract->commitToOrder('order_test_' . uniqid());

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
