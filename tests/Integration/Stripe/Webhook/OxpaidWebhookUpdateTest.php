<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Integration\Stripe\Webhook;

use Doctrine\DBAL\Connection;
use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\EshopCommunity\Internal\Framework\Database\ConnectionProviderInterface;
use OxidEsales\EshopCommunity\Tests\Integration\IntegrationTestCase;
use OxidEsales\PaymentComponent\EventSystem\EventDispatcher;
use OxidEsales\PaymentComponent\EventSystem\EventDispatcherInterface;
use OxidEsales\PaymentComponent\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentComponent\Repository\DoctrineContractRepository;
use OxidEsales\PaymentComponent\Repository\DoctrineWebhookLogRepository;
use OxidEsales\PaymentComponent\Repository\WebhookLogRepositoryInterface;
use OxidEsales\PaymentComponent\Service\ContractFulfillmentService;
use OxidEsales\PaymentComponent\Service\ContractFulfillmentServiceInterface;
use OxidEsales\Payments\Stripe\WebhookHandler\WebhookContractFulfillmentHandler;
use OxidEsales\Payments\Stripe\Service\WebhookProcessingService;
use Psr\Log\NullLogger;

/**
 * Integration Tests for OXPAID Update via Webhooks
 *
 * Sprint 7 Refactored: Tests now use the contract state machine instead of
 * direct SQL order creation. This ensures tests mirror production code paths.
 *
 * Business Rules:
 * - Authorized only: OXPAID = 0000-00-00 00:00:00 (payment pending capture)
 * - Charged/Captured: OXPAID MUST have real timestamp
 * - Refunded: OXPAID MUST have timestamp of refund event
 *
 * NOTE: @runTestsInSeparateProcesses is required because the Symfony DI container
 * caches service instances, and the WebhookContractFulfillmentHandler uses the
 * ContractRepository which needs a fresh database connection per test.
 *
 * @covers \OxidEsales\Payments\Stripe\Service\WebhookProcessingService
 * @covers \OxidEsales\Payments\Stripe\WebhookHandler\WebhookContractFulfillmentHandler
 * @group integration
 * @group webhook
 * @group oxpaid
 * @group sprint-4
 * @group contract-aware
 */
final class OxpaidWebhookUpdateTest extends IntegrationTestCase
{
    private const TEST_PREFIX = 'oxpaid_test_';
    private const SHOP_ID = 1;

    private Connection $connection;
    private WebhookProcessingService $webhookService;
    private string $testRunId;

    public function setUp(): void
    {
        parent::setUp();

        $this->testRunId = date('His') . '_' . substr(uniqid(), -4);

        $container = ContainerFactory::getInstance()->getContainer();
        /** @var ConnectionProviderInterface $connectionProvider */
        $connectionProvider = $container->get(ConnectionProviderInterface::class);
        $this->connection = $connectionProvider->get();

        // Manually instantiate WebhookProcessingService with its dependencies
        // to avoid DI container caching issues in CI
        $this->webhookService = $this->createWebhookProcessingService();
    }

    /**
     * Create WebhookProcessingService manually with dependencies.
     * Uses direct instantiation to work in CI without module activation.
     *
     * Sprint 18: Updated to use ContractFulfillmentService
     */
    private function createWebhookProcessingService(): WebhookProcessingService
    {
        // Direct instantiation for repositories (CI compatibility)
        $contractRepository = new DoctrineContractRepository($this->connection);
        $webhookLogRepository = new DoctrineWebhookLogRepository($this->connection);

        // Direct instantiation for EventDispatcher (CI compatibility - module not activated)
        $eventDispatcher = new EventDispatcher(null);

        // Sprint 18: Create ContractFulfillmentService (requires logger)
        $contractFulfillmentService = new ContractFulfillmentService(
            $contractRepository,
            $eventDispatcher,
            new NullLogger()
        );

        // Create the fulfillment handler with ContractFulfillmentService (Sprint 18)
        $fulfillmentHandler = new WebhookContractFulfillmentHandler(
            $contractRepository,
            $contractFulfillmentService
        );

        // Create the service with ContractFulfillmentService (Sprint 18)
        return new WebhookProcessingService(
            $fulfillmentHandler,
            $eventDispatcher,
            $webhookLogRepository,
            $contractRepository,
            null, // orderPaymentStateService - not needed for these tests
            $contractFulfillmentService
        );
    }

    public function tearDown(): void
    {
        $this->cleanupTestData();
        parent::tearDown();
    }

    // =========================================================================
    // Contract-Aware Tests: payment_intent.succeeded
    // =========================================================================

    /**
     * @test
     *
     * When payment_intent.succeeded webhook arrives for a contract-based order,
     * the contract should transition to FULFILLED and OXPAID should be updated.
     */
    public function paymentIntentSucceededUpdatesOxpaidViaContract(): void
    {
        // Arrange: Create contract and order (mirrors production flow)
        $paymentIntentId = 'pi_succeeded_' . $this->testRunId;
        [$contractId, $orderId] = $this->createContractAndOrder($paymentIntentId);

        // Verify initial state
        $initialOrder = $this->getOrderData($orderId);
        $this->assertEquals(
            '0000-00-00 00:00:00',
            $initialOrder['OXPAID'],
            'OXPAID should be zero before webhook'
        );

        $contractBefore = $this->getContractData($contractId);
        $this->assertEquals('committed', $contractBefore['OXSTATE']);

        // Act: Process payment_intent.succeeded webhook
        $event = $this->createStripeEvent('payment_intent.succeeded', [
            'id' => $paymentIntentId,
            'object' => 'payment_intent',
            'status' => 'succeeded',
            'amount' => 10000,
            'currency' => 'eur',
        ]);

        $this->webhookService->processEvent($event);

        // Assert: Contract should be FULFILLED
        $contractAfter = $this->getContractData($contractId);
        $this->assertEquals(
            'fulfilled',
            $contractAfter['OXSTATE'],
            'Contract should transition to FULFILLED after webhook'
        );

        // Assert: OXPAID should now have a timestamp
        $updatedOrder = $this->getOrderData($orderId);
        $this->assertNotEquals(
            '0000-00-00 00:00:00',
            $updatedOrder['OXPAID'],
            'OXPAID should be updated after payment_intent.succeeded webhook'
        );

        // Verify timestamp is recent
        $paidDate = new \DateTimeImmutable($updatedOrder['OXPAID']);
        $now = new \DateTimeImmutable();
        $diff = abs($now->getTimestamp() - $paidDate->getTimestamp());
        $this->assertLessThan(
            7200,
            $diff,
            'OXPAID timestamp should be recent (within 2 hours)'
        );
    }

    /**
     * @test
     *
     * Idempotency: Processing same webhook twice should not cause errors
     * and contract should stay in FULFILLED state.
     */
    public function paymentIntentSucceededIsIdempotent(): void
    {
        // Arrange
        $paymentIntentId = 'pi_idempotent_' . $this->testRunId;
        [$contractId, $orderId] = $this->createContractAndOrder($paymentIntentId);

        $event = $this->createStripeEvent('payment_intent.succeeded', [
            'id' => $paymentIntentId,
            'object' => 'payment_intent',
            'status' => 'succeeded',
            'amount' => 10000,
            'currency' => 'eur',
        ]);

        // Act: Process twice
        $this->webhookService->processEvent($event);
        $firstOrder = $this->getOrderData($orderId);
        $firstPaid = $firstOrder['OXPAID'];

        $this->webhookService->processEvent($event);
        $secondOrder = $this->getOrderData($orderId);

        // Assert: Same result, no errors
        $this->assertEquals($firstPaid, $secondOrder['OXPAID']);

        $contractAfter = $this->getContractData($contractId);
        $this->assertEquals('fulfilled', $contractAfter['OXSTATE']);
    }

    // =========================================================================
    // Contract-Aware Tests: charge.captured
    // =========================================================================

    /**
     * @test
     *
     * charge.captured webhook should update OXPAID via contract.
     */
    public function chargeCapturedUpdatesOxpaidViaContract(): void
    {
        // Arrange
        $paymentIntentId = 'pi_captured_' . $this->testRunId;
        $chargeId = 'ch_captured_' . $this->testRunId;
        [$contractId, $orderId] = $this->createContractAndOrder($paymentIntentId);

        // Verify initial state
        $initialOrder = $this->getOrderData($orderId);
        $this->assertEquals('0000-00-00 00:00:00', $initialOrder['OXPAID']);

        // Act: Process charge.captured webhook
        $event = $this->createStripeEvent('charge.captured', [
            'id' => $chargeId,
            'object' => 'charge',
            'payment_intent' => $paymentIntentId,
            'amount' => 10000,
            'amount_captured' => 10000,
            'captured' => true,
            'currency' => 'eur',
        ]);

        $this->webhookService->processEvent($event);

        // Assert: Contract FULFILLED and OXPAID updated
        $contractAfter = $this->getContractData($contractId);
        $this->assertEquals('fulfilled', $contractAfter['OXSTATE']);

        $updatedOrder = $this->getOrderData($orderId);
        $this->assertNotEquals(
            '0000-00-00 00:00:00',
            $updatedOrder['OXPAID'],
            'OXPAID should be updated after charge.captured webhook'
        );
    }

    // =========================================================================
    // Contract-Aware Tests: checkout.session.completed
    // =========================================================================

    /**
     * @test
     *
     * checkout.session.completed webhook should update OXPAID via contract.
     */
    public function checkoutSessionCompletedUpdatesOxpaidViaContract(): void
    {
        // Arrange
        $paymentIntentId = 'pi_checkout_' . $this->testRunId;
        $sessionId = 'cs_test_' . $this->testRunId;
        [$contractId, $orderId] = $this->createContractAndOrder($paymentIntentId);

        // Verify initial state
        $initialOrder = $this->getOrderData($orderId);
        $this->assertEquals('0000-00-00 00:00:00', $initialOrder['OXPAID']);

        // Act: Process checkout.session.completed webhook
        $event = $this->createStripeEvent('checkout.session.completed', [
            'id' => $sessionId,
            'object' => 'checkout.session',
            'payment_intent' => $paymentIntentId,
            'payment_status' => 'paid',
            'status' => 'complete',
            'amount_total' => 10000,
            'currency' => 'eur',
        ]);

        $this->webhookService->processEvent($event);

        // Assert: Contract FULFILLED and OXPAID updated
        $contractAfter = $this->getContractData($contractId);
        $this->assertEquals('fulfilled', $contractAfter['OXSTATE']);

        $updatedOrder = $this->getOrderData($orderId);
        $this->assertNotEquals(
            '0000-00-00 00:00:00',
            $updatedOrder['OXPAID'],
            'OXPAID should be updated after checkout.session.completed webhook'
        );
    }

    // =========================================================================
    // Negative Tests: Authorization should NOT update OXPAID
    // =========================================================================

    /**
     * @test
     *
     * Authorization only (requires_capture) should NOT update OXPAID.
     * Contract should stay in COMMITTED state.
     */
    public function paymentIntentRequiresCaptureShouldNotUpdateOxpaid(): void
    {
        // Arrange
        $paymentIntentId = 'pi_auth_only_' . $this->testRunId;
        [$contractId, $orderId] = $this->createContractAndOrder($paymentIntentId);

        // Verify initial state
        $initialOrder = $this->getOrderData($orderId);
        $this->assertEquals('0000-00-00 00:00:00', $initialOrder['OXPAID']);

        // Act: Process authorization event (not a capture)
        $event = $this->createStripeEvent('payment_intent.amount_capturable_updated', [
            'id' => $paymentIntentId,
            'object' => 'payment_intent',
            'status' => 'requires_capture',
            'amount' => 10000,
            'amount_capturable' => 10000,
            'currency' => 'eur',
        ]);

        $this->webhookService->processEvent($event);

        // Assert: OXPAID should remain zero (not captured yet)
        $updatedOrder = $this->getOrderData($orderId);
        $this->assertEquals(
            '0000-00-00 00:00:00',
            $updatedOrder['OXPAID'],
            'OXPAID should remain zero for authorization-only'
        );

        // Contract should stay COMMITTED (not fulfilled)
        $contractAfter = $this->getContractData($contractId);
        $this->assertEquals(
            'committed',
            $contractAfter['OXSTATE'],
            'Contract should stay COMMITTED for authorization-only'
        );
    }

    // =========================================================================
    // Legacy Fallback Tests (for orders without contracts)
    // =========================================================================

    /**
     * @test
     *
     * Legacy orders without contracts should still work via OXTRANSID lookup.
     */
    public function legacyOrderWithoutContractStillWorks(): void
    {
        // Arrange: Create order directly (no contract) - legacy path
        $paymentIntentId = 'pi_legacy_' . $this->testRunId;
        $orderId = $this->createLegacyOrderWithTransId($paymentIntentId);

        // Verify initial state
        $initialOrder = $this->getOrderData($orderId);
        $this->assertEquals('0000-00-00 00:00:00', $initialOrder['OXPAID']);

        // Act: Process webhook
        $event = $this->createStripeEvent('payment_intent.succeeded', [
            'id' => $paymentIntentId,
            'object' => 'payment_intent',
            'status' => 'succeeded',
            'amount' => 10000,
            'currency' => 'eur',
        ]);

        $this->webhookService->processEvent($event);

        // Assert: OXPAID updated via legacy path
        $updatedOrder = $this->getOrderData($orderId);
        $this->assertNotEquals(
            '0000-00-00 00:00:00',
            $updatedOrder['OXPAID'],
            'OXPAID should be updated for legacy orders via OXTRANSID lookup'
        );
    }

    // =========================================================================
    // Helper Methods: Contract-Aware
    // =========================================================================

    /**
     * Create a contract and linked order (mirrors production flow).
     *
     * @return array{0: string, 1: string} [contractId, orderId]
     */
    private function createContractAndOrder(string $paymentIntentId): array
    {
        $userId = $this->createTestUser();
        $contractId = $this->createContract($userId, $paymentIntentId);
        $orderId = $this->createOrderLinkedToContract($userId, $contractId, $paymentIntentId);
        $this->transitionContractToCommitted($contractId, $orderId);

        return [$contractId, $orderId];
    }

    /**
     * Create contract with PaymentIntent ID as providerOrderId.
     */
    private function createContract(string $userId, string $paymentIntentId): string
    {
        $contractId = 'contract_' . $this->testRunId . '_' . substr(md5($paymentIntentId), 0, 8);

        // BasketSnapshot requires totalGross, totalNet, totalVat, currency
        $basketData = json_encode([
            'totalGross' => 100.00,
            'totalNet' => 84.03,
            'totalVat' => 15.97,
            'currency' => 'EUR',
            'items' => [],
        ]);

        $this->connection->insert('oe_payments_contract', [
            'OXID' => $contractId,
            'OXSHOPID' => self::SHOP_ID,
            'OXUSERID' => $userId,
            'OXPROVIDER' => 'stripe',
            'OXPROVIDERORDERID' => $paymentIntentId, // Sprint 7: Use pi_... directly
            'OXSTATE' => 'pending',
            'OXBASKETDATA' => $basketData,
            'OXCONDITIONS' => '[]',
            'OXCREATED' => date('Y-m-d H:i:s'),
            'OXUPDATED' => date('Y-m-d H:i:s'),
        ]);

        return $contractId;
    }

    /**
     * Create order linked to contract.
     */
    private function createOrderLinkedToContract(
        string $userId,
        string $contractId,
        string $paymentIntentId
    ): string {
        $orderId = substr(self::TEST_PREFIX . 'ord_' . $this->testRunId, 0, 32);

        $this->connection->insert('oxorder', [
            'OXID' => $orderId,
            'OXSHOPID' => self::SHOP_ID,
            'OXUSERID' => $userId,
            'OXORDERDATE' => date('Y-m-d H:i:s'),
            'OXORDERNR' => random_int(100000, 999999),
            'OXTRANSID' => $paymentIntentId,
            'OXTRANSSTATUS' => 'NOT_FINISHED',
            'OXBILLEMAIL' => self::TEST_PREFIX . '@example.com',
            'OXBILLFNAME' => 'OXPAID',
            'OXBILLLNAME' => 'Test',
            'OXBILLSTREET' => 'Test Street',
            'OXBILLSTREETNR' => '1',
            'OXBILLCITY' => 'Test City',
            'OXBILLCOUNTRYID' => 'a7c40f631fc920687.20179984',
            'OXBILLZIP' => '12345',
            'OXBILLSAL' => 'MR',
            'OXPAYMENTTYPE' => 'oe_payments_stripe_wallet',
            'OXTOTALNETSUM' => 84.03,
            'OXTOTALBRUTSUM' => 100.00,
            'OXTOTALORDERSUM' => 100.00,
            'OXCURRENCY' => 'EUR',
            'OXCURRATE' => 1,
            'OXFOLDER' => 'ORDERFOLDER_NEW',
            'OXPAID' => '0000-00-00 00:00:00',
        ]);

        return $orderId;
    }

    /**
     * Transition contract to COMMITTED state with order link.
     */
    private function transitionContractToCommitted(string $contractId, string $orderId): void
    {
        $this->connection->update(
            'oe_payments_contract',
            [
                'OXORDERID' => $orderId,
                'OXSTATE' => 'committed',
                'OXUPDATED' => date('Y-m-d H:i:s'),
            ],
            ['OXID' => $contractId]
        );
    }

    // =========================================================================
    // Helper Methods: Legacy (for backward compatibility tests)
    // =========================================================================

    /**
     * Create order directly with OXTRANSID (legacy path, no contract).
     */
    private function createLegacyOrderWithTransId(string $paymentIntentId): string
    {
        $userId = $this->createTestUser();
        $orderId = substr(self::TEST_PREFIX . 'legacy_' . $this->testRunId, 0, 32);

        $this->connection->insert('oxorder', [
            'OXID' => $orderId,
            'OXSHOPID' => self::SHOP_ID,
            'OXUSERID' => $userId,
            'OXORDERDATE' => date('Y-m-d H:i:s'),
            'OXORDERNR' => random_int(100000, 999999),
            'OXTRANSID' => $paymentIntentId,
            'OXTRANSSTATUS' => 'NOT_FINISHED',
            'OXBILLEMAIL' => self::TEST_PREFIX . '@example.com',
            'OXBILLFNAME' => 'Legacy',
            'OXBILLLNAME' => 'Test',
            'OXBILLSTREET' => 'Test Street',
            'OXBILLSTREETNR' => '1',
            'OXBILLCITY' => 'Test City',
            'OXBILLCOUNTRYID' => 'a7c40f631fc920687.20179984',
            'OXBILLZIP' => '12345',
            'OXBILLSAL' => 'MR',
            'OXPAYMENTTYPE' => 'oe_payments_stripe_wallet',
            'OXTOTALNETSUM' => 84.03,
            'OXTOTALBRUTSUM' => 100.00,
            'OXTOTALORDERSUM' => 100.00,
            'OXCURRENCY' => 'EUR',
            'OXCURRATE' => 1,
            'OXFOLDER' => 'ORDERFOLDER_NEW',
            'OXPAID' => '0000-00-00 00:00:00',
        ]);

        return $orderId;
    }

    // =========================================================================
    // Common Helper Methods
    // =========================================================================

    private function createTestUser(): string
    {
        $userId = substr(self::TEST_PREFIX . 'user_' . $this->testRunId, 0, 32);

        $existing = $this->connection->fetchOne(
            'SELECT OXID FROM oxuser WHERE OXID = ?',
            [$userId]
        );

        if ($existing) {
            return $userId;
        }

        $this->connection->insert('oxuser', [
            'OXID' => $userId,
            'OXACTIVE' => 1,
            'OXRIGHTS' => 'user',
            'OXSHOPID' => self::SHOP_ID,
            'OXUSERNAME' => self::TEST_PREFIX . $this->testRunId . '@example.com',
            'OXPASSWORD' => '',
            'OXFNAME' => 'OXPAID',
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

    private function createStripeEvent(string $type, array $objectData): \Stripe\Event
    {
        $eventData = [
            'id' => 'evt_test_' . $this->testRunId . '_' . substr(md5($type), 0, 8),
            'object' => 'event',
            'type' => $type,
            'data' => [
                'object' => $objectData,
            ],
            'created' => time(),
            'livemode' => false,
        ];

        return \Stripe\Event::constructFrom($eventData);
    }

    private function getOrderData(string $orderId): array
    {
        $result = $this->connection->fetchAssociative(
            'SELECT * FROM oxorder WHERE OXID = :id',
            ['id' => $orderId]
        );

        if (!$result) {
            throw new \RuntimeException("Order not found: {$orderId}");
        }

        return $result;
    }

    private function getContractData(string $contractId): array
    {
        $result = $this->connection->fetchAssociative(
            'SELECT * FROM oe_payments_contract WHERE OXID = :id',
            ['id' => $contractId]
        );

        if (!$result) {
            throw new \RuntimeException("Contract not found: {$contractId}");
        }

        return $result;
    }

    private function cleanupTestData(): void
    {
        $this->connection->executeStatement(
            "DELETE FROM oe_payments_webhooklogs WHERE OXEVENTID LIKE ?",
            ['evt_test_%']
        );
        $this->connection->executeStatement(
            "DELETE FROM oe_payments_contract WHERE OXID LIKE ?",
            ['contract_%']
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
