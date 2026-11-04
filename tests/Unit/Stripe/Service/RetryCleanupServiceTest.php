<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Service;

use OxidEsales\PaymentBase\Adapter\ShopOrderServiceInterface;
use OxidEsales\PaymentBase\Contract\BasketSnapshot;
use OxidEsales\PaymentBase\Contract\ContractCondition;
use OxidEsales\PaymentBase\Contract\PaymentContract;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\Payments\Stripe\Service\RetryCleanupService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Sprint 72: Tests for RetryCleanupService.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\OxidEsales\Payments\Stripe\Service\RetryCleanupService::class)]
class RetryCleanupServiceTest extends TestCase
{
    private ContractRepositoryInterface&MockObject $contractRepository;
    private ShopOrderServiceInterface&MockObject $orderService;
    private RetryCleanupService $service;

    protected function setUp(): void
    {
        $this->contractRepository = $this->createMock(ContractRepositoryInterface::class);
        $this->orderService = $this->createMock(ShopOrderServiceInterface::class);
        $this->service = new RetryCleanupService(
            $this->contractRepository,
            $this->orderService,
            $this->unpaidSessions(),
            new \Psr\Log\NullLogger(),
            $this->createMock(\OxidEsales\Payments\Stripe\Service\CheckoutInFlightGuard::class)
        );
    }


    // =========================================================================
    // Sprint 133 · Story 15 (F15)
    //
    // cancelContractAndDeleteOrder() returned a bare false for three different
    // situations — wrong concrete type, already-terminal contract, nothing found
    // — so the caller (cleanupStaleContracts(), run after EVERY webhook) could
    // not tell "nothing to do" from "could not act", and a contract that can
    // never satisfy the guard was re-fetched forever in silence. The sibling
    // check in StripePaymentHandler throws a LogicException naming the class:
    // one invariant, two opposite reactions.
    // =========================================================================

    public function testCleanupLogsWhenItSkipsAnAlreadyTerminalContract(): void
    {
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $logger->expects($this->once())
            ->method('info')
            ->with($this->stringContains('skipped'), $this->anything());

        $contract = $this->createContract('contract_term');
        $contract->cancel('earlier');

        $this->contractRepository->method('findStaleNotFinished')->willReturn([$contract]);

        $service = new RetryCleanupService(
            $this->contractRepository,
            $this->orderService,
            $this->unpaidSessions(),
            $logger,
            $this->createMock(\OxidEsales\Payments\Stripe\Service\CheckoutInFlightGuard::class)
        );

        $this->assertSame(0, $service->cleanupStaleContracts(30));
    }

    public function testCleanupWorksWithAnyPaymentContractInterfaceImplementation(): void
    {
        // Every method this service needs — getState(), getOrderId(), cancel() —
        // is on PaymentContractInterface, so depending on the concrete class was
        // an unnecessary DIP break that silently rejected other implementations.
        $contract = $this->createMock(\OxidEsales\PaymentBase\Contract\PaymentContractInterface::class);
        $contract->method('getId')->willReturn('c1');
        $contract->method('getState')->willReturn(\OxidEsales\PaymentBase\Contract\ContractState::pending());
        $contract->method('getOrderId')->willReturn(null);
        $contract->expects($this->once())->method('cancel')->with('checkout_retry');

        $this->contractRepository->method('findStaleNotFinished')->willReturn([$contract]);

        $service = new RetryCleanupService(
            $this->contractRepository,
            $this->orderService,
            $this->unpaidSessions(),
            new \Psr\Log\NullLogger(),
            $this->createMock(\OxidEsales\Payments\Stripe\Service\CheckoutInFlightGuard::class)
        );

        $this->assertSame(1, $service->cleanupStaleContracts(30));
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
            ->method('findStaleNotFinished')
            ->willReturn([$contract]);

        $this->orderService
            ->expects($this->once())
            ->method('deleteNotFinishedOrder')
            ->with('order_456')
            ->willReturn(true);

        $this->contractRepository
            ->expects($this->once())
            ->method('save')
            ->with($contract);

        $result = $this->service->cleanupStaleContracts(30);

        $this->assertSame(1, $result);
        $this->assertTrue($contract->getState()->isCancelled());
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
            ->method('findStaleNotFinished')
            ->willReturn([$contract]);

        // Committed contract should not be cleaned up
        $this->orderService->expects($this->never())->method('deleteNotFinishedOrder');
        $this->contractRepository->expects($this->never())->method('save');

        $result = $this->service->cleanupStaleContracts(30);

        $this->assertSame(0, $result);
    }

    public function testCleanupHandlesContractWithoutOrder(): void
    {
        $contract = $this->createContract();
        // Contract in DRAFT state, no orderId

        $this->contractRepository
            ->method('findStaleNotFinished')
            ->willReturn([$contract]);

        $this->orderService->expects($this->never())->method('deleteNotFinishedOrder');

        $this->contractRepository
            ->expects($this->once())
            ->method('save')
            ->with($contract);

        $result = $this->service->cleanupStaleContracts(30);

        $this->assertSame(1, $result);
        $this->assertTrue($contract->getState()->isCancelled());
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

    /** None of these contracts has a paid Stripe session. */
    private function unpaidSessions(): \OxidEsales\Payments\Stripe\Service\PaidSessionSettler
    {
        $settler = $this->createMock(\OxidEsales\Payments\Stripe\Service\PaidSessionSettler::class);
        $settler->method('settle')->willReturn(false);

        return $settler;
    }
}
