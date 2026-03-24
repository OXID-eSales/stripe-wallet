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
use OxidEsales\PaymentComponent\Contract\BasketSnapshot;
use OxidEsales\PaymentComponent\Contract\ContractCondition;
use OxidEsales\PaymentComponent\Contract\PaymentContract;
use OxidEsales\PaymentComponent\Repository\DoctrineContractRepository;
use OxidEsales\Payments\Stripe\Adapter\OxidShopOrderService;
use OxidEsales\Payments\Stripe\Service\RetryCleanupService;

/**
 * Integration tests for stale NOT_FINISHED order cleanup via RetryCleanupService.
 *
 * Verifies that abandoned checkout contracts/orders older than the threshold
 * are cleaned up, while recent ones are preserved.
 *
 * @group integration
 * @group stale-cleanup
 * @group strp-100
 */
final class StaleOrderCleanupIntegrationTest extends IntegrationTestCase
{
    private const TEST_PREFIX = 'ox_test_';
    private const SHOP_ID = 1;

    private Connection $connection;
    private DoctrineContractRepository $contractRepository;
    private RetryCleanupService $cleanupService;
    private string $testRunId;

    public function setUp(): void
    {
        parent::setUp();

        $this->testRunId = date('His') . '_' . substr(uniqid(), -4);

        $container = ContainerFactory::getInstance()->getContainer();

        /** @var ConnectionProviderInterface $connectionProvider */
        $connectionProvider = $container->get(ConnectionProviderInterface::class);
        $this->connection = $connectionProvider->get();

        $this->contractRepository = new DoctrineContractRepository($this->connection);

        $orderService = new OxidShopOrderService();
        $this->cleanupService = new RetryCleanupService($this->contractRepository, $orderService);
    }

    public function tearDown(): void
    {
        $this->cleanupTestData();
        parent::tearDown();
    }

    /**
     * @test
     */
    public function cleanupDeletesStaleNotFinishedOrderAndCancelsContract(): void
    {
        // Arrange: Create user, order, and contract backdated 35 minutes
        $userId = $this->createTestUser();
        $orderId = $this->createTestOrder($userId, 100.00, 'EUR');
        $contractId = $this->createContractId('stale');

        $contract = $this->createContract($contractId, $userId);
        $contract->addCondition(ContractCondition::paymentAuthorized());
        $contract->transitionToNotFinished($orderId);
        $contract->transitionToPending();
        $this->contractRepository->save($contract);

        // Backdate the contract's OXCREATED to 35 minutes ago
        $this->connection->executeStatement(
            'UPDATE oe_payments_contract SET OXCREATED = DATE_SUB(NOW(), INTERVAL 35 MINUTE) WHERE OXID = :id',
            ['id' => $contractId]
        );

        // Act: Clean up contracts older than 30 minutes
        $cleaned = $this->cleanupService->cleanupStaleContracts(30);

        // Assert: At least one contract cleaned (may include pre-existing stale data)
        $this->assertGreaterThanOrEqual(1, $cleaned, 'Expected at least 1 stale contract to be cleaned');

        // Assert: Order deleted from oxorder
        $orderRow = $this->connection->fetchAssociative(
            'SELECT OXID FROM oxorder WHERE OXID = :id',
            ['id' => $orderId]
        );
        $this->assertFalse($orderRow, 'Stale NOT_FINISHED order should be deleted');

        // Assert: Contract state is cancelled
        $contractRow = $this->connection->fetchAssociative(
            'SELECT OXSTATE FROM oe_payments_contract WHERE OXID = :id',
            ['id' => $contractId]
        );
        $this->assertNotFalse($contractRow, 'Contract should still exist');
        $this->assertSame('cancelled', $contractRow['OXSTATE'], 'Contract should be cancelled');
    }

    /**
     * @test
     */
    public function cleanupSkipsRecentNotFinishedOrder(): void
    {
        // Arrange: Create user, order, and contract with current timestamp (fresh)
        $userId = $this->createTestUser();
        $orderId = $this->createTestOrder($userId, 50.00, 'EUR');
        $contractId = $this->createContractId('recent');

        $contract = $this->createContract($contractId, $userId);
        $contract->addCondition(ContractCondition::paymentAuthorized());
        $contract->transitionToNotFinished($orderId);
        $contract->transitionToPending();
        $this->contractRepository->save($contract);

        // Act: Clean up contracts older than 30 minutes
        $this->cleanupService->cleanupStaleContracts(30);

        // Assert: Our recent order still exists (pre-existing stale data may have been cleaned)
        $orderRow = $this->connection->fetchAssociative(
            'SELECT OXID FROM oxorder WHERE OXID = :id',
            ['id' => $orderId]
        );
        $this->assertNotFalse($orderRow, 'Recent NOT_FINISHED order should still exist');

        // Assert: Contract still pending
        $contractRow = $this->connection->fetchAssociative(
            'SELECT OXSTATE FROM oe_payments_contract WHERE OXID = :id',
            ['id' => $contractId]
        );
        $this->assertNotFalse($contractRow, 'Contract should still exist');
        $this->assertSame('pending', $contractRow['OXSTATE'], 'Contract should still be pending');
    }

    // =========================================================================
    // Helper Methods
    // =========================================================================

    private function createContractId(string $suffix): string
    {
        return substr(self::TEST_PREFIX . $this->testRunId . '_' . $suffix, 0, 32);
    }

    private function createContract(string $contractId, string $userId): PaymentContract
    {
        $basketSnapshot = BasketSnapshot::fromArray([
            'items' => [],
            'discounts' => [],
            'totalGross' => 100.0,
            'totalNet' => 84.0,
            'totalVat' => 16.0,
            'currency' => 'EUR',
            'capturedAt' => date('Y-m-d H:i:s'),
        ]);

        return new PaymentContract(
            shopId: self::SHOP_ID,
            userId: $userId,
            basketSnapshot: $basketSnapshot,
            id: $contractId
        );
    }

    private function createTestUser(): string
    {
        $userId = substr(self::TEST_PREFIX . 'user_' . $this->testRunId, 0, 32);

        $this->connection->insert('oxuser', [
            'OXID' => $userId,
            'OXACTIVE' => 1,
            'OXRIGHTS' => 'user',
            'OXSHOPID' => self::SHOP_ID,
            'OXUSERNAME' => 'ox_test_' . $this->testRunId . '@example.com',
            'OXPASSWORD' => '',
            'OXFNAME' => 'Stale',
            'OXLNAME' => 'Cleanup',
            'OXSTREET' => 'Test Street',
            'OXSTREETNR' => '1',
            'OXCITY' => 'Test City',
            'OXCOUNTRYID' => 'a7c40f631fc920687.20179984',
            'OXZIP' => '12345',
            'OXSAL' => 'MR',
            'OXCREATE' => date('Y-m-d H:i:s'),
            'OXREGISTER' => date('Y-m-d H:i:s'),
        ]);

        return $userId;
    }

    private function createTestOrder(
        string $userId,
        float $total,
        string $currency,
        string $transId = ''
    ): string {
        $orderId = substr(self::TEST_PREFIX . 'ord_' . $this->testRunId . '_' . uniqid(), 0, 32);

        $this->connection->insert('oxorder', [
            'OXID' => $orderId,
            'OXSHOPID' => self::SHOP_ID,
            'OXUSERID' => $userId,
            'OXORDERDATE' => date('Y-m-d H:i:s'),
            'OXORDERNR' => random_int(100000, 999999),
            'OXTRANSID' => $transId,
            'OXTRANSSTATUS' => 'NOT_FINISHED',
            'OXBILLEMAIL' => 'ox_test@example.com',
            'OXBILLFNAME' => 'Stale',
            'OXBILLLNAME' => 'Cleanup',
            'OXBILLSTREET' => 'Test Street',
            'OXBILLSTREETNR' => '1',
            'OXBILLCITY' => 'Test City',
            'OXBILLCOUNTRYID' => 'a7c40f631fc920687.20179984',
            'OXBILLZIP' => '12345',
            'OXBILLSAL' => 'MR',
            'OXPAYMENTTYPE' => 'stripe_card',
            'OXTOTALNETSUM' => $total / 1.19,
            'OXTOTALBRUTSUM' => $total,
            'OXTOTALORDERSUM' => $total,
            'OXCURRENCY' => $currency,
            'OXCURRATE' => 1,
            'OXFOLDER' => 'ORDERFOLDER_NEW',
            'OXPAID' => '0000-00-00 00:00:00',
        ]);

        return $orderId;
    }

    private function cleanupTestData(): void
    {
        $this->connection->executeStatement(
            "DELETE FROM oe_payments_contract WHERE OXID LIKE ?",
            [self::TEST_PREFIX . '%']
        );
        $this->connection->executeStatement(
            "DELETE FROM oxorder WHERE OXID LIKE ?",
            [self::TEST_PREFIX . '%']
        );
        $this->connection->executeStatement(
            "DELETE FROM oxuser WHERE OXID LIKE ?",
            [self::TEST_PREFIX . '%']
        );
    }
}
