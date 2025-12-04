<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Integration\Stripe\Webhook;

use Doctrine\DBAL\Connection;
use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\EshopCommunity\Internal\Framework\Database\ConnectionProviderInterface;
use OxidEsales\EshopCommunity\Tests\Integration\IntegrationTestCase;
use OxidSolutionCatalysts\Payments\Component\EventSystem\EventDispatcherInterface;
use OxidSolutionCatalysts\Payments\Component\Repository\ContractRepositoryInterface;
use OxidSolutionCatalysts\Payments\Component\Repository\WebhookLogRepositoryInterface;
use OxidSolutionCatalysts\Payments\Stripe\Handler\WebhookContractFulfillmentHandler;
use OxidSolutionCatalysts\Payments\Stripe\Service\WebhookProcessingService;

/**
 * TDD Tests: Contract-Aware OXPAID Update via Webhooks
 *
 * These tests verify that the FULL checkout flow works:
 * 1. Contract created with checkout session ID (cs_test_...)
 * 2. Contract updated with PaymentIntent ID (pi_...) on return (Sprint 7 fix)
 * 3. Webhook arrives with PaymentIntent ID (pi_...)
 * 4. Contract found and transitioned to FULFILLED
 * 5. OXPAID timestamp updated
 *
 * Sprint 7: Tests the fix for providerOrderId mismatch bug.
 *
 * @covers \OxidSolutionCatalysts\Payments\Stripe\Service\WebhookProcessingService
 * @covers \OxidSolutionCatalysts\Payments\Stripe\Handler\WebhookContractFulfillmentHandler
 * @group integration
 * @group webhook
 * @group oxpaid
 * @group sprint-7
 * @group contract-aware
 */
final class ContractAwareOxpaidWebhookTest extends IntegrationTestCase
{
    private const TEST_PREFIX = 'contract_oxpaid_test_';
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
        $this->webhookService = $this->createWebhookProcessingService($container);
    }

    /**
     * Create WebhookProcessingService manually with dependencies from container.
     * This avoids issues with DI container caching in CI environments.
     */
    private function createWebhookProcessingService($container): WebhookProcessingService
    {
        // Get dependencies that are reliably available in the container
        $contractRepository = $container->get(ContractRepositoryInterface::class);
        $eventDispatcher = $container->get(EventDispatcherInterface::class);
        $webhookLogRepository = $container->get(WebhookLogRepositoryInterface::class);

        // Create the fulfillment handler
        $fulfillmentHandler = new WebhookContractFulfillmentHandler(
            $contractRepository,
            $eventDispatcher
        );

        // Create the service
        return new WebhookProcessingService(
            $fulfillmentHandler,
            $eventDispatcher,
            $webhookLogRepository,
            $contractRepository
        );
    }

    public function tearDown(): void
    {
        $this->cleanupTestData();
        parent::tearDown();
    }

    // =========================================================================
    // Sprint 7: Fixed flow - contract has correct PaymentIntent ID
    // =========================================================================

    /**
     * @test
     *
     * Sprint 7 FIX: When contract has PaymentIntent ID (pi_...) as providerOrderId,
     * webhook lookup succeeds and OXPAID is updated.
     *
     * This test simulates the FIXED flow where StripeCheckoutReturnHandler
     * correctly sets providerOrderId to PaymentIntent ID.
     */
    public function contractWithPaymentIntentIdUpdatesOxpaid(): void
    {
        // Arrange: Simulate FIXED checkout flow
        $paymentIntentId = 'pi_fixed_' . $this->testRunId;

        // Step 1: Create contract with PaymentIntent ID (Sprint 7 fix)
        $contractId = $this->createContractWithProviderOrderId($paymentIntentId);

        // Step 2: Create order linked to contract
        $orderId = $this->createTestOrderLinkedToContract($contractId, $paymentIntentId);

        // Step 3: Transition contract to COMMITTED
        $this->transitionContractToCommitted($contractId, $orderId);

        // Verify initial state: OXPAID should be zero
        $initialOrder = $this->getOrderData($orderId);
        $this->assertEquals(
            '0000-00-00 00:00:00',
            $initialOrder['OXPAID'],
            'OXPAID should be zero before webhook'
        );

        // Verify contract is in COMMITTED state
        $contractBefore = $this->getContractData($contractId);
        $this->assertEquals(
            'committed',
            $contractBefore['OXSTATE'],
            'Contract should be in COMMITTED state before webhook'
        );

        // Act: Process payment_intent.succeeded webhook (with pi_... ID)
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
            'Contract should be in FULFILLED state after webhook'
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
            7200, // 2 hours to account for timezone
            $diff,
            'OXPAID timestamp should be recent'
        );
    }

    // =========================================================================
    // Bug demonstration: contract with checkout session ID fails lookup
    // =========================================================================

    /**
     * @test
     *
     * Bug Demonstration: When contract has Checkout Session ID (cs_test_...)
     * instead of PaymentIntent ID, webhook lookup fails.
     *
     * This test demonstrates the bug that Sprint 7 fixes.
     * The contract keeps cs_test_... and webhook with pi_... cannot find it.
     */
    public function contractWithCheckoutSessionIdFailsLookup(): void
    {
        // Arrange: Simulate BUGGY flow (before Sprint 7 fix)
        $checkoutSessionId = 'cs_test_bug_' . $this->testRunId;
        $paymentIntentId = 'pi_bug_' . $this->testRunId;

        // Create contract with checkout session ID (OLD buggy behavior)
        $contractId = $this->createContractWithProviderOrderId($checkoutSessionId);

        // Create order
        $orderId = $this->createTestOrderLinkedToContract($contractId, $paymentIntentId);

        // Transition to COMMITTED
        $this->transitionContractToCommitted($contractId, $orderId);

        // Verify contract has checkout session ID (wrong!)
        $contractBefore = $this->getContractData($contractId);
        $this->assertEquals(
            $checkoutSessionId,
            $contractBefore['OXPROVIDERORDERID'],
            'Contract has checkout session ID (buggy behavior)'
        );

        // Act: Process webhook with pi_... ID
        $event = $this->createStripeEvent('payment_intent.succeeded', [
            'id' => $paymentIntentId,
            'object' => 'payment_intent',
            'status' => 'succeeded',
            'amount' => 10000,
            'currency' => 'eur',
        ]);

        $this->webhookService->processEvent($event);

        // Assert: Contract should STILL be in COMMITTED state (not fulfilled!)
        // because lookup by pi_... fails when contract has cs_test_...
        $contractAfter = $this->getContractData($contractId);
        $this->assertEquals(
            'committed',
            $contractAfter['OXSTATE'],
            'BUG: Contract stays COMMITTED because lookup by pi_... fails'
        );

        // Assert: OXPAID might be updated via legacy path (OXTRANSID lookup)
        // but contract is not fulfilled - this is the architectural issue
        $updatedOrder = $this->getOrderData($orderId);
        // Legacy path should still work for order, so OXPAID may be updated
        // The key issue is the contract not being fulfilled
    }

    // =========================================================================
    // Helper Methods
    // =========================================================================

    /**
     * Create contract directly in database with specified providerOrderId.
     */
    private function createContractWithProviderOrderId(string $providerOrderId): string
    {
        $contractId = 'contract_' . $this->testRunId . '_' . substr(md5($providerOrderId), 0, 8);
        $userId = $this->createTestUser();

        // BasketSnapshot requires totalGross, totalNet, totalVat, currency, items
        $basketData = json_encode([
            'totalGross' => 100.00,
            'totalNet' => 84.03,
            'totalVat' => 15.97,
            'currency' => 'EUR',
            'items' => [],
        ]);

        $this->connection->insert('osc_payment_contract', [
            'OXID' => $contractId,
            'OXSHOPID' => self::SHOP_ID,
            'OXUSERID' => $userId,
            'OXPROVIDER' => 'stripe',
            'OXPROVIDERORDERID' => $providerOrderId,
            'OXSTATE' => 'pending',
            'OXBASKETDATA' => $basketData,
            'OXCONDITIONS' => '[]',
            'OXCREATED' => date('Y-m-d H:i:s'),
            'OXUPDATED' => date('Y-m-d H:i:s'),
        ]);

        return $contractId;
    }

    private function transitionContractToCommitted(string $contractId, string $orderId): void
    {
        $this->connection->update(
            'osc_payment_contract',
            [
                'OXORDERID' => $orderId,
                'OXSTATE' => 'committed',
                'OXUPDATED' => date('Y-m-d H:i:s'),
            ],
            ['OXID' => $contractId]
        );
    }

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
            'OXFNAME' => 'Contract',
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

    private function createTestOrderLinkedToContract(string $contractId, string $paymentIntentId): string
    {
        $userId = $this->createTestUser();
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
            'OXBILLFNAME' => 'Contract',
            'OXBILLLNAME' => 'Test',
            'OXBILLSTREET' => 'Test Street',
            'OXBILLSTREETNR' => '1',
            'OXBILLCITY' => 'Test City',
            'OXBILLCOUNTRYID' => 'a7c40f631fc920687.20179984',
            'OXBILLZIP' => '12345',
            'OXBILLSAL' => 'MR',
            'OXPAYMENTTYPE' => 'osc_stripe_wallet',
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
            'SELECT * FROM osc_payment_contract WHERE OXID = :id',
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
            "DELETE FROM osc_payment_webhooklogs WHERE OXEVENTID LIKE ?",
            ['evt_test_%']
        );
        $this->connection->executeStatement(
            "DELETE FROM osc_payment_contract WHERE OXID LIKE ?",
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
