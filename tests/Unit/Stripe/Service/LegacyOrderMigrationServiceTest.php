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
use OxidEsales\Payments\Stripe\Service\LegacyOrderAssessment;
use OxidEsales\Payments\Stripe\Service\LegacyOrderMigrationService;
use OxidEsales\Payments\Stripe\Service\Result\LegacyOrderVerdict;
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

    /** @var array<string, LegacyOrderVerdict> order id => what the assessment answers */
    private array $verdicts = [];

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

                return [['OXARTNUM' => 'A-1', 'OXTITLE' => 'Widget', 'OXAMOUNT' => '1.5', 'OXBPRICE' => '9.99', 'OXVAT' => '19']];
            }
        );
        $assessment = $this->createMock(LegacyOrderAssessment::class);
        $assessment->method('assess')->willReturnCallback(
            fn (array $row): LegacyOrderVerdict => $this->verdicts[$row['OXID']] ?? $this->paid(119.0)
        );

        $this->service = new LegacyOrderMigrationService($this->connection, $this->contractRepository, $shopAdapter, $assessment);
    }

    /**
     * Sprint 144 (AW): the dry run asks the same assessment as --apply and writes nothing.
     */
    public function testMigrate_InDryRun_ReportsTheSameSkipsAsApplyAndWritesNothing(): void
    {
        $this->candidateRows = [$this->paidOrderRow('ord-1'), $this->paidOrderRow('ord-2', ['OXORDERNR' => '1043'])];
        $this->verdicts['ord-2'] = LegacyOrderVerdict::skip('order is cancelled (storno)');
        $this->contractRepository->expects($this->never())->method('save');

        $dryRun = $this->service->migrate(false);

        $this->assertSame(['ord-1', 'ord-2'], $dryRun->candidateOrderIds);
        $this->assertSame(1, $dryRun->migrated, 'one order would be migrated');
        $this->assertSame(['order 1043' => 'order is cancelled (storno)'], $dryRun->skipped);
    }

    public function testMigrate_WithApply_ReportsTheSameCountsAsTheDryRun(): void
    {
        $this->candidateRows = [$this->paidOrderRow('ord-1'), $this->paidOrderRow('ord-2', ['OXORDERNR' => '1043'])];
        $this->verdicts['ord-2'] = LegacyOrderVerdict::skip('order is cancelled (storno)');

        $dryRun = $this->service->migrate(false);
        $applied = $this->service->migrate(true);

        $this->assertSame($dryRun->migrated, $applied->migrated);
        $this->assertSame($dryRun->skipped, $applied->skipped);
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
    }

    /**
     * The key the regular checkout writes and the module reads.
     */
    public function testMigrate_WithApply_WritesOrderNumberMetadata(): void
    {
        $this->candidateRows = [$this->paidOrderRow('ord-1')];
        $saved = $this->captureSavedContract();

        $this->service->migrate(true);

        $this->assertSame('1042', $saved()->getMetadata('order_number'));
    }

    public function testMigrate_WithApply_TakesCapturedAndRefundedAmountsFromTheAssessment(): void
    {
        $this->candidateRows = [$this->paidOrderRow('ord-1')];
        $this->verdicts['ord-1'] = $this->paid(110.0, 24.88);
        $saved = $this->captureSavedContract();

        $this->service->migrate(true);

        $this->assertSame(110.0, $saved()->getCapturedAmount(), 'what Stripe collected, not OXTOTALORDERSUM');
        $this->assertSame(24.88, $saved()->getRefundedAmount());
        $this->assertNotNull($saved()->getRefundedAt());
    }

    public function testMigrate_WhenTheAssessmentSkips_SavesNothingAndListsTheReason(): void
    {
        $this->candidateRows = [$this->paidOrderRow('ord-1')];
        $this->verdicts['ord-1'] = LegacyOrderVerdict::skip("Stripe reports the PaymentIntent as 'canceled', not paid");
        $this->contractRepository->expects($this->never())->method('save');

        $report = $this->service->migrate(true);

        $this->assertSame(0, $report->migrated);
        $this->assertStringContainsString('not paid', $report->skipped['order 1042']);
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

    private function paid(float $captured, float $refunded = 0.0): LegacyOrderVerdict
    {
        return LegacyOrderVerdict::migrate($captured, $refunded, new \DateTimeImmutable('2025-04-01 12:00:00'));
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
