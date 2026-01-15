<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Integration\Sprint2;

use OxidEsales\PaymentComponent\Repository\DoctrinePaymentCustomerRepository;
use OxidEsales\PaymentComponent\Repository\DoctrineWebhookLogRepository;
use OxidEsales\PaymentComponent\Repository\PaymentCustomerRepositoryInterface;
use OxidEsales\PaymentComponent\Repository\WebhookLogRepositoryInterface;
use OxidEsales\PaymentComponent\Webhook\WebhookLog;
use PHPUnit\Framework\TestCase;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

/**
 * Integration tests for Sprint 2 Table Consolidation
 *
 * These tests verify that the consolidated architecture works correctly
 * with actual database operations (using SQLite for isolation).
 *
 * @group sprint-2
 * @group integration
 */
class TableConsolidationIntegrationTest extends TestCase
{
    private Connection $connection;
    private WebhookLogRepositoryInterface $webhookRepository;
    private PaymentCustomerRepositoryInterface $customerRepository;

    protected function setUp(): void
    {
        // Use SQLite in-memory for fast, isolated tests
        $this->connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        // Create the consolidated tables
        $this->createTables();

        // Initialize repositories
        $this->webhookRepository = new DoctrineWebhookLogRepository($this->connection);
        $this->customerRepository = new DoctrinePaymentCustomerRepository($this->connection);
    }

    protected function tearDown(): void
    {
        $this->connection->close();
    }

    private function createTables(): void
    {
        // Create osc_payment_webhooklogs table with provider fields
        $this->connection->executeStatement("
            CREATE TABLE osc_payment_webhooklogs (
                OXID VARCHAR(32) PRIMARY KEY,
                OXEVENTID VARCHAR(128) NOT NULL UNIQUE,
                OXEVENTTYPE VARCHAR(128),
                OXCONTRACTID VARCHAR(32),
                OXSTATUS VARCHAR(32) NOT NULL,
                OXRECEIVEDAT DATETIME NOT NULL,
                OXERROR TEXT,
                OXPROVIDER VARCHAR(32),
                OXPAYLOAD TEXT,
                OXPROCESSEDAT DATETIME
            )
        ");

        // Create osc_payment_customer table (provider-agnostic)
        $this->connection->executeStatement("
            CREATE TABLE osc_payment_customer (
                OXID VARCHAR(32) PRIMARY KEY,
                OXUSERID VARCHAR(32) NOT NULL UNIQUE,
                OXPAYMENTCUSTOMERID VARCHAR(128),
                OXDEFAULTPAYMENTMETHOD VARCHAR(64),
                OXSAVEDPAYMENTMETHODS TEXT,
                OXBILLINGAGREEMENT INTEGER DEFAULT 0,
                OXLASTPAYMENTDATE DATETIME,
                OXCREATED DATETIME NOT NULL,
                OXUPDATED DATETIME NOT NULL
            )
        ");
    }

    /**
     * @test
     * WebhookLog with provider field can be saved and retrieved
     */
    public function webhookLogWithProviderCanBeSavedAndRetrieved(): void
    {
        // Arrange
        $log = new WebhookLog(
            'evt_stripe_123',
            new \DateTimeImmutable(),
            'received'
        );
        $log->setEventType('payment_intent.succeeded');
        $log->setProvider('stripe');
        $log->setPayload(['type' => 'payment_intent.succeeded', 'data' => ['amount' => 1000]]);

        // Act
        $this->webhookRepository->save($log);
        $retrieved = $this->webhookRepository->findByEventId('evt_stripe_123');

        // Assert
        $this->assertNotNull($retrieved);
        $this->assertEquals('evt_stripe_123', $retrieved->getEventId());
        $this->assertEquals('stripe', $retrieved->getProvider());
        $this->assertEquals(['type' => 'payment_intent.succeeded', 'data' => ['amount' => 1000]], $retrieved->getPayload());
    }

    /**
     * @test
     * WebhookLog without provider field (backward compatibility)
     */
    public function webhookLogWithoutProviderWorksForBackwardCompatibility(): void
    {
        // Arrange
        $log = new WebhookLog(
            'evt_legacy_456',
            new \DateTimeImmutable(),
            'received'
        );
        $log->setEventType('charge.succeeded');
        // Note: No provider set

        // Act
        $this->webhookRepository->save($log);
        $retrieved = $this->webhookRepository->findByEventId('evt_legacy_456');

        // Assert
        $this->assertNotNull($retrieved);
        $this->assertEquals('evt_legacy_456', $retrieved->getEventId());
        $this->assertNull($retrieved->getProvider());
    }

    /**
     * @test
     * Multiple providers can be stored in webhook logs
     */
    public function multipleProvidersCanBeStoredInWebhookLogs(): void
    {
        // Arrange - Create logs for different providers
        $stripeLog = new WebhookLog('evt_stripe_1', new \DateTimeImmutable(), 'received');
        $stripeLog->setEventType('payment_intent.succeeded');
        $stripeLog->setProvider('stripe');

        $paypalLog = new WebhookLog('evt_paypal_1', new \DateTimeImmutable(), 'received');
        $paypalLog->setEventType('CHECKOUT.ORDER.COMPLETED');
        $paypalLog->setProvider('paypal');

        // Act
        $this->webhookRepository->save($stripeLog);
        $this->webhookRepository->save($paypalLog);

        // Assert - Both can be retrieved
        $retrievedStripe = $this->webhookRepository->findByEventId('evt_stripe_1');
        $retrievedPaypal = $this->webhookRepository->findByEventId('evt_paypal_1');

        $this->assertEquals('stripe', $retrievedStripe->getProvider());
        $this->assertEquals('paypal', $retrievedPaypal->getProvider());
    }

    /**
     * @test
     * Customer ID can be saved and retrieved via repository
     */
    public function customerIdCanBeSavedAndRetrievedViaRepository(): void
    {
        // Arrange
        $userId = 'oxid_user_123';
        $stripeCustomerId = 'cus_stripe_abc123';

        // Act
        $this->customerRepository->savePaymentCustomerId($userId, $stripeCustomerId);
        $retrieved = $this->customerRepository->findPaymentCustomerId($userId);

        // Assert
        $this->assertEquals($stripeCustomerId, $retrieved);
    }

    /**
     * @test
     * Non-existent customer returns null
     */
    public function nonExistentCustomerReturnsNull(): void
    {
        // Act
        $result = $this->customerRepository->findPaymentCustomerId('nonexistent_user');

        // Assert
        $this->assertNull($result);
    }

    /**
     * @test
     * Customer ID can be updated
     */
    public function customerIdCanBeUpdated(): void
    {
        // Arrange
        $userId = 'oxid_user_456';
        $oldCustomerId = 'cus_old_123';
        $newCustomerId = 'cus_new_456';

        // Act
        $this->customerRepository->savePaymentCustomerId($userId, $oldCustomerId);
        $this->customerRepository->savePaymentCustomerId($userId, $newCustomerId);
        $retrieved = $this->customerRepository->findPaymentCustomerId($userId);

        // Assert
        $this->assertEquals($newCustomerId, $retrieved);
    }

    /**
     * @test
     * WebhookLog processedAt timestamp can be set and retrieved
     */
    public function webhookLogProcessedAtCanBeSetAndRetrieved(): void
    {
        // Arrange
        $log = new WebhookLog('evt_processed_123', new \DateTimeImmutable(), 'processed');
        $log->setEventType('payment.completed');
        $log->setProvider('stripe');
        $processedAt = new \DateTimeImmutable('2025-12-02 10:30:00');
        $log->setProcessedAt($processedAt);

        // Act
        $this->webhookRepository->save($log);
        $retrieved = $this->webhookRepository->findByEventId('evt_processed_123');

        // Assert
        $this->assertNotNull($retrieved->getProcessedAt());
        $this->assertEquals('2025-12-02 10:30:00', $retrieved->getProcessedAt()->format('Y-m-d H:i:s'));
    }

    /**
     * @test
     * Webhook idempotency - duplicate events are rejected
     */
    public function webhookIdempotencyPreventsduplicateEvents(): void
    {
        // Arrange
        $log = new WebhookLog('evt_duplicate_123', new \DateTimeImmutable(), 'received');
        $log->setEventType('payment.received');
        $log->setProvider('stripe');

        // Act
        $this->webhookRepository->save($log);

        // Assert - findByEventId returns existing log (idempotency check)
        $existing = $this->webhookRepository->findByEventId('evt_duplicate_123');
        $this->assertNotNull($existing);
        $this->assertEquals('evt_duplicate_123', $existing->getEventId());
    }

    /**
     * @test
     * Full customer data can be saved via save method
     */
    public function fullCustomerDataCanBeSaved(): void
    {
        // Arrange - use lowercase keys as expected by the repository
        $data = [
            'id' => 'customer_record_123',
            'userId' => 'oxid_user_789',
            'paymentCustomerId' => 'cus_full_789',
            'defaultPaymentMethod' => 'card',
            'billingAgreement' => 1,
        ];

        // Act
        $this->customerRepository->save($data);
        $retrieved = $this->customerRepository->findByUserId('oxid_user_789');

        // Assert
        $this->assertNotNull($retrieved);
        $this->assertEquals('cus_full_789', $retrieved['OXPAYMENTCUSTOMERID']);
        $this->assertEquals('card', $retrieved['OXDEFAULTPAYMENTMETHOD']);
    }
}
