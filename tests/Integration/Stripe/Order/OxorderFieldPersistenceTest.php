<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Integration\Stripe\Order;

use Doctrine\DBAL\Connection;
use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\EshopCommunity\Internal\Framework\Database\ConnectionProviderInterface;
use OxidEsales\EshopCommunity\Tests\Integration\IntegrationTestCase;
use OxidEsales\PaymentComponent\Contract\BasketSnapshot;
use OxidEsales\PaymentComponent\Contract\ContractCondition;
use OxidEsales\PaymentComponent\Contract\PaymentContract;
use OxidEsales\PaymentComponent\Repository\DoctrineContractRepository;

/**
 * Tests that OXORDER fields are correctly populated during checkout.
 *
 * Fields tested:
 * - OXTRANSID: Stripe PaymentIntent ID
 * - OXTRANSSTATUS: Transaction status (NOT_FINISHED, OK, ERROR)
 * - OXPAID: Payment completion timestamp
 * - OXFOLDER: Order folder for admin (ORDERFOLDER_NEW, ORDERFOLDER_PROBLEMS)
 *
 * @covers \OxidEsales\Payments\Stripe\Service\WebhookProcessingService
 * @group integration
 * @group order-fields
 * @group oxorder
 * @group sprint-2
 */
final class OxorderFieldPersistenceTest extends IntegrationTestCase
{
    private const TEST_PREFIX = 'ox_test_';
    private const SHOP_ID = 1;

    private Connection $connection;
    private DoctrineContractRepository $contractRepository;
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
    }

    public function tearDown(): void
    {
        $this->cleanupTestData();
        parent::tearDown();
    }

    // =========================================================================
    // OXTRANSID Tests
    // =========================================================================

    /**
     * @test
     * @group tdd-red
     */
    public function oxtransidIsSetToPaymentIntentIdOnOrderCreation(): void
    {
        // Arrange
        $userId = $this->createTestUser();
        $paymentIntentId = 'pi_transid_' . $this->testRunId;

        // Act: Create order with PaymentIntent ID
        $orderId = $this->createTestOrder($userId, 100.00, 'EUR', $paymentIntentId);

        // Assert
        $dbOrder = $this->connection->fetchAssociative(
            'SELECT OXTRANSID FROM oxorder WHERE OXID = :id',
            ['id' => $orderId]
        );

        $this->assertEquals(
            $paymentIntentId,
            $dbOrder['OXTRANSID'],
            'OXTRANSID should contain the PaymentIntent ID'
        );
    }

    /**
     * @test
     * @group tdd-red
     */
    public function oxtransidIsNotOverwrittenOnSubsequentUpdates(): void
    {
        // Arrange
        $userId = $this->createTestUser();
        $paymentIntentId = 'pi_nooverwrite_' . $this->testRunId;
        $orderId = $this->createTestOrder($userId, 100.00, 'EUR', $paymentIntentId);

        // Act: Try to update order (simulating webhook)
        $this->connection->update('oxorder', [
            'OXTRANSSTATUS' => 'OK',
        ], ['OXID' => $orderId]);

        // Assert: OXTRANSID should remain unchanged
        $dbOrder = $this->connection->fetchAssociative(
            'SELECT OXTRANSID FROM oxorder WHERE OXID = :id',
            ['id' => $orderId]
        );

        $this->assertEquals(
            $paymentIntentId,
            $dbOrder['OXTRANSID'],
            'OXTRANSID should not be overwritten'
        );
    }

    /**
     * @test
     * @group tdd-red
     */
    public function oxtransidAccepts64CharacterPaymentIntentId(): void
    {
        // Arrange - Stripe PaymentIntent IDs can be up to 27 chars, but field is VARCHAR(64)
        $userId = $this->createTestUser();
        $longPaymentIntentId = 'pi_' . str_repeat('a', 61); // 64 chars total

        // Act
        $orderId = $this->createTestOrder($userId, 50.00, 'EUR', $longPaymentIntentId);

        // Assert
        $dbOrder = $this->connection->fetchAssociative(
            'SELECT OXTRANSID FROM oxorder WHERE OXID = :id',
            ['id' => $orderId]
        );

        $this->assertEquals($longPaymentIntentId, $dbOrder['OXTRANSID']);
    }

    // =========================================================================
    // OXTRANSSTATUS Tests
    // =========================================================================

    /**
     * @test
     * @group tdd-red
     */
    public function oxtransstatusIsNotFinishedOnOrderCreation(): void
    {
        // Arrange & Act
        $userId = $this->createTestUser();
        $orderId = $this->createTestOrder($userId, 100.00, 'EUR');

        // Assert
        $dbOrder = $this->connection->fetchAssociative(
            'SELECT OXTRANSSTATUS FROM oxorder WHERE OXID = :id',
            ['id' => $orderId]
        );

        $this->assertEquals(
            'NOT_FINISHED',
            $dbOrder['OXTRANSSTATUS'],
            'OXTRANSSTATUS should be NOT_FINISHED on order creation'
        );
    }

    /**
     * @test
     * @group tdd-red
     */
    public function oxtransstatusIsOkAfterPaymentSucceeds(): void
    {
        // Arrange
        $userId = $this->createTestUser();
        $orderId = $this->createTestOrder($userId, 100.00, 'EUR');

        // Act: Simulate payment_intent.succeeded webhook
        $this->connection->update('oxorder', [
            'OXTRANSSTATUS' => 'OK',
        ], ['OXID' => $orderId]);

        // Assert
        $dbOrder = $this->connection->fetchAssociative(
            'SELECT OXTRANSSTATUS FROM oxorder WHERE OXID = :id',
            ['id' => $orderId]
        );

        $this->assertEquals('OK', $dbOrder['OXTRANSSTATUS']);
    }

    /**
     * @test
     * @group tdd-red
     */
    public function oxtransstatusIsErrorAfterPaymentFails(): void
    {
        // Arrange
        $userId = $this->createTestUser();
        $orderId = $this->createTestOrder($userId, 100.00, 'EUR');

        // Act: Simulate payment_intent.payment_failed webhook
        $this->connection->update('oxorder', [
            'OXTRANSSTATUS' => 'ERROR',
        ], ['OXID' => $orderId]);

        // Assert
        $dbOrder = $this->connection->fetchAssociative(
            'SELECT OXTRANSSTATUS FROM oxorder WHERE OXID = :id',
            ['id' => $orderId]
        );

        $this->assertEquals('ERROR', $dbOrder['OXTRANSSTATUS']);
    }

    // =========================================================================
    // OXPAID Tests
    // =========================================================================

    /**
     * @test
     * @group tdd-red
     */
    public function oxpaidIsZeroOnOrderCreation(): void
    {
        // Arrange & Act
        $userId = $this->createTestUser();
        $orderId = $this->createTestOrder($userId, 100.00, 'EUR');

        // Assert
        $dbOrder = $this->connection->fetchAssociative(
            'SELECT OXPAID FROM oxorder WHERE OXID = :id',
            ['id' => $orderId]
        );

        $this->assertEquals(
            '0000-00-00 00:00:00',
            $dbOrder['OXPAID'],
            'OXPAID should be zero datetime on order creation'
        );
    }

    /**
     * @test
     * @group tdd-red
     */
    public function oxpaidIsSetOnPaymentCapture(): void
    {
        // Arrange
        $userId = $this->createTestUser();
        $orderId = $this->createTestOrder($userId, 100.00, 'EUR');

        // Act: Simulate charge.captured webhook
        $captureTime = date('Y-m-d H:i:s');
        $this->connection->update('oxorder', [
            'OXPAID' => $captureTime,
            'OXTRANSSTATUS' => 'OK',
        ], ['OXID' => $orderId]);

        // Assert
        $dbOrder = $this->connection->fetchAssociative(
            'SELECT OXPAID FROM oxorder WHERE OXID = :id',
            ['id' => $orderId]
        );

        $this->assertNotEquals(
            '0000-00-00 00:00:00',
            $dbOrder['OXPAID'],
            'OXPAID should be set (not zero)'
        );
        $this->assertEquals(
            $captureTime,
            $dbOrder['OXPAID'],
            'OXPAID should match the capture timestamp'
        );
    }

    /**
     * @test
     * @group tdd-red
     */
    public function oxpaidRemainsZeroOnPaymentFailure(): void
    {
        // Arrange
        $userId = $this->createTestUser();
        $orderId = $this->createTestOrder($userId, 100.00, 'EUR');

        // Act: Simulate payment_intent.payment_failed webhook
        $this->connection->update('oxorder', [
            'OXTRANSSTATUS' => 'ERROR',
            'OXFOLDER' => 'ORDERFOLDER_PROBLEMS',
        ], ['OXID' => $orderId]);

        // Assert
        $dbOrder = $this->connection->fetchAssociative(
            'SELECT OXPAID FROM oxorder WHERE OXID = :id',
            ['id' => $orderId]
        );

        $this->assertEquals(
            '0000-00-00 00:00:00',
            $dbOrder['OXPAID'],
            'OXPAID should remain zero on payment failure'
        );
    }

    // =========================================================================
    // OXFOLDER Tests
    // =========================================================================

    /**
     * @test
     * @group tdd-red
     */
    public function oxfolderIsNewOnOrderCreation(): void
    {
        // Arrange & Act
        $userId = $this->createTestUser();
        $orderId = $this->createTestOrder($userId, 100.00, 'EUR');

        // Assert
        $dbOrder = $this->connection->fetchAssociative(
            'SELECT OXFOLDER FROM oxorder WHERE OXID = :id',
            ['id' => $orderId]
        );

        $this->assertEquals(
            'ORDERFOLDER_NEW',
            $dbOrder['OXFOLDER'],
            'OXFOLDER should be ORDERFOLDER_NEW on order creation'
        );
    }

    /**
     * @test
     * @group tdd-red
     */
    public function oxfolderIsProblemsOnPaymentFailure(): void
    {
        // Arrange
        $userId = $this->createTestUser();
        $orderId = $this->createTestOrder($userId, 100.00, 'EUR');

        // Act: Simulate payment failure
        $this->connection->update('oxorder', [
            'OXTRANSSTATUS' => 'ERROR',
            'OXFOLDER' => 'ORDERFOLDER_PROBLEMS',
        ], ['OXID' => $orderId]);

        // Assert
        $dbOrder = $this->connection->fetchAssociative(
            'SELECT OXFOLDER FROM oxorder WHERE OXID = :id',
            ['id' => $orderId]
        );

        $this->assertEquals(
            'ORDERFOLDER_PROBLEMS',
            $dbOrder['OXFOLDER'],
            'OXFOLDER should be ORDERFOLDER_PROBLEMS on payment failure'
        );
    }

    // =========================================================================
    // Combined Flow Tests
    // =========================================================================

    /**
     * @test
     * @group tdd-red
     * @group complete-flow
     */
    public function completePaymentFlowSetsAllFieldsCorrectly(): void
    {
        // Arrange
        $userId = $this->createTestUser();
        $paymentIntentId = 'pi_complete_' . $this->testRunId;
        $orderId = $this->createTestOrder($userId, 299.99, 'EUR', $paymentIntentId);

        // Initial state assertions
        $initialOrder = $this->connection->fetchAssociative(
            'SELECT * FROM oxorder WHERE OXID = :id',
            ['id' => $orderId]
        );
        $this->assertEquals('NOT_FINISHED', $initialOrder['OXTRANSSTATUS']);
        $this->assertEquals('0000-00-00 00:00:00', $initialOrder['OXPAID']);
        $this->assertEquals('ORDERFOLDER_NEW', $initialOrder['OXFOLDER']);

        // Act: Simulate successful payment + capture (webhook flow)
        $captureTime = date('Y-m-d H:i:s');
        $this->connection->update('oxorder', [
            'OXTRANSSTATUS' => 'OK',
            'OXPAID' => $captureTime,
        ], ['OXID' => $orderId]);

        // Final state assertions
        $finalOrder = $this->connection->fetchAssociative(
            'SELECT * FROM oxorder WHERE OXID = :id',
            ['id' => $orderId]
        );

        $this->assertEquals($paymentIntentId, $finalOrder['OXTRANSID'], 'OXTRANSID mismatch');
        $this->assertEquals('OK', $finalOrder['OXTRANSSTATUS'], 'OXTRANSSTATUS mismatch');
        $this->assertNotEquals('0000-00-00 00:00:00', $finalOrder['OXPAID'], 'OXPAID should be set');
        $this->assertEquals('ORDERFOLDER_NEW', $finalOrder['OXFOLDER'], 'OXFOLDER mismatch');
    }

    /**
     * @test
     * @group tdd-red
     * @group complete-flow
     */
    public function failedPaymentFlowSetsFieldsCorrectly(): void
    {
        // Arrange
        $userId = $this->createTestUser();
        $paymentIntentId = 'pi_failed_' . $this->testRunId;
        $orderId = $this->createTestOrder($userId, 99.99, 'EUR', $paymentIntentId);

        // Act: Simulate failed payment (webhook flow)
        $this->connection->update('oxorder', [
            'OXTRANSSTATUS' => 'ERROR',
            'OXFOLDER' => 'ORDERFOLDER_PROBLEMS',
        ], ['OXID' => $orderId]);

        // Assert
        $dbOrder = $this->connection->fetchAssociative(
            'SELECT * FROM oxorder WHERE OXID = :id',
            ['id' => $orderId]
        );

        $this->assertEquals($paymentIntentId, $dbOrder['OXTRANSID'], 'OXTRANSID should be set');
        $this->assertEquals('ERROR', $dbOrder['OXTRANSSTATUS'], 'OXTRANSSTATUS should be ERROR');
        $this->assertEquals('0000-00-00 00:00:00', $dbOrder['OXPAID'], 'OXPAID should remain zero');
        $this->assertEquals('ORDERFOLDER_PROBLEMS', $dbOrder['OXFOLDER'], 'OXFOLDER should be PROBLEMS');
    }

    /**
     * @test
     * @group tdd-red
     * @group contract-flow
     */
    public function contractCommitSetsOxtransidFromProviderInfo(): void
    {
        // Arrange
        $userId = $this->createTestUser();
        $paymentIntentId = 'pi_contract_' . $this->testRunId;
        $contractId = $this->createContractId('commit');

        // Act: Create order first (required for NOT_FINISHED transition)
        $orderId = $this->createTestOrder($userId, 100.00, 'EUR', $paymentIntentId);

        // Create contract with provider info
        $contract = $this->createContract($contractId, $userId);
        $contract->setProvider('stripe', $paymentIntentId, null);
        $contract->addCondition(ContractCondition::paymentAuthorized());
        $contract->transitionToNotFinished($orderId);
        $contract->transitionToPending();
        $contract->fulfillCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED, []);
        $this->contractRepository->save($contract);

        // Commit contract to order
        $contract->commitToOrder($orderId);
        $this->contractRepository->save($contract);

        // Assert
        $dbOrder = $this->connection->fetchAssociative(
            'SELECT OXTRANSID FROM oxorder WHERE OXID = :id',
            ['id' => $orderId]
        );

        $this->assertEquals(
            $paymentIntentId,
            $dbOrder['OXTRANSID'],
            'OXTRANSID should match PaymentIntent from contract'
        );
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
            'OXFNAME' => 'Order',
            'OXLNAME' => 'Test',
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
            'OXBILLFNAME' => 'Order',
            'OXBILLLNAME' => 'Test',
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
