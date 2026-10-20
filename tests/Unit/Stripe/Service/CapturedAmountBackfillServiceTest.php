<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Service;

use Doctrine\DBAL\Connection;
use OxidEsales\PaymentBase\Adapter\ShopAdapterInterface;
use OxidEsales\PaymentBase\Contract\BasketSnapshot;
use OxidEsales\PaymentBase\Contract\ContractCondition;
use OxidEsales\PaymentBase\Contract\PaymentContract;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Repository\TransactionRepositoryInterface;
use OxidEsales\Payments\Stripe\Adapter\Dto\StripePaymentIntentDto;
use OxidEsales\Payments\Stripe\Adapter\StripeAdapterInterface;
use OxidEsales\Payments\Stripe\Service\CapturedAmountBackfillService;
use OxidEsales\Payments\Stripe\Service\ContractCaptureRecorder;
use OxidEsales\Payments\Stripe\Service\Factory\StripeAdapterFactoryInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[\PHPUnit\Framework\Attributes\CoversClass(CapturedAmountBackfillService::class)]
final class CapturedAmountBackfillServiceTest extends TestCase
{
    private Connection&MockObject $connection;
    private ContractRepositoryInterface&MockObject $contractRepository;
    private TransactionRepositoryInterface&MockObject $transactionRepository;
    private StripeAdapterInterface&MockObject $stripeAdapter;
    private StripeAdapterFactoryInterface&MockObject $adapterFactory;
    private CapturedAmountBackfillService $service;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(Connection::class);
        $this->contractRepository = $this->createMock(ContractRepositoryInterface::class);
        $this->transactionRepository = $this->createMock(TransactionRepositoryInterface::class);
        $this->transactionRepository->method('findByContractId')->willReturn([]);
        $this->stripeAdapter = $this->createMock(StripeAdapterInterface::class);
        $this->adapterFactory = $this->createMock(StripeAdapterFactoryInterface::class);
        $this->adapterFactory->method('getStripeAdapter')->willReturn($this->stripeAdapter);
        $shopAdapter = $this->createMock(ShopAdapterInterface::class);
        $shopAdapter->method('getShopId')->willReturn('1');

        $this->service = new CapturedAmountBackfillService(
            $this->connection,
            $this->contractRepository,
            $this->adapterFactory,
            new ContractCaptureRecorder($this->contractRepository, $this->transactionRepository, $shopAdapter),
            $shopAdapter
        );
    }

    public function testBackfill_InDryRun_ReportsCandidatesAndWritesNothing(): void
    {
        $this->candidates([['OXID' => 'ctr-1', 'OXPROVIDERORDERID' => 'pi_1']]);
        $this->stripeAdapter->expects($this->never())->method('retrievePaymentIntent');
        $this->contractRepository->expects($this->never())->method('save');
        $this->transactionRepository->expects($this->never())->method('save');

        $report = $this->service->backfill(false);

        $this->assertSame(['ctr-1'], $report->candidateContractIds);
        $this->assertSame(0, $report->recorded);
    }

    public function testBackfill_QueriesFulfilledStripeContractsWithoutCapturedAmountOfTheCurrentShop(): void
    {
        $this->connection->expects($this->once())->method('fetchAllAssociative')
            ->with(
                $this->logicalAnd(
                    $this->stringContains('oe_payments_contract'),
                    $this->stringContains("OXSTATE = 'fulfilled'"),
                    $this->stringContains('OXCAPTUREDAMOUNT IS NULL'),
                    $this->stringContains('OXPROVIDER = :provider'),
                    $this->stringContains('OXSHOPID = :shopId')
                ),
                ['provider' => 'stripe', 'shopId' => 1]
            )
            ->willReturn([]);

        $report = $this->service->backfill(false);

        $this->assertSame([], $report->candidateContractIds);
    }

    public function testBackfill_WithApply_RecordsAmountReceivedFromPaymentIntent(): void
    {
        $contract = $this->fulfilledContract('ctr-1');
        $this->candidates([['OXID' => 'ctr-1', 'OXPROVIDERORDERID' => 'pi_1']]);
        $this->contractRepository->method('findById')->with('ctr-1')->willReturn($contract);
        $this->stripeAdapter->method('retrievePaymentIntent')->with('pi_1')
            ->willReturn($this->intent('pi_1', 'succeeded', 1999));

        $report = $this->service->backfill(true);

        $this->assertSame(1, $report->recorded);
        $this->assertSame(19.99, $contract->getCapturedAmount());
    }

    public function testBackfill_WhenPaymentIntentNotSucceeded_SkipsAndReports(): void
    {
        $contract = $this->fulfilledContract('ctr-1');
        $this->candidates([['OXID' => 'ctr-1', 'OXPROVIDERORDERID' => 'pi_1']]);
        $this->contractRepository->method('findById')->willReturn($contract);
        $this->stripeAdapter->method('retrievePaymentIntent')
            ->willReturn($this->intent('pi_1', 'requires_capture', 0));

        $report = $this->service->backfill(true);

        $this->assertSame(0, $report->recorded);
        $this->assertArrayHasKey('ctr-1', $report->skipped);
        $this->assertStringContainsString('requires_capture', $report->skipped['ctr-1']);
        $this->assertNull($contract->getCapturedAmount());
    }

    public function testBackfill_WhenStripeCallFails_ContinuesWithNextAndReportsFailure(): void
    {
        $second = $this->fulfilledContract('ctr-2');
        $this->candidates([
            ['OXID' => 'ctr-1', 'OXPROVIDERORDERID' => 'pi_1'],
            ['OXID' => 'ctr-2', 'OXPROVIDERORDERID' => 'pi_2'],
        ]);
        $this->contractRepository->method('findById')->willReturnMap([
            ['ctr-1', $this->fulfilledContract('ctr-1')],
            ['ctr-2', $second],
        ]);
        $this->stripeAdapter->method('retrievePaymentIntent')->willReturnCallback(
            fn (string $id): StripePaymentIntentDto => $id === 'pi_1'
                ? throw new \RuntimeException('stripe down')
                : $this->intent('pi_2', 'succeeded', 5000)
        );

        $report = $this->service->backfill(true);

        $this->assertSame(1, $report->recorded);
        $this->assertSame(['ctr-1' => 'stripe down'], $report->failed);
        $this->assertSame(50.0, $second->getCapturedAmount());
    }

    public function testBackfill_WhenContractVanished_SkipsAndReports(): void
    {
        $this->candidates([['OXID' => 'ctr-1', 'OXPROVIDERORDERID' => 'pi_1']]);
        $this->contractRepository->method('findById')->willReturn(null);
        $this->stripeAdapter->expects($this->never())->method('retrievePaymentIntent');

        $report = $this->service->backfill(true);

        $this->assertArrayHasKey('ctr-1', $report->skipped);
    }

    /**
     * @param list<array<string, string>> $rows
     */
    private function candidates(array $rows): void
    {
        $this->connection->method('fetchAllAssociative')->willReturn($rows);
    }

    private function intent(string $id, string $status, int $amountReceived): StripePaymentIntentDto
    {
        return new StripePaymentIntentDto(
            id: $id,
            status: $status,
            amount: $amountReceived,
            currency: 'eur',
            created: 1700000000,
            latestChargeId: null,
            amountReceived: $amountReceived
        );
    }

    private function fulfilledContract(string $id): PaymentContract
    {
        $snapshot = BasketSnapshot::fromArray([
            'items' => [],
            'discounts' => [],
            'totalGross' => 100.0,
            'totalNet' => 84.03,
            'totalVat' => 15.97,
            'currency' => 'EUR',
        ]);
        $contract = new PaymentContract(1, 'user-1', $snapshot, $id);
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));
        $contract->transitionToNotFinished('order-' . $id);
        $contract->transitionToPending();
        $contract->fulfillCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED);
        $contract->commitToOrder('order-' . $id);
        $contract->fulfill();

        return $contract;
    }
}
