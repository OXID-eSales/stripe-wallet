<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Integration\Watch;

use PHPUnit\Framework\TestCase;

/**
 * Base class for PaymentWatch integration tests
 *
 * Provides helper methods for:
 * - Database setup and teardown
 * - Test data fixtures
 * - HTTP request helpers
 * - cURL utilities
 *
 * @group integration
 * @group watch
 */
abstract class PaymentWatchIntegrationTestCase extends TestCase
{
    protected string $baseUrl;
    protected string $apiKey;
    protected array $createdRecords = [];

    /**
     * Set up before each test
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->baseUrl = getenv('PAYMENTWATCH_URL') ?: 'http://localhost.local';
        $this->apiKey = getenv('PAYMENTWATCH_API_KEY') ?: $this->generateTestApiKey();

        // Check if endpoint is available before running tests
        if (!$this->isEndpointAvailable()) {
            $this->markTestSkipped(
                'PaymentWatch endpoint is not available. ' .
                'These integration tests require a running OXID shop with the Stripe module activated. ' .
                'Set PAYMENTWATCH_URL environment variable to run these tests.'
            );
        }

        $this->cleanupDatabase();
    }

    /**
     * Check if PaymentWatch endpoint is available
     */
    private function isEndpointAvailable(): bool
    {
        $ch = curl_init($this->baseUrl . '/paymentwatch/assume');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_NOBODY => true,
            CURLOPT_TIMEOUT => 2,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // Endpoint is available if we get any response other than 0 (connection failed) or 301/404 (not found)
        // We expect 401 (unauthorized) or 400 (bad request) when the endpoint exists
        return $httpCode !== 0 && $httpCode !== 301 && $httpCode !== 404;
    }

    /**
     * Clean up after each test
     */
    protected function tearDown(): void
    {
        $this->cleanupCreatedRecords();
        parent::tearDown();
    }

    /**
     * Make cURL request to PaymentWatch endpoint
     *
     * @param array<string, mixed> $payload Request payload
     * @param array<string, string> $headers Additional headers
     * @return array{status: int, body: array<string, mixed>, time: float}
     */
    protected function makeAssumptionRequest(array $payload, array $headers = []): array
    {
        $ch = curl_init($this->baseUrl . '/paymentwatch/assume');

        $defaultHeaders = [
            'Content-Type: application/json',
            'X-API-Key: ' . $this->apiKey,
            'X-Request-ID: test_' . uniqid(),
        ];

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => array_merge($defaultHeaders, $headers),
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT => 30,
        ]);

        $startTime = microtime(true);
        $response = curl_exec($ch);
        $requestTime = (microtime(true) - $startTime) * 1000;

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            $this->fail("cURL error: $error");
        }

        $body = json_decode($response, true) ?? [];

        return [
            'status' => $httpCode,
            'body' => $body,
            'time' => $requestTime,
        ];
    }

    /**
     * Create test contract in database
     *
     * @param array<string, mixed> $data Contract data
     * @return string Contract ID
     */
    protected function createTestContract(array $data = []): string
    {
        $contractId = 'test_contract_' . uniqid();

        $defaults = [
            'OXID' => $contractId,
            'OXSTATE' => 'pending',
            'OXORDERID' => null,
            'OXUSERID' => 'test_user_' . uniqid(),
            'OXPROVIDERID' => 'stripe',
            'OXPROVIDERORDERID' => 'pi_' . uniqid(),
            'OXAMOUNT' => 100.00,
            'OXCURRENCY' => 'EUR',
            'OXTIMESTAMP' => time(),
        ];

        $contractData = array_merge($defaults, $data);

        $this->insertRecord('osc_payment_contract', $contractData);

        return $contractId;
    }

    /**
     * Create test transaction in database
     *
     * @param string $contractId Contract ID
     * @param array<string, mixed> $data Transaction data
     * @return string Transaction ID
     */
    protected function createTestTransaction(string $contractId, array $data = []): string
    {
        $transactionId = 'test_tx_' . uniqid();

        $defaults = [
            'OXID' => $transactionId,
            'OXCONTRACTID' => $contractId,
            'OXSTATUS' => 'pending',
            'OXTYPE' => 'payment',
            'OXAMOUNT' => 100.00,
            'OXPROVIDERID' => 'stripe',
            'OXPROVIDERORDERID' => 'pi_' . uniqid(),
            'OXTIMESTAMP' => time(),
        ];

        $transactionData = array_merge($defaults, $data);

        $this->insertRecord('osc_payment_transaction', $transactionData);

        return $transactionId;
    }

    /**
     * Insert record into database
     *
     * @param string $table Table name
     * @param array<string, mixed> $data Record data
     */
    protected function insertRecord(string $table, array $data): void
    {
        $connection = $this->getDbConnection();

        $connection->insert($table, $data);

        $this->createdRecords[] = ['table' => $table, 'id' => $data['OXID']];
    }

    /**
     * Update record in database
     *
     * @param string $table Table name
     * @param string $id Record ID
     * @param array<string, mixed> $data Data to update
     */
    protected function updateRecord(string $table, string $id, array $data): void
    {
        $connection = $this->getDbConnection();

        $connection->update($table, $data, ['OXID' => $id]);
    }

    /**
     * Get record from database
     *
     * @param string $table Table name
     * @param string $id Record ID
     * @return array<string, mixed>|null Record data or null
     */
    protected function getRecord(string $table, string $id): ?array
    {
        $connection = $this->getDbConnection();

        $result = $connection->fetchAssociative(
            "SELECT * FROM {$table} WHERE OXID = ?",
            [$id]
        );

        return $result ?: null;
    }

    /**
     * Clean up created records
     */
    protected function cleanupCreatedRecords(): void
    {
        $connection = $this->getDbConnection();

        foreach (array_reverse($this->createdRecords) as $record) {
            try {
                $connection->delete($record['table'], ['OXID' => $record['id']]);
            } catch (\Exception $e) {
                // Ignore errors during cleanup
            }
        }

        $this->createdRecords = [];
    }

    /**
     * Clean up database before tests
     */
    protected function cleanupDatabase(): void
    {
        $connection = $this->getDbConnection();

        // Delete test records (those starting with 'test_')
        $connection->executeStatement(
            "DELETE FROM osc_payment_contract WHERE OXID LIKE 'test_%'"
        );
        $connection->executeStatement(
            "DELETE FROM osc_payment_transaction WHERE OXID LIKE 'test_%'"
        );
    }

    /**
     * Generate test API key
     *
     * @return string 64-character hex string
     */
    protected function generateTestApiKey(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Get database connection
     *
     * @return \Doctrine\DBAL\Connection
     */
    protected function getDbConnection(): \Doctrine\DBAL\Connection
    {
        // Load database configuration
        $dbConfig = require __DIR__ . '/../../../migration/migrations-db.php';

        // Create DBAL connection
        $config = new \Doctrine\DBAL\Configuration();
        return \Doctrine\DBAL\DriverManager::getConnection($dbConfig, $config);
    }

    /**
     * Assert response is successful
     *
     * @param array{status: int, body: array<string, mixed>, time: float} $response
     */
    protected function assertResponseSuccess(array $response): void
    {
        $this->assertEquals(200, $response['status'], 'Expected HTTP 200 response');
        $this->assertArrayHasKey('assumption', $response['body']);
        $this->assertArrayHasKey('query_time_ms', $response['body']);
        $this->assertArrayHasKey('matched_rows', $response['body']);
    }

    /**
     * Assert response indicates authentication failure
     *
     * @param array{status: int, body: array<string, mixed>, time: float} $response
     */
    protected function assertResponseUnauthorized(array $response): void
    {
        $this->assertEquals(401, $response['status'], 'Expected HTTP 401 response');
        $this->assertArrayHasKey('error', $response['body']);
    }

    /**
     * Assert response indicates validation error
     *
     * @param array{status: int, body: array<string, mixed>, time: float} $response
     */
    protected function assertResponseValidationError(array $response): void
    {
        $this->assertEquals(400, $response['status'], 'Expected HTTP 400 response');
        $this->assertArrayHasKey('error', $response['body']);
    }
}
