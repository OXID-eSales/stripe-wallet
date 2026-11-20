<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Service;

use Doctrine\DBAL\Connection;
use OxidEsales\PaymentBase\Adapter\ShopAdapterInterface;
use OxidEsales\PaymentBase\Contract\PaymentContract;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\Payments\Stripe\Service\LegacyOrderMigrationService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * STRP-181: legacy oxid-esales/stripe-module orders are adopted by creating a
 * FULFILLED payment-base contract per order. The real PaymentContract domain
 * model runs through its real lifecycle here — only the DB and the repository
 * are mocked.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(LegacyOrderMigrationService::class)]
final class LegacyOrderMigrationServiceTest extends TestCase
{
    private Connection&MockObject $connection;
    private ContractRepositoryInterface&MockObject $contractRepository;
    private LegacyOrderMigrationService $service;

    /** @var list<array<string, mixed>> */
    private array $candidateRows = [];

    /** @var array<string, float> order id => legacy refunded total on oxorder columns */
    private array $orderRefunds = [];

    /** @var array<string, float> order id => legacy refunded total on article rows */
    private array $articleRefunds = [];

    private bool $legacyColumnsInstalled = true;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(Connection::class);
        $this->contractRepository = $this->createMock(ContractRepositoryInterface::class);
        $shopAdapter = $this->createMock(ShopAdapterInterface::class);
        $shopAdapter->method('getShopId')->willReturn('1');

        $this->connection->method('fetchAllAssociative')->willReturnCallback(
            function (string $sql): array {
                if (str_contains($sql, 'oe_payments_contract')) {
                    return $this->candidateRows;
                }
                if (str_contains($sql, 'oxorderarticles')) {
                    return [
                        ['OXARTNUM' => 'A-1', 'OXTITLE' => 'Widget', 'OXAMOUNT' => '2', 'OXBPRICE' => '9.99', 'OXVAT' => '19'],
                    ];
                }
                return [];
            }
        );
        $this->connection->method('fetchFirstColumn')->willReturnCallback(
            fn (): array => $this->legacyColumnsInstalled
                ? [
                    'STRIPEDELCOSTREFUNDED', 'STRIPEPAYCOSTREFUNDED', 'STRIPEWRAPCOSTREFUNDED',
                    'STRIPEGIFTCARDREFUNDED', 'STRIPEVOUCHERDISCOUNTREFUNDED', 'STRIPEDISCOUNTREFUNDED',
                    'STRIPEAMOUNTREFUNDED',
                ]
                : []
        );
        $this->connection->method('fetchOne')->willReturnCallback(
            function (string $sql, array $params): float {
                $orderId = (string) ($params['orderId'] ?? '');
                if (str_contains($sql, 'oxorderarticles')) {
                    return $this->articleRefunds[$orderId] ?? 0.0;
                }
                return $this->orderRefunds[$orderId] ?? 0.0;
            }
        );

        $this->service = new LegacyOrderMigrationService(
            $this->connection,
            $this->contractRepository,
            $shopAdapter
        );
    }

    public function testMigrate_InDryRun_ListsCandidatesAndWritesNothing(): void
    {
        $this->candidateRows = [$this->paidOrderRow('ord-1')];
        $this->contractRepository->expects($this->never())->method('save');

        $report = $this->service->migrate(false);

        $this->assertSame(['ord-1'], $report->candidateOrderIds);
        $this->assertSame(0, $report->migrated);
    }

    public function testMigrate_WithApply_CreatesAFulfilledStripeContractForThePaidLegacyOrder(): void
    {
        $this->candidateRows = [$this->paidOrderRow('ord-1')];
        $saved = $this->captureSavedContract();

        $report = $this->service->migrate(true);

        $this->assertSame(1, $report->migrated);
        $this->assertSame([], $report->skipped);
        $this->assertSame([], $report->failed);

        $contract = $saved();
        $this->assertInstanceOf(PaymentContract::class, $contract);
        $this->assertTrue($contract->isInState('fulfilled'));
        $this->assertSame('stripe', $contract->getProvider());
        $this->assertSame('pi_legacy_1', $contract->getProviderOrderId());
        $this->assertSame('ord-1', $contract->getOrderId());
        $this->assertSame(1, $contract->getShopId());
        $this->assertSame('user-1', $contract->getUserId());
        $this->assertSame(119.0, $contract->getCapturedAmount());
        $this->assertSame(119.0, $contract->getAmount());
        $this->assertSame('EUR', $contract->getCurrency());
        $this->assertNull($contract->getRefundedAmount());

        $metadata = $contract->getMetadata('legacyMigration');
        $this->assertIsArray($metadata);
        $this->assertSame('oxid-esales/stripe-module', $metadata['migratedFrom']);
        $this->assertSame('stripecreditcard', $metadata['legacyPaymentType']);
        $this->assertSame('1042', $metadata['orderNumber']);
    }

    public function testMigrate_CarriesTheLegacyRefundBookkeepingOntoTheContract(): void
    {
        $this->candidateRows = [$this->paidOrderRow('ord-1')];
        $this->orderRefunds['ord-1'] = 4.90;
        $this->articleRefunds['ord-1'] = 19.98;
        $saved = $this->captureSavedContract();

        $report = $this->service->migrate(true);

        $this->assertSame(1, $report->migrated);
        $this->assertSame(24.88, $saved()->getRefundedAmount());
        $this->assertNotNull($saved()->getRefundedAt());
    }

    public function testMigrate_WithoutLegacyColumns_MigratesWithNoRefunds(): void
    {
        $this->legacyColumnsInstalled = false;
        $this->candidateRows = [$this->paidOrderRow('ord-1')];
        $saved = $this->captureSavedContract();

        $report = $this->service->migrate(true);

        $this->assertSame(1, $report->migrated);
        $this->assertNull($saved()->getRefundedAmount());
    }

    public function testMigrate_SkipsOrderWhoseTransactionIdIsNotAPaymentIntent(): void
    {
        $this->candidateRows = [
            $this->paidOrderRow('ord-1', ['OXTRANSID' => 'ch_oldcharge']),
            $this->paidOrderRow('ord-2', ['OXTRANSID' => '', 'OXORDERNR' => '1043']),
        ];
        $this->contractRepository->expects($this->never())->method('save');

        $report = $this->service->migrate(true);

        $this->assertSame(0, $report->migrated);
        $this->assertStringContainsString('not a PaymentIntent', $report->skipped['order 1042']);
        $this->assertStringContainsString('no transaction id', $report->skipped['order 1043']);
    }

    public function testMigrate_SkipsCancelledAndUnpaidOrders(): void
    {
        $this->candidateRows = [
            $this->paidOrderRow('ord-1', ['OXSTORNO' => '1']),
            $this->paidOrderRow('ord-2', ['OXPAID' => '0000-00-00 00:00:00', 'OXORDERNR' => '1043']),
        ];
        $this->contractRepository->expects($this->never())->method('save');

        $report = $this->service->migrate(true);

        $this->assertSame(0, $report->migrated);
        $this->assertStringContainsString('cancelled', $report->skipped['order 1042']);
        $this->assertStringContainsString('never paid', $report->skipped['order 1043']);
    }

    public function testMigrate_SkipsOrderWhoseLegacyRefundsExceedTheTotal(): void
    {
        $this->candidateRows = [$this->paidOrderRow('ord-1')];
        $this->orderRefunds['ord-1'] = 500.0;
        $this->contractRepository->expects($this->never())->method('save');

        $report = $this->service->migrate(true);

        $this->assertSame(0, $report->migrated);
        $this->assertStringContainsString('resolve manually', $report->skipped['order 1042']);
    }

    public function testMigrate_WhenSaveFails_ContinuesWithTheNextOrderAndReportsTheFailure(): void
    {
        $this->candidateRows = [
            $this->paidOrderRow('ord-1'),
            $this->paidOrderRow('ord-2', ['OXORDERNR' => '1043']),
        ];
        $calls = 0;
        $this->contractRepository->method('save')->willReturnCallback(
            function () use (&$calls): void {
                $calls++;
                if ($calls === 1) {
                    throw new \RuntimeException('row is locked');
                }
            }
        );

        $report = $this->service->migrate(true);

        $this->assertSame(1, $report->migrated);
        $this->assertSame(['order 1042' => 'row is locked'], $report->failed);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function paidOrderRow(string $orderId, array $overrides = []): array
    {
        return array_merge([
            'OXID' => $orderId,
            'OXUSERID' => 'user-1',
            'OXORDERNR' => '1042',
            'OXTRANSID' => 'pi_legacy_1',
            'OXPAYMENTTYPE' => 'stripecreditcard',
            'OXTOTALORDERSUM' => '119.00',
            'OXTOTALNETSUM' => '100.00',
            'OXCURRENCY' => 'EUR',
            'OXPAID' => '2025-04-01 12:00:00',
            'OXSTORNO' => '0',
        ], $overrides);
    }

    /**
     * @return callable(): PaymentContractInterface
     */
    private function captureSavedContract(): callable
    {
        $captured = null;
        $this->contractRepository->method('save')->willReturnCallback(
            function (PaymentContractInterface $contract) use (&$captured): void {
                $captured = $contract;
            }
        );

        return function () use (&$captured): PaymentContractInterface {
            $this->assertNotNull($captured, 'no contract was saved');
            return $captured;
        };
    }
}
