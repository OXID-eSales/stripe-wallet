<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Unit\Stripe\Webhook\Handler;

use Doctrine\DBAL\Connection;
use OxidSolutionCatalysts\Payments\Component\Contract\ContractState;
use OxidSolutionCatalysts\Payments\Component\Contract\PaymentContractInterface;
use OxidSolutionCatalysts\Payments\Component\Repository\ContractRepositoryInterface;
use OxidSolutionCatalysts\Payments\Component\Webhook\WebhookEvent;
use OxidSolutionCatalysts\Payments\Component\Webhook\WebhookEventHandlerInterface;
use OxidSolutionCatalysts\Payments\Component\Webhook\WebhookResult;
use OxidSolutionCatalysts\Payments\Stripe\Webhook\Handler\PaymentIntentSucceededHandler;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OxidSolutionCatalysts\Payments\Stripe\Webhook\Handler\PaymentIntentSucceededHandler
 * @group sprint-13
 * @group sprint-15
 * @group webhook
 * @group handler
 */
final class PaymentIntentSucceededHandlerTest extends TestCase
{
    private Connection $connection;
    private ContractRepositoryInterface $contractRepository;
    private LoggerInterface $logger;
    private PaymentIntentSucceededHandler $handler;

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
    }

    /**
     * @test
     */
    public function implementsInterface(): void
    {
        $this->assertInstanceOf(WebhookEventHandlerInterface::class, $this->handler);
    }

    /**
     * @test
     */
    public function supportsPaymentIntentSucceededEvent(): void
    {
        $this->assertTrue($this->handler->supports('payment_intent.succeeded'));
    }

    /**
     * @test
     */
    public function doesNotSupportOtherEvents(): void
    {
        $this->assertFalse($this->handler->supports('payment_intent.created'));
        $this->assertFalse($this->handler->supports('charge.refunded'));
        $this->assertFalse($this->handler->supports('checkout.session.completed'));
    }

    /**
     * @test
     * Sprint 15: OXPAID is only updated when contract exists
     */
    public function updatesOxpaidTimestampWithContract(): void
    {
        $event = $this->createEvent('pi_test_123', [
            'status' => 'succeeded',
            'charges' => [
                'data' => [
                    ['paid' => true, 'created' => 1733400000],
                ],
            ],
        ]);

        // Contract is REQUIRED
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getState')->willReturn(ContractState::committed());
        $contract->expects($this->once())->method('fulfill');

        $this->contractRepository
            ->method('findByProviderOrderId')
            ->willReturn($contract);

        $this->connection
            ->expects($this->once())
            ->method('executeStatement')
            ->with(
                $this->stringContains('UPDATE oxorder'),
                $this->callback(function ($params) {
                    return isset($params['transid']) && $params['transid'] === 'pi_test_123';
                })
            );

        $result = $this->handler->handle($event);

        $this->assertTrue($result->isSuccess());
    }

    /**
     * @test
     * Sprint 15: NO_CONTRACT is ERROR - logs error but returns success (200)
     */
    public function logsErrorWhenNoContractFound(): void
    {
        $event = $this->createEvent('pi_no_contract', ['status' => 'succeeded']);

        // No contract found
        $this->contractRepository
            ->method('findByProviderOrderId')
            ->willReturn(null);

        // Should log ERROR
        $this->logger
            ->expects($this->once())
            ->method('error')
            ->with(
                $this->stringContains('NO_CONTRACT'),
                $this->callback(fn($ctx) => $ctx['payment_intent_id'] === 'pi_no_contract')
            );

        // Should NOT update OXPAID without contract
        $this->connection
            ->expects($this->never())
            ->method('executeStatement');

        $result = $this->handler->handle($event);

        // Returns success (200) so Stripe doesn't retry
        $this->assertTrue($result->isSuccess());
        $this->assertSame('no_contract_logged', $result->action);
    }

    /**
     * @test
     */
    public function fulfillsContractWhenCommitted(): void
    {
        $event = $this->createEvent('pi_test_456', ['status' => 'succeeded']);

        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getState')->willReturn(ContractState::committed());
        $contract->expects($this->once())->method('fulfill');

        $this->contractRepository
            ->method('findByProviderOrderId')
            ->with('pi_test_456')
            ->willReturn($contract);

        $this->contractRepository
            ->expects($this->once())
            ->method('save')
            ->with($contract);

        $result = $this->handler->handle($event);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('contract_fulfilled', $result->action);
    }

    /**
     * @test
     * Sprint 15: Contract must be in COMMITTED state to be fulfilled
     */
    public function doesNotFulfillContractIfNotCommitted(): void
    {
        $event = $this->createEvent('pi_test_789', ['status' => 'succeeded']);

        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getState')->willReturn(ContractState::fulfilled());
        $contract->expects($this->never())->method('fulfill');

        $this->contractRepository
            ->method('findByProviderOrderId')
            ->willReturn($contract);

        // Should NOT update OXPAID if contract is not committed
        $this->connection
            ->expects($this->never())
            ->method('executeStatement');

        $result = $this->handler->handle($event);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('contract_not_committed', $result->action);
    }

    /**
     * @test
     */
    public function logsSuccessfulHandling(): void
    {
        $event = $this->createEvent('pi_log_test', ['status' => 'succeeded']);

        // Contract is required for successful handling
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getState')->willReturn(ContractState::committed());

        $this->contractRepository->method('findByProviderOrderId')->willReturn($contract);

        $this->logger
            ->expects($this->atLeastOnce())
            ->method('info')
            ->with(
                $this->stringContains('payment_intent.succeeded'),
                $this->callback(fn($ctx) => isset($ctx['payment_intent_id']))
            );

        $this->handler->handle($event);
    }

    /**
     * @test
     */
    public function returnsFailureWhenPaymentIntentIdMissing(): void
    {
        $event = new WebhookEvent(
            'evt_123',
            'payment_intent.succeeded',
            ['object' => ['status' => 'succeeded']], // No 'id' field
            0
        );

        $result = $this->handler->handle($event);

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('Missing payment intent', $result->error);
    }

    /**
     * @test
     */
    public function extractsCapturedAtFromChargeData(): void
    {
        $chargeCreatedTimestamp = time(); // Use current time for consistent test
        $event = $this->createEvent('pi_charge_test', [
            'status' => 'succeeded',
            'charges' => [
                'data' => [
                    ['paid' => true, 'created' => $chargeCreatedTimestamp],
                ],
            ],
        ]);

        // Contract is required
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getState')->willReturn(ContractState::committed());

        $this->contractRepository->method('findByProviderOrderId')->willReturn($contract);

        $this->connection
            ->expects($this->once())
            ->method('executeStatement')
            ->with(
                $this->anything(),
                $this->callback(function ($params) {
                    // Verify paid parameter exists and is a valid datetime string
                    return isset($params['paid'])
                        && isset($params['transid'])
                        && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $params['paid']);
                })
            );

        $this->handler->handle($event);
    }

    /**
     * Create a test event with the given payment intent data.
     *
     * @param string $paymentIntentId
     * @param array<string, mixed> $additionalData
     * @return WebhookEvent
     */
    private function createEvent(string $paymentIntentId, array $additionalData = []): WebhookEvent
    {
        $objectData = array_merge(['id' => $paymentIntentId], $additionalData);

        return new WebhookEvent(
            'evt_test_' . substr(md5($paymentIntentId), 0, 8),
            'payment_intent.succeeded',
            ['object' => $objectData],
            time()
        );
    }
}
