<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Service;

use OxidEsales\PaymentBase\Adapter\ShopAdapterInterface;
use OxidEsales\PaymentBase\Contract\BasketSnapshot;
use OxidEsales\PaymentBase\Contract\ContractCondition;
use OxidEsales\PaymentBase\Contract\PaymentContract;
use OxidEsales\PaymentBase\Contract\Transaction;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Repository\TransactionRepositoryInterface;
use OxidEsales\Payments\Stripe\Core\ShopId;
use OxidEsales\Payments\Stripe\Service\ContractCaptureRecorder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[\PHPUnit\Framework\Attributes\CoversClass(ContractCaptureRecorder::class)]
final class ContractCaptureRecorderTest extends TestCase
{
    private ContractRepositoryInterface&MockObject $contractRepository;
    private TransactionRepositoryInterface&MockObject $transactionRepository;
    private LoggerInterface&MockObject $logger;
    private ContractCaptureRecorder $recorder;

    protected function setUp(): void
    {
        $this->contractRepository = $this->createMock(ContractRepositoryInterface::class);
        $this->transactionRepository = $this->createMock(TransactionRepositoryInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $shopAdapter = $this->createMock(ShopAdapterInterface::class);
        $shopAdapter->method('getShopId')->willReturn('1');

        $this->recorder = new ContractCaptureRecorder(
            $this->contractRepository,
            $this->transactionRepository,
            $shopAdapter,
            $this->logger
        );
    }

    public function testRecord_SetsAmountAndWritesRow(): void
    {
        $contract = $this->fulfilledContract();
        $this->transactionRepository->method('findByContractId')->willReturn([]);
        $this->contractRepository->expects($this->once())->method('save')->with($contract);
        $this->transactionRepository->expects($this->once())->method('save')
            ->with($this->callback(static fn (Transaction $row): bool => $row->getType() === 'capture'
                && $row->getAmount() === 19.99
                && $row->getContractId() === 'ctr-1'));

        $this->recorder->record($contract, 19.99);

        $this->assertSame(19.99, $contract->getCapturedAmount());
    }

    public function testRecord_WhenRowExists_WritesNoSecondRow(): void
    {
        $contract = $this->fulfilledContract();
        $this->transactionRepository->method('findByContractId')->willReturn([$this->captureRow()]);
        $this->contractRepository->expects($this->once())->method('save');
        $this->transactionRepository->expects($this->never())->method('save');

        $this->recorder->record($contract, 30.00);

        $this->assertSame(30.00, $contract->getCapturedAmount());
    }

    public function testRecord_WhenAmountKnown_IsNoOp(): void
    {
        $contract = $this->fulfilledContract();
        $contract->setCapturedAmount(42.50);
        $this->contractRepository->expects($this->never())->method('save');
        $this->transactionRepository->expects($this->never())->method('save');

        $this->recorder->record($contract, 99.00);

        $this->assertSame(42.50, $contract->getCapturedAmount());
    }

    /**
     * @return iterable<string, array{0: ?float}>
     */
    public static function unusableAmounts(): iterable
    {
        yield 'missing' => [null];
        yield 'zero' => [0.0];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unusableAmounts')]
    public function testRecord_WithZeroOrMissingAmount_LogsErrorAndChangesNothing(?float $amount): void
    {
        $contract = $this->fulfilledContract();
        $this->logger->expects($this->once())->method('error')
            ->with($this->stringContains('Capture audit row skipped'));
        $this->contractRepository->expects($this->never())->method('save');
        $this->transactionRepository->expects($this->never())->method('save');

        $this->recorder->record($contract, $amount);

        $this->assertNull($contract->getCapturedAmount());
    }

    private function captureRow(): Transaction
    {
        return new Transaction(
            id: 'cap_manual',
            shopId: ShopId::of(1, 'test'),
            orderId: 'order-1',
            contractId: 'ctr-1',
            provider: 'stripe',
            type: 'capture',
            status: 'completed',
            amount: 30.00,
            currency: 'EUR'
        );
    }

    private function fulfilledContract(): PaymentContract
    {
        $snapshot = BasketSnapshot::fromArray([
            'items' => [],
            'discounts' => [],
            'totalGross' => 100.0,
            'totalNet' => 84.03,
            'totalVat' => 15.97,
            'currency' => 'EUR',
        ]);
        $contract = new PaymentContract(1, 'user-1', $snapshot, 'ctr-1');
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));
        $contract->transitionToNotFinished('order-1');
        $contract->transitionToPending();
        $contract->fulfillCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED);
        $contract->commitToOrder('order-1');
        $contract->fulfill();

        return $contract;
    }
}
