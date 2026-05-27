<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Webhook\Handler;

use OxidEsales\PaymentBase\Adapter\ShopAdapterInterface;
use OxidEsales\PaymentBase\Contract\ContractState;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Service\ContractFulfillmentServiceInterface;
use OxidEsales\PaymentBase\Service\OrderPaymentStateServiceInterface;
use OxidEsales\PaymentBase\Webhook\WebhookEvent;
use OxidEsales\PaymentBase\Webhook\WebhookEventHandlerInterface;
use OxidEsales\Payments\Stripe\WebhookHandler\PaymentIntentSucceededHandler;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OxidEsales\Payments\Stripe\WebhookHandler\PaymentIntentSucceededHandler
 * @group sprint-13
 * @group sprint-15
 * @group sprint-16
 * @group sprint-18
 * @group webhook
 * @group handler
 */
final class PaymentIntentSucceededHandlerTest extends TestCase
{
    private OrderPaymentStateServiceInterface&MockObject $orderPaymentStateService;
    private ContractRepositoryInterface&MockObject $contractRepository;
    private ContractFulfillmentServiceInterface&MockObject $contractFulfillmentService;
    private ShopAdapterInterface&MockObject $shopAdapter;
    private LoggerInterface&MockObject $logger;
    private PaymentIntentSucceededHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orderPaymentStateService = $this->createMock(OrderPaymentStateServiceInterface::class);
        $this->contractRepository = $this->createMock(ContractRepositoryInterface::class);
        $this->contractFulfillmentService = $this->createMock(ContractFulfillmentServiceInterface::class);
        $this->shopAdapter = $this->createMock(ShopAdapterInterface::class);
        $this->shopAdapter->method('getShopId')->willReturn('1');
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->handler = new PaymentIntentSucceededHandler(
            $this->orderPaymentStateService,
            $this->contractRepository,
            $this->contractFulfillmentService,
            $this->createMock(\OxidEsales\PaymentBase\Repository\TransactionRepositoryInterface::class),
            $this->shopAdapter,
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
     * Sprint 16: Uses OrderPaymentStateService for OXPAID updates
     * Sprint 18: Uses ContractFulfillmentService for DRY fulfillment
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

        $this->contractRepository
            ->method('findByProviderOrderId')
            ->willReturn($contract);

        // Sprint 18: ContractFulfillmentService handles fulfillment
        $this->contractFulfillmentService
            ->expects($this->once())
            ->method('fulfill')
            ->with($contract)
            ->willReturn(true);

        // Sprint 16: Uses OrderPaymentStateService instead of Connection
        $this->orderPaymentStateService
            ->expects($this->once())
            ->method('updatePaidTimestampByTransactionId')
            ->with(
                'pi_test_123',
                $this->isInstanceOf(\DateTimeImmutable::class)
            );

        $result = $this->handler->handle($event);

        $this->assertTrue($result->isSuccess());
    }

    /**
     * @test
     * Sprint 15: NO_CONTRACT is ERROR - logs error but returns success (200)
     * Sprint 16: Uses OrderPaymentStateService for OXPAID updates
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
        $this->orderPaymentStateService
            ->expects($this->never())
            ->method('updatePaidTimestampByTransactionId');

        $result = $this->handler->handle($event);

        // Returns success (200) so Stripe doesn't retry
        $this->assertTrue($result->isSuccess());
        $this->assertSame('no_contract_logged', $result->action);
    }

    /**
     * @test
     * Sprint 18: Uses ContractFulfillmentService for DRY fulfillment
     */
    public function fulfillsContractWhenCommitted(): void
    {
        $event = $this->createEvent('pi_test_456', ['status' => 'succeeded']);

        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getState')->willReturn(ContractState::committed());

        $this->contractRepository
            ->method('findByProviderOrderId')
            ->with('pi_test_456')
            ->willReturn($contract);

        // Sprint 18: ContractFulfillmentService handles fulfillment
        $this->contractFulfillmentService
            ->expects($this->once())
            ->method('fulfill')
            ->with($contract)
            ->willReturn(true);

        $result = $this->handler->handle($event);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('contract_fulfilled', $result->action);
    }

    /**
     * @test
     * Sprint 15: Contract must be in COMMITTED state to be fulfilled
     * Sprint 16: Uses OrderPaymentStateService for OXPAID updates
     * Sprint 18: Uses ContractFulfillmentService for DRY fulfillment
     */
    public function doesNotFulfillContractIfNotCommitted(): void
    {
        $event = $this->createEvent('pi_test_789', ['status' => 'succeeded']);

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

        $result = $this->handler->handle($event);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('contract_not_fulfilled', $result->action);
    }

    /**
     * @test
     * Sprint 18: Uses ContractFulfillmentService for DRY fulfillment
     */
    public function logsSuccessfulHandling(): void
    {
        $event = $this->createEvent('pi_log_test', ['status' => 'succeeded']);

        // Contract is required for successful handling
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getState')->willReturn(ContractState::committed());

        $this->contractRepository->method('findByProviderOrderId')->willReturn($contract);

        // Sprint 18: Mock fulfillment service
        $this->contractFulfillmentService
            ->method('fulfill')
            ->willReturn(true);

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
     * Sprint 16: Uses OrderPaymentStateService for OXPAID updates
     * Sprint 18: Uses ContractFulfillmentService for DRY fulfillment
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

        // Sprint 18: Mock fulfillment service
        $this->contractFulfillmentService
            ->method('fulfill')
            ->willReturn(true);

        // Sprint 16: Verify OrderPaymentStateService is called with correct timestamp
        $this->orderPaymentStateService
            ->expects($this->once())
            ->method('updatePaidTimestampByTransactionId')
            ->with(
                'pi_charge_test',
                $this->callback(function (\DateTimeImmutable $paidAt) use ($chargeCreatedTimestamp) {
                    return $paidAt->getTimestamp() === $chargeCreatedTimestamp;
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
