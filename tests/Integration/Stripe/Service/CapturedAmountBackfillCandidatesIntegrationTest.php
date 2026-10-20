<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Integration\Stripe\Service;

use Doctrine\DBAL\Connection;
use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\EshopCommunity\Internal\Framework\Database\ConnectionProviderInterface;
use OxidEsales\EshopCommunity\Tests\Integration\IntegrationTestCase;
use OxidEsales\PaymentBase\Adapter\ShopAdapterInterface;
use OxidEsales\PaymentBase\Contract\BasketSnapshot;
use OxidEsales\PaymentBase\Contract\ContractCondition;
use OxidEsales\PaymentBase\Contract\PaymentContract;
use OxidEsales\PaymentBase\Repository\DoctrineContractRepository;
use OxidEsales\PaymentBase\Repository\TransactionRepositoryInterface;
use OxidEsales\Payments\Stripe\Service\CapturedAmountBackfillService;
use OxidEsales\Payments\Stripe\Service\ContractCaptureRecorder;
use OxidEsales\Payments\Stripe\Service\Factory\StripeAdapterFactoryInterface;

/**
 * Sprint 137 · Story 2: the candidate query runs against the real
 * oe_payments_contract table (column names are not covered by the unit test).
 */
#[\PHPUnit\Framework\Attributes\Group('integration')]
#[\PHPUnit\Framework\Attributes\Group('sprint-137')]
final class CapturedAmountBackfillCandidatesIntegrationTest extends IntegrationTestCase
{
    private const TEST_PREFIX = 'ox_test_';
    private const SHOP_ID = 1;

    private Connection $connection;
    private DoctrineContractRepository $contractRepository;
    private string $runId;

    public function setUp(): void
    {
        parent::setUp();

        $this->runId = date('His') . substr(uniqid(), -4);
        $container = ContainerFactory::getInstance()->getContainer();
        /** @var ConnectionProviderInterface $connectionProvider */
        $connectionProvider = $container->get(ConnectionProviderInterface::class);
        $this->connection = $connectionProvider->get();
        $this->contractRepository = new DoctrineContractRepository($this->connection);
    }

    public function tearDown(): void
    {
        $this->connection->executeStatement(
            'DELETE FROM oe_payments_contract WHERE OXID LIKE ?',
            [self::TEST_PREFIX . '%']
        );
        parent::tearDown();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function dryRunListsOnlyFulfilledStripeContractsWithoutCapturedAmount(): void
    {
        $candidate = $this->saveContract('cand', 'stripe', true, null);
        $withAmount = $this->saveContract('amt', 'stripe', true, 10.0);
        $otherProvider = $this->saveContract('pp', 'paypal', true, null);
        $notFulfilled = $this->saveContract('open', 'stripe', false, null);

        $report = $this->service()->backfill(false);

        $this->assertContains($candidate, $report->candidateContractIds);
        $this->assertNotContains($withAmount, $report->candidateContractIds);
        $this->assertNotContains($otherProvider, $report->candidateContractIds);
        $this->assertNotContains($notFulfilled, $report->candidateContractIds);
        $this->assertSame(0, $report->recorded);
    }

    private function service(): CapturedAmountBackfillService
    {
        $shopAdapter = $this->createMock(ShopAdapterInterface::class);
        $shopAdapter->method('getShopId')->willReturn((string) self::SHOP_ID);

        return new CapturedAmountBackfillService(
            $this->connection,
            $this->contractRepository,
            $this->createMock(StripeAdapterFactoryInterface::class),
            new ContractCaptureRecorder(
                $this->contractRepository,
                $this->createMock(TransactionRepositoryInterface::class),
                $shopAdapter
            ),
            $shopAdapter
        );
    }

    private function saveContract(string $label, string $provider, bool $fulfilled, ?float $capturedAmount): string
    {
        $id = substr(self::TEST_PREFIX . $label . '_' . $this->runId, 0, 32);
        $snapshot = BasketSnapshot::fromArray([
            'items' => [],
            'discounts' => [],
            'totalGross' => 100.0,
            'totalNet' => 84.0,
            'totalVat' => 16.0,
            'currency' => 'EUR',
            'capturedAt' => date('Y-m-d H:i:s'),
        ]);
        $contract = new PaymentContract(self::SHOP_ID, 'ox_test_user', $snapshot, $id);
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));
        $contract->transitionToNotFinished('ox_test_order_' . $label);
        $contract->transitionToPending();
        $contract->setProvider($provider, 'pi_' . $label . $this->runId);
        if ($fulfilled) {
            $contract->fulfillCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED);
            $contract->commitToOrder('ox_test_order_' . $label);
            $contract->fulfill();
        }
        if ($capturedAmount !== null) {
            $contract->setCapturedAmount($capturedAmount);
        }
        $this->contractRepository->save($contract);

        return $id;
    }
}
