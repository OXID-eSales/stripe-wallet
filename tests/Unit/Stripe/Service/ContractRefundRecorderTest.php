<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Service;

use OxidEsales\PaymentBase\Contract\ContractState;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\Payments\Stripe\Service\ContractRefundRecorder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ContractRefundRecorder.
 *
 * Sprint 114.8: Extracted refund recording with FULFILLED guard from D3 duplication sites.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\OxidEsales\Payments\Stripe\Service\ContractRefundRecorder::class)]
#[\PHPUnit\Framework\Attributes\Group('sprint-114-8')]
final class ContractRefundRecorderTest extends TestCase
{
    private ContractRepositoryInterface&MockObject $contractRepository;
    private ContractRefundRecorder $recorder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contractRepository = $this->createMock(ContractRepositoryInterface::class);
        $this->recorder = new ContractRefundRecorder($this->contractRepository);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function recordsRefundOnFulfilledContract(): void
    {
        $contract = $this->createFulfilledContract();

        $contract->expects($this->once())->method('addRefundedAmount')->with(25.0);
        $contract->expects($this->once())->method('setRefundedAt');
        $this->contractRepository->expects($this->once())->method('save')->with($contract);

        $this->recorder->record($contract, 25.0);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function doesNotRecordRefundOnNonFulfilledContract(): void
    {
        $contract = $this->createNonFulfilledContract();

        $contract->expects($this->never())->method('addRefundedAmount');
        $contract->expects($this->never())->method('setRefundedAt');
        $this->contractRepository->expects($this->never())->method('save');

        $this->recorder->record($contract, 25.0, 'contract_abc');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function recordsZeroAmountRefundOnFulfilledContract(): void
    {
        $contract = $this->createFulfilledContract();

        $contract->expects($this->once())->method('addRefundedAmount')->with(0.0);
        $contract->expects($this->once())->method('setRefundedAt');
        $this->contractRepository->expects($this->once())->method('save');

        $this->recorder->record($contract, 0.0);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function createFulfilledContract(): PaymentContractInterface&MockObject
    {
        $state = $this->createMock(ContractState::class);
        $state->method('isFulfilled')->willReturn(true);

        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getState')->willReturn($state);

        return $contract;
    }

    private function createNonFulfilledContract(): PaymentContractInterface&MockObject
    {
        $state = $this->createMock(ContractState::class);
        $state->method('isFulfilled')->willReturn(false);
        $state->method('getValue')->willReturn('committed');

        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getState')->willReturn($state);

        return $contract;
    }
}
