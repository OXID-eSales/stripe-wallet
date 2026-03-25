<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Service;

use OxidEsales\PaymentComponent\Adapter\ShopOrderServiceInterface;
use OxidEsales\PaymentComponent\Contract\BasketSnapshot;
use OxidEsales\PaymentComponent\Contract\ContractCondition;
use OxidEsales\PaymentComponent\Contract\PaymentContract;
use OxidEsales\PaymentComponent\Repository\ContractRepositoryInterface;
use OxidEsales\Payments\Stripe\Service\RetryCleanupService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Sprint 72: Tests for RetryCleanupService.
 *
 */
#[CoversClass(\OxidEsales\Payments\Stripe\Service\RetryCleanupService::class)]
class RetryCleanupServiceTest extends TestCase
{
    private ContractRepositoryInterface&MockObject $contractRepository;
    private ShopOrderServiceInterface&MockObject $orderService;
    private RetryCleanupService $service;

    protected function setUp(): void
    {
        $this->contractRepository = $this->createMock(ContractRepositoryInterface::class);
        $this->orderService = $this->createMock(ShopOrderServiceInterface::class);
        $this->service = new RetryCleanupService($this->contractRepository, $this->orderService);
    }

    private function createContract(string $id = 'contract_123'): PaymentContract
    {
        $basket = BasketSnapshot::fromArray([
            'items' => [],
            'discounts' => [],
            'totalGross' => 100.0,
            'totalNet' => 84.03,
            'totalVat' => 15.97,
            'currency' => 'EUR',
            'capturedAt' => date('Y-m-d H:i:s'),
        ]);

        $contract = new PaymentContract(1, 'user123', $basket, $id);
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));
        return $contract;
    }

    public function testCleanupCancelsContractAndDeletesOrder(): void
    {
        $contract = $this->createContract();
        $contract->transitionToNotFinished('order_456');
        $contract->transitionToPending();

        $this->contractRepository
            ->method('findById')
            ->with('contract_123')
            ->willReturn($contract);

        $this->orderService
            ->expects($this->once())
            ->method('deleteNotFinishedOrder')
            ->with('order_456')
            ->willReturn(true);

        $this->contractRepository
            ->expects($this->once())
            ->method('save')
            ->with($contract);

        $result = $this->service->cleanupPreviousAttempt('contract_123');

        $this->assertTrue($result);
        $this->assertTrue($contract->getState()->isCancelled());
    }

    public function testCleanupSkipsNullContractId(): void
    {
        $this->contractRepository->expects($this->never())->method('findById');
        $this->orderService->expects($this->never())->method('deleteNotFinishedOrder');

        $result = $this->service->cleanupPreviousAttempt(null);

        $this->assertFalse($result);
    }

    public function testCleanupSkipsTerminalContract(): void
    {
        $contract = $this->createContract();
        $contract->transitionToNotFinished('order_456');
        $contract->transitionToPending();
        $contract->fulfillCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED);
        $contract->commitToOrder('order_456');
        // Contract is now COMMITTED (terminal-ish, but not a terminal state)

        $this->contractRepository
            ->method('findById')
            ->willReturn($contract);

        // Committed contract should not be cleaned up
        $this->orderService->expects($this->never())->method('deleteNotFinishedOrder');
        $this->contractRepository->expects($this->never())->method('save');

        $result = $this->service->cleanupPreviousAttempt('contract_123');

        $this->assertFalse($result);
    }

    public function testCleanupHandlesContractWithoutOrder(): void
    {
        $contract = $this->createContract();
        // Contract in DRAFT state, no orderId

        $this->contractRepository
            ->method('findById')
            ->willReturn($contract);

        $this->orderService->expects($this->never())->method('deleteNotFinishedOrder');

        $this->contractRepository
            ->expects($this->once())
            ->method('save')
            ->with($contract);

        $result = $this->service->cleanupPreviousAttempt('contract_123');

        $this->assertTrue($result);
        $this->assertTrue($contract->getState()->isCancelled());
    }

    public function testCleanupSkipsNonExistentContract(): void
    {
        $this->contractRepository
            ->method('findById')
            ->willReturn(null);

        $this->orderService->expects($this->never())->method('deleteNotFinishedOrder');

        $result = $this->service->cleanupPreviousAttempt('contract_nonexistent');

        $this->assertFalse($result);
    }

    // ==========================================
    // cleanupForUser() TESTS
    // ==========================================

    public function testCleanupForUserCancelsActiveContract(): void
    {
        $contract = $this->createContract();
        $contract->transitionToNotFinished('order_789');
        $contract->transitionToPending();

        $this->contractRepository
            ->method('findActiveByUserId')
            ->with('user123')
            ->willReturn($contract);

        $this->orderService
            ->expects($this->once())
            ->method('deleteNotFinishedOrder')
            ->with('order_789')
            ->willReturn(true);

        $this->contractRepository
            ->expects($this->once())
            ->method('save');

        $result = $this->service->cleanupForUser('user123');

        $this->assertTrue($result);
        $this->assertTrue($contract->getState()->isCancelled());
    }

    public function testCleanupForUserSkipsWhenNoActiveContract(): void
    {
        $this->contractRepository
            ->method('findActiveByUserId')
            ->willReturn(null);

        $this->orderService->expects($this->never())->method('deleteNotFinishedOrder');

        $result = $this->service->cleanupForUser('user123');

        $this->assertFalse($result);
    }

    public function testCleanupForUserSkipsCommittedContract(): void
    {
        $contract = $this->createContract();
        $contract->transitionToNotFinished('order_789');
        $contract->transitionToPending();
        $contract->fulfillCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED);
        $contract->commitToOrder('order_789');

        $this->contractRepository
            ->method('findActiveByUserId')
            ->willReturn($contract);

        $this->orderService->expects($this->never())->method('deleteNotFinishedOrder');
        $this->contractRepository->expects($this->never())->method('save');

        $result = $this->service->cleanupForUser('user123');

        $this->assertFalse($result);
    }

    // ==========================================
    // cleanupStaleContracts() TESTS
    // ==========================================

    public function testCleanupStaleCancelsAndDeletesMultipleContracts(): void
    {
        $contract1 = $this->createContract('stale_1');
        $contract1->transitionToNotFinished('order_stale_1');

        $contract2 = $this->createContract('stale_2');
        $contract2->transitionToNotFinished('order_stale_2');

        $this->contractRepository
            ->method('findStaleNotFinished')
            ->with(30)
            ->willReturn([$contract1, $contract2]);

        $this->orderService
            ->expects($this->exactly(2))
            ->method('deleteNotFinishedOrder')
            ->willReturn(true);

        $this->contractRepository
            ->expects($this->exactly(2))
            ->method('save');

        $count = $this->service->cleanupStaleContracts(30);

        $this->assertSame(2, $count);
        $this->assertTrue($contract1->getState()->isCancelled());
        $this->assertTrue($contract2->getState()->isCancelled());
    }

    public function testCleanupStaleReturnsZeroWhenNoStaleContracts(): void
    {
        $this->contractRepository
            ->method('findStaleNotFinished')
            ->with(30)
            ->willReturn([]);

        $this->orderService->expects($this->never())->method('deleteNotFinishedOrder');

        $count = $this->service->cleanupStaleContracts(30);

        $this->assertSame(0, $count);
    }

    public function testCleanupStaleContinuesOnIndividualFailure(): void
    {
        $contract1 = $this->createContract('stale_1');
        $contract1->transitionToNotFinished('order_stale_1');
        $contract1->transitionToPending();
        $contract1->fulfillCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED);
        $contract1->commitToOrder('order_stale_1');
        // contract1 is COMMITTED — cancelContractAndDeleteOrder returns false

        $contract2 = $this->createContract('stale_2');
        $contract2->transitionToNotFinished('order_stale_2');
        // contract2 is NOT_FINISHED — should be cleaned up

        $this->contractRepository
            ->method('findStaleNotFinished')
            ->willReturn([$contract1, $contract2]);

        $this->orderService
            ->expects($this->once())
            ->method('deleteNotFinishedOrder')
            ->with('order_stale_2')
            ->willReturn(true);

        $count = $this->service->cleanupStaleContracts(30);

        $this->assertSame(1, $count);
    }
}
