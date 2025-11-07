# Sprint 6: Integration & E2E Testing

**Duration:** 1 week
**Team:** 2-3 developers (1 QA engineer)
**Prerequisites:** Sprint 5 complete (Controller with 169 tests total)

---

## Sprint Overview

### Goal
Implement comprehensive **Integration and End-to-End (E2E) tests** to verify the entire PaymentWatch system works correctly with:
- **Real HTTP requests** (cURL integration tests)
- **Real database queries** (all operators tested)
- **Authentication scenarios** (success and failure cases)
- **Security penetration tests** (SQL injection attempts)
- **Performance benchmarks** (response time < 50ms)
- **Complete payment flow E2E** (order creation → transaction → verification)

### Testing Pyramid
```
        E2E Tests (3)
       /           \
    Integration (15)
   /                 \
Unit Tests (151)
```

### Key Deliverables
1. **HTTP Integration Tests** - Real cURL requests to controller
2. **Database Integration Tests** - All operators with real queries
3. **Authentication Tests** - API key + IP validation
4. **Security Penetration Tests** - SQL injection attempts
5. **E2E Payment Flow Test** - Complete workflow
6. **Performance Benchmarks** - Response time measurements
7. **Coverage Report** - Verify >= 90%

---

## Task 6.1: HTTP Integration Tests

**Time Estimate:** 3 hours
**Testing:** Integration with real HTTP server

### Create HTTP Integration Test

```bash
docker compose exec php bash -c "cat > /var/www/extensions/stripe/tests/Integration/Watch/Controller/HttpIntegrationTest.php << 'EOF'
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Integration\Watch\Controller;

use PHPUnit\Framework\TestCase;

/**
 * @covers \OxidSolutionCatalysts\Payments\Watch\Controller\AssumptionController
 * @group http
 * @group integration
 */
class HttpIntegrationTest extends TestCase
{
    private string \$baseUrl;
    private string \$apiKey;

    protected function setUp(): void
    {
        \$this->baseUrl = 'http://localhost/paymentwatch';
        \$this->apiKey = getenv('PAYMENTWATCH_API_KEY') ?: 'test-api-key';
    }

    /**
     * @test
     * Successful assumption returns 200 OK
     */
    public function it_handles_successful_assumption_via_http(): void
    {
        \$payload = json_encode([
            'table' => 'oxorder',
            'field' => 'oxordernr',
            'value' => '12345',
            'operator' => '==',
        ]);

        \$response = $this->makeRequest('/assume', \$payload);

        \$this->assertSame(200, \$response['status_code']);
        \$this->assertTrue(isset(\$response['body']['success']));
        \$this->assertTrue(isset(\$response['body']['request_id']));
    }

    /**
     * @test
     * Invalid API key returns 401
     */
    public function it_returns_401_for_invalid_api_key(): void
    {
        \$payload = json_encode([
            'table' => 'oxorder',
            'field' => 'oxordernr',
            'value' => '12345',
        ]);

        \$response = $this->makeRequest('/assume', \$payload, 'wrong-key');

        \$this->assertSame(401, \$response['status_code']);
        \$this->assertFalse(\$response['body']['success']);
        \$this->assertStringContainsString('Authentication failed', \$response['body']['message']);
    }

    /**
     * @test
     * Missing API key returns 401
     */
    public function it_returns_401_for_missing_api_key(): void
    {
        \$payload = json_encode(['table' => 'oxorder', 'field' => 'oxordernr', 'value' => '12345']);

        \$response = $this->makeRequest('/assume', \$payload, null);

        \$this->assertSame(401, \$response['status_code']);
        \$this->assertStringContainsString('Missing API key', \$response['body']['message']);
    }

    /**
     * @test
     * Invalid JSON returns 400
     */
    public function it_returns_400_for_invalid_json(): void
    {
        \$response = $this->makeRequest('/assume', 'invalid json{');

        \$this->assertSame(400, \$response['status_code']);
        \$this->assertStringContainsString('Invalid JSON', \$response['body']['message']);
    }

    /**
     * @test
     * @group security
     * SQL injection in table name returns 400
     */
    public function it_rejects_sql_injection_via_http(): void
    {
        \$payload = json_encode([
            'table' => "oxorder'; DROP TABLE oxorder;--",
            'field' => 'oxordernr',
            'value' => '12345',
        ]);

        \$response = $this->makeRequest('/assume', \$payload);

        \$this->assertSame(400, \$response['status_code']);
        \$this->assertStringContainsString('Invalid', \$response['body']['message']);
    }

    /**
     * @test
     * Comparison operator works via HTTP
     */
    public function it_handles_comparison_operator(): void
    {
        \$payload = json_encode([
            'table' => 'oxorder',
            'field' => 'oxtotalordersum',
            'value' => 0.01,
            'operator' => '>',
        ]);

        \$response = $this->makeRequest('/assume', \$payload);

        \$this->assertSame(200, \$response['status_code']);
    }

    /**
     * @test
     * LIKE operator works via HTTP
     */
    public function it_handles_like_operator(): void
    {
        \$payload = json_encode([
            'table' => 'oxuser',
            'field' => 'oxusername',
            'value' => '@example.com',
            'operator' => '%like',
        ]);

        \$response = $this->makeRequest('/assume', \$payload);

        \$this->assertSame(200, \$response['status_code']);
    }

    /**
     * @test
     * WHERE clause works via HTTP
     */
    public function it_handles_where_clause(): void
    {
        \$payload = json_encode([
            'table' => 'oxorder',
            'field' => 'oxpaid',
            'value' => '0000-00-00 00:00:00',
            'operator' => '==',
            'where' => [
                'oxstorno' => 0,
            ],
        ]);

        \$response = $this->makeRequest('/assume', \$payload);

        \$this->assertSame(200, \$response['status_code']);
    }

    /**
     * @test
     * Custom request ID is preserved
     */
    public function it_preserves_custom_request_id(): void
    {
        \$customRequestId = 'test-req-' . time();

        \$payload = json_encode([
            'table' => 'oxorder',
            'field' => 'oxordernr',
            'value' => '12345',
        ]);

        \$response = $this->makeRequest('/assume', \$payload, \$this->apiKey, \$customRequestId);

        \$this->assertSame(200, \$response['status_code']);
        \$this->assertSame(\$customRequestId, \$response['body']['request_id']);
    }

    /**
     * Make HTTP request using cURL
     *
     * @return array{status_code: int, body: array<string, mixed>}
     */
    private function makeRequest(
        string \$path,
        string \$payload,
        ?string \$apiKey = null,
        ?string \$requestId = null
    ): array {
        \$ch = curl_init(\$this->baseUrl . \$path);

        \$headers = ['Content-Type: application/json'];

        if (\$apiKey !== null) {
            \$headers[] = 'X-API-Key: ' . \$apiKey;
        } elseif (\$apiKey !== false) {
            \$headers[] = 'X-API-Key: ' . \$this->apiKey;
        }

        if (\$requestId !== null) {
            \$headers[] = 'X-Request-ID: ' . \$requestId;
        }

        curl_setopt_array(\$ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => \$payload,
            CURLOPT_HTTPHEADER => \$headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
        ]);

        \$response = curl_exec(\$ch);
        \$statusCode = curl_getinfo(\$ch, CURLINFO_HTTP_CODE);
        curl_close(\$ch);

        return [
            'status_code' => \$statusCode,
            'body' => json_decode(\$response, true),
        ];
    }
}
EOF"
```

#### Run HTTP Integration Tests
```bash
docker compose exec -T php vendor/bin/phpunit \
  -c /var/www/extensions/stripe/tests/phpunit.xml \
  --bootstrap=/var/www/source/bootstrap.php \
  --group http
```

**Expected:** ✅ 9 HTTP integration tests passing

---

## Task 6.2: E2E Payment Flow Test

**Time Estimate:** 4 hours
**Testing:** Complete payment workflow

### Create E2E Payment Flow Test

```bash
docker compose exec php bash -c "cat > /var/www/extensions/stripe/tests/Integration/Watch/E2E/PaymentFlowE2ETest.php << 'EOF'
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Integration\Watch\E2E;

use Doctrine\DBAL\Connection;
use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use PHPUnit\Framework\TestCase;

/**
 * @group e2e
 * @group slow
 */
class PaymentFlowE2ETest extends TestCase
{
    private Connection \$connection;
    private string \$baseUrl;
    private string \$apiKey;
    private string \$testOrderId;

    protected function setUp(): void
    {
        \$container = ContainerFactory::getInstance()->getContainer();
        \$this->connection = \$container->get(Connection::class);

        \$this->baseUrl = 'http://localhost/paymentwatch';
        \$this->apiKey = getenv('PAYMENTWATCH_API_KEY') ?: 'test-api-key';
        \$this->testOrderId = 'TEST-E2E-' . time();
    }

    protected function tearDown(): void
    {
        // Cleanup test data
        \$this->connection->delete('oxorder', ['OXORDERNR' => \$this->testOrderId]);
        \$this->connection->delete('oepaypal_order', ['OXORDERID' => \$this->testOrderId]);
    }

    /**
     * @test
     * Complete E2E payment flow:
     * 1. Create order
     * 2. Create payment transaction
     * 3. Verify order exists
     * 4. Verify transaction status
     * 5. Verify payment received
     */
    public function it_verifies_complete_payment_flow(): void
    {
        // Step 1: Create order in database
        \$orderId = md5(\$this->testOrderId);
        \$this->connection->insert('oxorder', [
            'OXID' => \$orderId,
            'OXSHOPID' => 1,
            'OXUSERID' => 'oxdefaultadmin',
            'OXORDERNR' => \$this->testOrderId,
            'OXORDERDATE' => date('Y-m-d H:i:s'),
            'OXBILLEMAIL' => 'test-e2e@example.com',
            'OXTOTALORDERSUM' => 99.99,
            'OXSTORNO' => 0,
            'OXPAID' => '0000-00-00 00:00:00',
            'OXPAYMENTTYPE' => 'oxidpaypal',
        ]);

        // Step 2: Verify order exists via PaymentWatch
        \$response = $this->makePaymentWatchRequest([
            'table' => 'oxorder',
            'field' => 'oxordernr',
            'value' => \$this->testOrderId,
            'operator' => '==',
        ]);

        \$this->assertTrue(\$response['body']['success'], 'Order should exist');

        // Step 3: Verify order is not paid yet
        \$response = $this->makePaymentWatchRequest([
            'table' => 'oxorder',
            'field' => 'oxpaid',
            'value' => '0000-00-00 00:00:00',
            'operator' => '==',
            'where' => [
                'oxordernr' => \$this->testOrderId,
            ],
        ]);

        \$this->assertTrue(\$response['body']['success'], 'Order should not be paid yet');

        // Step 4: Create PayPal transaction
        \$transactionId = 'TXN-' . time();
        \$this->connection->insert('oepaypal_order', [
            'OXORDERID' => \$orderId,
            'OXPROVIDERORDERID' => \$transactionId,
            'OXTRANSACTIONSTATUS' => 'pending',
            'OXTIMESTAMP' => date('Y-m-d H:i:s'),
            'OXCURRENCY' => 'EUR',
            'OXTOTALORDERSUM' => 99.99,
        ]);

        // Step 5: Verify transaction exists
        \$response = $this->makePaymentWatchRequest([
            'table' => 'oepaypal_order',
            'field' => 'oxproviderorderid',
            'value' => \$transactionId,
            'operator' => '==',
        ]);

        \$this->assertTrue(\$response['body']['success'], 'Transaction should exist');

        // Step 6: Verify transaction status is pending
        \$response = $this->makePaymentWatchRequest([
            'table' => 'oepaypal_order',
            'field' => 'oxtransactionstatus',
            'value' => 'pending',
            'operator' => '==',
            'where' => [
                'oxproviderorderid' => \$transactionId,
            ],
        ]);

        \$this->assertTrue(\$response['body']['success'], 'Transaction status should be pending');

        // Step 7: Simulate payment completion (update transaction status)
        \$this->connection->update(
            'oepaypal_order',
            ['OXTRANSACTIONSTATUS' => 'completed'],
            ['OXPROVIDERORDERID' => \$transactionId]
        );

        // Step 8: Verify transaction is completed
        \$response = $this->makePaymentWatchRequest([
            'table' => 'oepaypal_order',
            'field' => 'oxtransactionstatus',
            'value' => 'completed',
            'operator' => '==',
            'where' => [
                'oxproviderorderid' => \$transactionId,
            ],
        ]);

        \$this->assertTrue(\$response['body']['success'], 'Transaction should be completed');

        // Step 9: Update order payment date
        \$this->connection->update(
            'oxorder',
            ['OXPAID' => date('Y-m-d H:i:s')],
            ['OXORDERNR' => \$this->testOrderId]
        );

        // Step 10: Verify order is now paid
        \$response = $this->makePaymentWatchRequest([
            'table' => 'oxorder',
            'field' => 'oxpaid',
            'value' => '0000-00-00 00:00:00',
            'operator' => '!=',
            'where' => [
                'oxordernr' => \$this->testOrderId,
            ],
        ]);

        \$this->assertTrue(\$response['body']['success'], 'Order should be marked as paid');

        // Step 11: Verify total order sum
        \$response = $this->makePaymentWatchRequest([
            'table' => 'oxorder',
            'field' => 'oxtotalordersum',
            'value' => 99.99,
            'operator' => '==',
            'where' => [
                'oxordernr' => \$this->testOrderId,
            ],
        ]);

        \$this->assertTrue(\$response['body']['success'], 'Order total should match');
    }

    /**
     * @test
     * E2E test for cancelled order
     */
    public function it_verifies_cancelled_order_flow(): void
    {
        // Create order
        \$orderId = md5(\$this->testOrderId);
        \$this->connection->insert('oxorder', [
            'OXID' => \$orderId,
            'OXSHOPID' => 1,
            'OXUSERID' => 'oxdefaultadmin',
            'OXORDERNR' => \$this->testOrderId,
            'OXORDERDATE' => date('Y-m-d H:i:s'),
            'OXBILLEMAIL' => 'test-e2e@example.com',
            'OXTOTALORDERSUM' => 99.99,
            'OXSTORNO' => 1,  // Cancelled
            'OXPAID' => '0000-00-00 00:00:00',
        ]);

        // Verify order is cancelled
        \$response = $this->makePaymentWatchRequest([
            'table' => 'oxorder',
            'field' => 'oxstorno',
            'value' => 1,
            'operator' => '==',
            'where' => [
                'oxordernr' => \$this->testOrderId,
            ],
        ]);

        \$this->assertTrue(\$response['body']['success'], 'Order should be cancelled');
    }

    /**
     * @test
     * E2E test for refunded transaction
     */
    public function it_verifies_refunded_transaction_flow(): void
    {
        // Create order and transaction
        \$orderId = md5(\$this->testOrderId);
        \$transactionId = 'TXN-REFUND-' . time();

        \$this->connection->insert('oxorder', [
            'OXID' => \$orderId,
            'OXSHOPID' => 1,
            'OXUSERID' => 'oxdefaultadmin',
            'OXORDERNR' => \$this->testOrderId,
            'OXORDERDATE' => date('Y-m-d H:i:s'),
            'OXBILLEMAIL' => 'test-e2e@example.com',
            'OXTOTALORDERSUM' => 99.99,
            'OXSTORNO' => 0,
        ]);

        \$this->connection->insert('oepaypal_order', [
            'OXORDERID' => \$orderId,
            'OXPROVIDERORDERID' => \$transactionId,
            'OXTRANSACTIONSTATUS' => 'refunded',
            'OXTIMESTAMP' => date('Y-m-d H:i:s'),
            'OXCURRENCY' => 'EUR',
            'OXTOTALORDERSUM' => 99.99,
        ]);

        // Verify transaction is refunded
        \$response = $this->makePaymentWatchRequest([
            'table' => 'oepaypal_order',
            'field' => 'oxtransactionstatus',
            'value' => 'refunded',
            'operator' => '==',
            'where' => [
                'oxproviderorderid' => \$transactionId,
            ],
        ]);

        \$this->assertTrue(\$response['body']['success'], 'Transaction should be refunded');
    }

    /**
     * Make PaymentWatch API request
     *
     * @param array<string, mixed> \$payload
     * @return array{status_code: int, body: array<string, mixed>}
     */
    private function makePaymentWatchRequest(array \$payload): array
    {
        \$ch = curl_init(\$this->baseUrl . '/assume');

        curl_setopt_array(\$ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(\$payload),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-API-Key: ' . \$this->apiKey,
            ],
            CURLOPT_RETURNTRANSFER => true,
        ]);

        \$response = curl_exec(\$ch);
        \$statusCode = curl_getinfo(\$ch, CURLINFO_HTTP_CODE);
        curl_close(\$ch);

        return [
            'status_code' => \$statusCode,
            'body' => json_decode(\$response, true),
        ];
    }
}
EOF"
```

#### Run E2E Tests
```bash
docker compose exec -T php vendor/bin/phpunit \
  -c /var/www/extensions/stripe/tests/phpunit.xml \
  --bootstrap=/var/www/source/bootstrap.php \
  --group e2e
```

**Expected:** ✅ 3 E2E tests passing

---

## Task 6.3: Performance Benchmarks

**Time Estimate:** 2 hours
**Testing:** Response time measurements

### Create Performance Benchmark Test

```bash
docker compose exec php bash -c "cat > /var/www/extensions/stripe/tests/Integration/Watch/Performance/PerformanceBenchmarkTest.php << 'EOF'
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Integration\Watch\Performance;

use PHPUnit\Framework\TestCase;

/**
 * @group performance
 * @group slow
 */
class PerformanceBenchmarkTest extends TestCase
{
    private string \$baseUrl;
    private string \$apiKey;

    protected function setUp(): void
    {
        \$this->baseUrl = 'http://localhost/paymentwatch';
        \$this->apiKey = getenv('PAYMENTWATCH_API_KEY') ?: 'test-api-key';
    }

    /**
     * @test
     * Average response time should be < 50ms
     */
    public function it_responds_within_50ms_on_average(): void
    {
        \$iterations = 10;
        \$durations = [];

        for (\$i = 0; \$i < \$iterations; \$i++) {
            \$startTime = microtime(true);

            $this->makeRequest([
                'table' => 'oxorder',
                'field' => 'oxordernr',
                'value' => '12345',
                'operator' => '==',
            ]);

            \$duration = (microtime(true) - \$startTime) * 1000; // Convert to ms
            \$durations[] = \$duration;
        }

        \$avgDuration = array_sum(\$durations) / count(\$durations);

        \$this->assertLessThan(
            50.0,
            \$avgDuration,
            sprintf('Average response time %.2fms exceeds 50ms threshold', \$avgDuration)
        );

        echo sprintf("\nAverage response time: %.2fms\n", \$avgDuration);
        echo sprintf("Min: %.2fms, Max: %.2fms\n", min(\$durations), max(\$durations));
    }

    /**
     * @test
     * P95 response time should be < 100ms
     */
    public function it_meets_p95_response_time_target(): void
    {
        \$iterations = 100;
        \$durations = [];

        for (\$i = 0; \$i < \$iterations; \$i++) {
            \$startTime = microtime(true);

            $this->makeRequest([
                'table' => 'oxorder',
                'field' => 'oxordernr',
                'value' => (string) \$i,
                'operator' => '==',
            ]);

            \$duration = (microtime(true) - \$startTime) * 1000;
            \$durations[] = \$duration;
        }

        sort(\$durations);
        \$p95Index = (int) ceil(0.95 * count(\$durations)) - 1;
        \$p95Duration = \$durations[\$p95Index];

        \$this->assertLessThan(
            100.0,
            \$p95Duration,
            sprintf('P95 response time %.2fms exceeds 100ms threshold', \$p95Duration)
        );

        echo sprintf("\nP95 response time: %.2fms\n", \$p95Duration);
    }

    /**
     * @test
     * Complex query with WHERE clause and LIKE operator
     */
    public function it_handles_complex_queries_efficiently(): void
    {
        \$startTime = microtime(true);

        $this->makeRequest([
            'table' => 'oxorder',
            'field' => 'oxbillemail',
            'value' => '@example.com',
            'operator' => '%like',
            'where' => [
                'oxstorno' => 0,
            ],
        ]);

        \$duration = (microtime(true) - \$startTime) * 1000;

        \$this->assertLessThan(
            100.0,
            \$duration,
            sprintf('Complex query took %.2fms, expected < 100ms', \$duration)
        );

        echo sprintf("\nComplex query duration: %.2fms\n", \$duration);
    }

    /**
     * Make API request
     *
     * @param array<string, mixed> \$payload
     * @return array{status_code: int, body: array<string, mixed>}
     */
    private function makeRequest(array \$payload): array
    {
        \$ch = curl_init(\$this->baseUrl . '/assume');

        curl_setopt_array(\$ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(\$payload),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-API-Key: ' . \$this->apiKey,
            ],
            CURLOPT_RETURNTRANSFER => true,
        ]);

        \$response = curl_exec(\$ch);
        \$statusCode = curl_getinfo(\$ch, CURLINFO_HTTP_CODE);
        curl_close(\$ch);

        return [
            'status_code' => \$statusCode,
            'body' => json_decode(\$response, true),
        ];
    }
}
EOF"
```

#### Run Performance Benchmarks
```bash
docker compose exec -T php vendor/bin/phpunit \
  -c /var/www/extensions/stripe/tests/phpunit.xml \
  --bootstrap=/var/www/source/bootstrap.php \
  --group performance
```

**Expected:** ✅ 3 performance tests passing with timing output

---

## Task 6.4: Coverage Verification

**Time Estimate:** 1 hour

### Generate Coverage Report

```bash
# Generate HTML coverage report
docker compose exec -T -e XDEBUG_MODE=coverage php vendor/bin/phpunit \
  -c /var/www/extensions/stripe/tests/phpunit.xml \
  --bootstrap=/var/www/source/bootstrap.php \
  --filter Watch \
  --coverage-html /var/www/extensions/stripe/coverage \
  --coverage-text

# View coverage summary
docker compose exec php bash -c "cat /var/www/extensions/stripe/coverage/index.html | grep 'Coverage'"
```

### Create Coverage Verification Script

```bash
docker compose exec php bash -c "cat > /var/www/extensions/stripe/scripts/verify-coverage.sh << 'EOF'
#!/bin/bash

set -e

echo "Running tests with coverage analysis..."

# Run tests and capture coverage output
COVERAGE=\$(docker compose exec -T -e XDEBUG_MODE=coverage php vendor/bin/phpunit \\
  -c /var/www/extensions/stripe/tests/phpunit.xml \\
  --bootstrap=/var/www/source/bootstrap.php \\
  --filter Watch \\
  --coverage-text 2>&1 | grep -A 3 'Code Coverage Report:')

echo \"\$COVERAGE\"

# Extract overall coverage percentage
PERCENTAGE=\$(echo \"\$COVERAGE\" | grep -oP 'Lines:\\s+\\K[0-9.]+')

echo \"\"
echo \"Overall coverage: \${PERCENTAGE}%\"

# Check if >= 90%
if (( \$(echo \"\$PERCENTAGE >= 90\" | bc -l) )); then
    echo \"✅ Coverage target met (>= 90%)\"
    exit 0
else
    echo \"❌ Coverage below target: \${PERCENTAGE}% < 90%\"
    exit 1
fi
EOF"

chmod +x /var/www/extensions/stripe/scripts/verify-coverage.sh
```

#### Run Coverage Verification
```bash
docker compose exec php /var/www/extensions/stripe/scripts/verify-coverage.sh
```

**Expected:** ✅ Coverage >= 90%

---

## Sprint 6 Deliverables

### Test Files Created
```
tests/Integration/Watch/Controller/
└── HttpIntegrationTest.php (9 tests) @group http

tests/Integration/Watch/E2E/
└── PaymentFlowE2ETest.php (3 tests) @group e2e

tests/Integration/Watch/Performance/
└── PerformanceBenchmarkTest.php (3 tests) @group performance

scripts/
└── verify-coverage.sh
```

**Total:** 15 new integration/E2E tests (184 total tests)

---

## Acceptance Criteria

### Integration Tests
- ✅ 9 HTTP integration tests passing
- ✅ 3 E2E payment flow tests passing
- ✅ All operators tested with real database
- ✅ Authentication scenarios covered
- ✅ Security penetration tests passing

### Performance
- ✅ Average response time < 50ms
- ✅ P95 response time < 100ms
- ✅ Complex queries < 100ms

### Coverage
- ✅ Overall coverage >= 90%
- ✅ Domain layer: 100%
- ✅ Application layer: >= 95%
- ✅ Infrastructure layer: >= 95%
- ✅ Controller: >= 90%

---

## Verify Sprint Completion

### Run All Tests
```bash
docker compose exec -T -e XDEBUG_MODE=coverage php vendor/bin/phpunit \
  -c /var/www/extensions/stripe/tests/phpunit.xml \
  --bootstrap=/var/www/source/bootstrap.php \
  --filter Watch
```

**Expected:** ✅ 184 tests passing

### Run Tests by Group

```bash
# Unit tests only
docker compose exec -T php vendor/bin/phpunit --filter Watch --exclude-group integration,e2e,http,performance

# Integration tests
docker compose exec -T php vendor/bin/phpunit --group integration

# HTTP tests
docker compose exec -T php vendor/bin/phpunit --group http

# E2E tests
docker compose exec -T php vendor/bin/phpunit --group e2e

# Performance tests
docker compose exec -T php vendor/bin/phpunit --group performance

# Security tests
docker compose exec -T php vendor/bin/phpunit --group security
```

### Test Summary
```bash
docker compose exec php bash -c "cat << 'SUMMARY'
╔═══════════════════════════════════════════════════════╗
║          PaymentWatch Test Summary                    ║
╠═══════════════════════════════════════════════════════╣
║ Unit Tests:            169                            ║
║ Integration Tests:      12                            ║
║ HTTP Tests:              9                            ║
║ E2E Tests:               3                            ║
║ Performance Tests:       3                            ║
║─────────────────────────────────────────────────────  ║
║ Total Tests:           196                            ║
║ Security Tests:         40 (@group security)          ║
║ Coverage:              >= 90%                         ║
║ Avg Response Time:     < 50ms                         ║
║ P95 Response Time:     < 100ms                        ║
╚═══════════════════════════════════════════════════════╝
SUMMARY"
```

---

## Sprint Review

### Demo Checklist
- [ ] Run complete test suite (184 tests)
- [ ] Show HTTP integration tests with cURL
- [ ] Demonstrate E2E payment flow (11 steps)
- [ ] Show performance benchmarks (< 50ms avg)
- [ ] Display coverage report (>= 90%)
- [ ] Run security penetration tests

### Retrospective Questions
1. Are there any untested edge cases?
2. Should we add load testing (concurrent requests)?
3. Are performance targets realistic for production?
4. Should we add monitoring/alerting for slow queries?

---

## Common Issues

### Issue: HTTP tests fail - connection refused
**Solution:** Ensure OXID shop is running: `docker compose up -d`
Check baseUrl in tests matches your environment

### Issue: E2E tests flaky (sometimes fail)
**Solution:** Add proper cleanup in tearDown()
Use unique test data per run (timestamps)
Check for database locks

### Issue: Performance tests fail on slow machines
**Solution:** Adjust thresholds for development environments
Run on production-like hardware for accurate benchmarks

---

## Next Sprint

**Ready for [Sprint 7: JavaScript SDK Development](sprint-07-js-sdk.md)**

Sprint 7 will implement:
- TypeScript PaymentWatch client
- TDD workflow for JavaScript
- Error classes and retry logic
- Dual module build (ESM + CommonJS)
- Integration tests against real server
- >= 90% test coverage

---

**Sprint 6 Complete! 🎉**
**Tests:** 15 new integration/E2E tests (184 total)
**Coverage:** >= 90%
**Performance:** < 50ms average
**Next:** JavaScript SDK (Weeks 8-9)
