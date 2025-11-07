# PaymentWatch - Implementation Guide

**Developer Guide for Building the PaymentWatch Module**

Version: 1.0.0
Date: 2025-11-11

---

## Overview

This guide provides step-by-step instructions for implementing the PaymentWatch test helper module in the OXID eShop payment component.

### Namespace & Directory Structure

**From composer.json:**
```json
"OxidSolutionCatalysts\\Payments\\Watch\\": "./src/Watch"
```

**Important:** The namespace includes `Payments`, but the physical directory does NOT:
- ✅ **Namespace:** `OxidSolutionCatalysts\Payments\Watch\`
- ✅ **Directory:** `src/Watch/`
- ❌ **NOT:** `src/Payments/Watch/`

**Example:**
```php
<?php
// Namespace includes "Payments"
namespace OxidSolutionCatalysts\Payments\Watch\Controller;

// But file is at: src/Watch/Controller/AssumptionController.php
// NOT at: src/Payments/Watch/Controller/AssumptionController.php

class AssumptionController { /* ... */ }
```

---

## Architecture Components

```
src/Watch/
├── Controller/
│   └── AssumptionController.php       # Main HTTP endpoint
├── Service/
│   ├── AuthenticationService.php      # IP + API key validation
│   ├── AssumptionParser.php           # Request payload parsing
│   ├── QueryBuilder.php               # Safe SQL query construction
│   └── AuditLogger.php                # Request logging
├── ValueObject/
│   ├── AssumptionRequest.php          # Value object for request
│   ├── AssumptionResponse.php         # Value object for response
│   └── AuthConfig.php                 # IP/API key configuration
├── Strategy/
│   ├── OperatorStrategyInterface.php
│   ├── EqualityOperator.php
│   ├── ComparisonOperator.php
│   ├── LikeOperator.php
│   └── NullCheckOperator.php
├── Exception/
│   ├── AuthenticationException.php
│   ├── ValidationException.php
│   └── QueryException.php
└── Config/
    └── routes.yaml                     # Route definitions
```

---

## Step 1: Controller Implementation

### AssumptionController.php

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Watch\Controller;

use OxidSolutionCatalysts\Payments\Watch\Service\AuthenticationService;
use OxidSolutionCatalysts\Payments\Watch\Service\AssumptionParser;
use OxidSolutionCatalysts\Payments\Watch\Service\QueryBuilder;
use OxidSolutionCatalysts\Payments\Watch\Service\AuditLogger;
use OxidSolutionCatalysts\Payments\Watch\Exception\AuthenticationException;
use OxidSolutionCatalysts\Payments\Watch\Exception\ValidationException;
use OxidSolutionCatalysts\Payments\Watch\Exception\QueryException;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

class AssumptionController
{
    public function __construct(
        private AuthenticationService $authService,
        private AssumptionParser $parser,
        private QueryBuilder $queryBuilder,
        private AuditLogger $auditLogger,
        private LoggerInterface $logger
    ) {}

    public function assume(ServerRequestInterface $request): ResponseInterface
    {
        $startTime = microtime(true);
        $requestId = $request->getHeaderLine('X-Request-ID') ?: uniqid('pwreq_', true);

        try {
            // 1. Authenticate request (IP + API key)
            $clientIp = $this->getClientIp($request);
            $apiKey = $request->getHeaderLine('X-API-Key');

            $this->authService->authenticate($clientIp, $apiKey);

            // 2. Parse request body
            $body = json_decode((string) $request->getBody(), true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new ValidationException('Invalid JSON payload');
            }

            $assumptionRequest = $this->parser->parse($body);

            // 3. Build and execute query
            $result = $this->queryBuilder->execute($assumptionRequest);

            // 4. Build response
            $queryTime = (microtime(true) - $startTime) * 1000;  // Convert to ms

            $response = [
                'assumption' => $result->isMatch(),
                'query_time_ms' => round($queryTime, 2),
                'matched_rows' => $result->getMatchedRows()
            ];

            // Include actual value if assumption is false
            if (!$result->isMatch() && $result->getActualValue() !== null) {
                $response['actual_value'] = $result->getActualValue();
                $response['expected_value'] = $assumptionRequest->getExpectedValue();
            }

            // 5. Audit log
            $this->auditLogger->logRequest(
                requestId: $requestId,
                clientIp: $clientIp,
                query: $assumptionRequest->getFieldPath(),
                result: $result->isMatch(),
                queryTimeMs: $queryTime
            );

            return $this->jsonResponse($response, 200);

        } catch (AuthenticationException $e) {
            $this->logger->warning('PaymentWatch authentication failed', [
                'ip' => $clientIp ?? 'unknown',
                'error' => $e->getMessage()
            ]);

            return $this->jsonResponse([
                'error' => 'Unauthorized',
                'details' => $e->getMessage()
            ], 401);

        } catch (ValidationException $e) {
            $this->logger->info('PaymentWatch validation error', [
                'error' => $e->getMessage(),
                'request_id' => $requestId
            ]);

            return $this->jsonResponse([
                'error' => 'Invalid assumption format',
                'details' => $e->getMessage()
            ], 400);

        } catch (QueryException $e) {
            $this->logger->error('PaymentWatch query error', [
                'error' => $e->getMessage(),
                'request_id' => $requestId
            ]);

            return $this->jsonResponse([
                'error' => 'Database query failed',
                'details' => $e->getMessage()
            ], 500);

        } catch (\Throwable $e) {
            $this->logger->critical('PaymentWatch unexpected error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request_id' => $requestId
            ]);

            return $this->jsonResponse([
                'error' => 'Internal server error'
            ], 500);
        }
    }

    private function getClientIp(ServerRequestInterface $request): string
    {
        // Check for proxy headers (if behind reverse proxy)
        $headers = [
            'X-Forwarded-For',
            'X-Real-IP',
            'CF-Connecting-IP'  // Cloudflare
        ];

        foreach ($headers as $header) {
            if ($request->hasHeader($header)) {
                $ip = $request->getHeaderLine($header);
                // Take first IP if multiple (X-Forwarded-For can be comma-separated)
                return trim(explode(',', $ip)[0]);
            }
        }

        // Fallback to server params
        $serverParams = $request->getServerParams();
        return $serverParams['REMOTE_ADDR'] ?? 'unknown';
    }

    private function jsonResponse(array $data, int $status): ResponseInterface
    {
        // Use PSR-7 response factory
        $response = new \Laminas\Diactoros\Response\JsonResponse($data, $status);
        return $response->withHeader('X-Content-Type-Options', 'nosniff');
    }
}
```

---

## Step 2: Authentication Service

### AuthenticationService.php

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Watch\Service;

use OxidSolutionCatalysts\Payments\Watch\ValueObject\AuthConfig;
use OxidSolutionCatalysts\Payments\Watch\Exception\AuthenticationException;

class AuthenticationService
{
    private array $allowedHosts = [];

    public function __construct(AuthConfig $config)
    {
        $this->allowedHosts = $config->getAllowedHosts();
    }

    /**
     * @throws AuthenticationException
     */
    public function authenticate(string $clientIp, string $apiKey): void
    {
        if (empty($apiKey)) {
            throw new AuthenticationException('Missing API key');
        }

        // Validate API key format (64-char hex)
        if (!preg_match('/^[a-f0-9]{64}$/i', $apiKey)) {
            throw new AuthenticationException('Invalid API key format');
        }

        // Check if IP is whitelisted and API key matches
        $authenticated = false;

        foreach ($this->allowedHosts as $host) {
            if ($this->ipMatches($clientIp, $host['ip'])) {
                // Constant-time comparison to prevent timing attacks
                if (hash_equals($host['api_key'], strtolower($apiKey))) {
                    $authenticated = true;
                    break;
                }
            }
        }

        if (!$authenticated) {
            throw new AuthenticationException('Invalid API key or IP not whitelisted');
        }
    }

    private function ipMatches(string $clientIp, string $allowedIp): bool
    {
        // Support CIDR notation (e.g., 192.168.1.0/24)
        if (str_contains($allowedIp, '/')) {
            return $this->ipInRange($clientIp, $allowedIp);
        }

        // Exact match
        return $clientIp === $allowedIp;
    }

    private function ipInRange(string $ip, string $cidr): bool
    {
        [$subnet, $mask] = explode('/', $cidr);

        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);
        $maskLong = -1 << (32 - (int) $mask);

        return ($ipLong & $maskLong) === ($subnetLong & $maskLong);
    }
}
```

---

## Step 3: Assumption Parser

### AssumptionParser.php

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Watch\Service;

use OxidSolutionCatalysts\Payments\Watch\ValueObject\AssumptionRequest;
use OxidSolutionCatalysts\Payments\Watch\Exception\ValidationException;

class AssumptionParser
{
    private const VALID_OPERATORS = [
        '==', '!=', '>', '<', '>=', '<=',
        '%like%', 'like%', '%like',
        'IS NULL', 'IS NOT NULL'
    ];

    /**
     * @throws ValidationException
     */
    public function parse(array $body): AssumptionRequest
    {
        if (!isset($body['assumption'])) {
            throw new ValidationException('Missing "assumption" key in request body');
        }

        $assumption = $body['assumption'];

        if (!is_array($assumption)) {
            throw new ValidationException('"assumption" must be an object');
        }

        // Extract field path (e.g., "osc_payment_transaction.OXSTATUS")
        $fieldPath = null;
        $expectedValue = null;

        foreach ($assumption as $key => $value) {
            if (!in_array($key, ['op', 'where'], true)) {
                $fieldPath = $key;
                $expectedValue = $value;
                break;
            }
        }

        if ($fieldPath === null) {
            throw new ValidationException('No field path found in assumption');
        }

        // Parse field path
        if (!str_contains($fieldPath, '.')) {
            throw new ValidationException(
                'Field path must be in format "table_name.field_name"'
            );
        }

        [$tableName, $fieldName] = explode('.', $fieldPath, 2);

        // Validate table/field names (prevent SQL injection)
        if (!$this->isValidIdentifier($tableName)) {
            throw new ValidationException("Invalid table name: {$tableName}");
        }

        if (!$this->isValidIdentifier($fieldName)) {
            throw new ValidationException("Invalid field name: {$fieldName}");
        }

        // Extract operator (default: ==)
        $operator = $assumption['op'] ?? '==';

        if (!in_array($operator, self::VALID_OPERATORS, true)) {
            throw new ValidationException("Invalid operator: {$operator}");
        }

        // Extract WHERE clause (optional)
        $whereClause = $assumption['where'] ?? [];

        if (!empty($whereClause) && !is_array($whereClause)) {
            throw new ValidationException('"where" must be an object');
        }

        // Validate WHERE clause fields
        foreach ($whereClause as $whereField => $whereValue) {
            if (!str_contains($whereField, '.')) {
                throw new ValidationException(
                    "WHERE field must be in format \"table_name.field_name\": {$whereField}"
                );
            }

            [$whereTable, $whereFieldName] = explode('.', $whereField, 2);

            if (!$this->isValidIdentifier($whereTable) || !$this->isValidIdentifier($whereFieldName)) {
                throw new ValidationException("Invalid WHERE clause field: {$whereField}");
            }
        }

        return new AssumptionRequest(
            tableName: $tableName,
            fieldName: $fieldName,
            expectedValue: $expectedValue,
            operator: $operator,
            whereClause: $whereClause
        );
    }

    private function isValidIdentifier(string $name): bool
    {
        // Allow alphanumeric, underscore, no SQL keywords
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name)) {
            return false;
        }

        // Block SQL keywords
        $sqlKeywords = [
            'SELECT', 'INSERT', 'UPDATE', 'DELETE', 'DROP', 'CREATE', 'ALTER',
            'TRUNCATE', 'UNION', 'WHERE', 'FROM', 'JOIN', 'ORDER', 'GROUP'
        ];

        return !in_array(strtoupper($name), $sqlKeywords, true);
    }
}
```

---

## Step 4: Query Builder

### QueryBuilder.php

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Watch\Service;

use OxidSolutionCatalysts\Payments\Watch\ValueObject\AssumptionRequest;
use OxidSolutionCatalysts\Payments\Watch\ValueObject\AssumptionResponse;
use OxidSolutionCatalysts\Payments\Watch\Exception\QueryException;
use Doctrine\DBAL\Connection;

class QueryBuilder
{
    public function __construct(private Connection $connection) {}

    /**
     * @throws QueryException
     */
    public function execute(AssumptionRequest $request): AssumptionResponse
    {
        try {
            // Build SELECT query
            $sql = $this->buildQuery($request);
            $params = $this->buildParams($request);

            // Execute query
            $result = $this->connection->fetchAssociative($sql, $params);

            if ($result === false) {
                // No rows matched WHERE clause
                return new AssumptionResponse(
                    isMatch: false,
                    matchedRows: 0,
                    actualValue: null
                );
            }

            // Extract actual value
            $actualValue = $result[$request->getFieldName()];

            // Compare with expected value
            $isMatch = $this->compareValues(
                $actualValue,
                $request->getExpectedValue(),
                $request->getOperator()
            );

            return new AssumptionResponse(
                isMatch: $isMatch,
                matchedRows: $isMatch ? 1 : 0,
                actualValue: $actualValue
            );

        } catch (\Doctrine\DBAL\Exception $e) {
            throw new QueryException("Database error: {$e->getMessage()}", 0, $e);
        }
    }

    private function buildQuery(AssumptionRequest $request): string
    {
        $tableName = $this->connection->quoteIdentifier($request->getTableName());
        $fieldName = $this->connection->quoteIdentifier($request->getFieldName());

        $sql = "SELECT {$fieldName} FROM {$tableName}";

        // Add WHERE clause
        $whereParts = [];

        foreach ($request->getWhereClause() as $whereField => $whereValue) {
            [$whereTable, $whereFieldName] = explode('.', $whereField);

            // Ensure WHERE field belongs to same table (prevent JOIN injection)
            if ($whereTable !== $request->getTableName()) {
                throw new QueryException(
                    "WHERE clause table must match main table: {$whereTable} != {$request->getTableName()}"
                );
            }

            $whereParts[] = $this->connection->quoteIdentifier($whereFieldName) . ' = ?';
        }

        if (!empty($whereParts)) {
            $sql .= ' WHERE ' . implode(' AND ', $whereParts);
        }

        // LIMIT 1 (we only need to check existence)
        $sql .= ' LIMIT 1';

        return $sql;
    }

    private function buildParams(AssumptionRequest $request): array
    {
        $params = [];

        // Add WHERE clause params
        foreach ($request->getWhereClause() as $whereValue) {
            $params[] = $whereValue;
        }

        return $params;
    }

    private function compareValues($actualValue, $expectedValue, string $operator): bool
    {
        return match ($operator) {
            '==' => $actualValue == $expectedValue,  // Loose comparison
            '!=' => $actualValue != $expectedValue,
            '>' => $actualValue > $expectedValue,
            '<' => $actualValue < $expectedValue,
            '>=' => $actualValue >= $expectedValue,
            '<=' => $actualValue <= $expectedValue,
            '%like%' => str_contains((string) $actualValue, (string) $expectedValue),
            'like%' => str_starts_with((string) $actualValue, (string) $expectedValue),
            '%like' => str_ends_with((string) $actualValue, (string) $expectedValue),
            'IS NULL' => $actualValue === null,
            'IS NOT NULL' => $actualValue !== null,
            default => throw new QueryException("Unsupported operator: {$operator}")
        };
    }
}
```

---

## Step 5: Value Objects

### AssumptionRequest.php

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Watch\ValueObject;

final class AssumptionRequest
{
    public function __construct(
        private string $tableName,
        private string $fieldName,
        private mixed $expectedValue,
        private string $operator = '==',
        private array $whereClause = []
    ) {}

    public function getTableName(): string
    {
        return $this->tableName;
    }

    public function getFieldName(): string
    {
        return $this->fieldName;
    }

    public function getFieldPath(): string
    {
        return "{$this->tableName}.{$this->fieldName}";
    }

    public function getExpectedValue(): mixed
    {
        return $this->expectedValue;
    }

    public function getOperator(): string
    {
        return $this->operator;
    }

    public function getWhereClause(): array
    {
        return $this->whereClause;
    }
}
```

### AssumptionResponse.php

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Watch\ValueObject;

final class AssumptionResponse
{
    public function __construct(
        private bool $isMatch,
        private int $matchedRows,
        private mixed $actualValue = null
    ) {}

    public function isMatch(): bool
    {
        return $this->isMatch;
    }

    public function getMatchedRows(): int
    {
        return $this->matchedRows;
    }

    public function getActualValue(): mixed
    {
        return $this->actualValue;
    }
}
```

---

## Step 6: Configuration

### Module Configuration (metadata.php)

```php
<?php

$sMetadataVersion = '2.1';
$aModule = [
    'id' => 'paymentwatch',
    'title' => 'PaymentWatch - E2E Testing Helper',
    'description' => 'Test automation helper for payment component E2E testing',
    'version' => '1.0.0',
    'author' => 'OXID eSales AG',
    'url' => 'https://www.oxid-esales.com',
    'email' => 'info@oxid-esales.com',
    'extend' => [],
    'controllers' => [
        'paymentwatch_assumption' => \OxidSolutionCatalysts\Payments\Watch\Controller\AssumptionController::class
    ],
    'settings' => [
        [
            'group' => 'paymentwatch_main',
            'name' => 'paywatchEnabled',
            'type' => 'bool',
            'value' => false
        ],
        [
            'group' => 'paymentwatch_main',
            'name' => 'paywatchAllowedHosts',
            'type' => 'aarr',
            'value' => []
        ]
    ]
];
```

### Route Configuration (routes.yaml)

```yaml
paymentwatch_assume:
    path: /paymentwatch/assume
    controller: paymentwatch_assumption::assume
    methods: [POST]
    requirements:
        _moduleId: paymentwatch
```

---

## Step 7: Testing

### Unit Test Example

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Watch\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use OxidSolutionCatalysts\Payments\Watch\Service\AssumptionParser;
use OxidSolutionCatalysts\Payments\Watch\Exception\ValidationException;

class AssumptionParserTest extends TestCase
{
    private AssumptionParser $parser;

    protected function setUp(): void
    {
        $this->parser = new AssumptionParser();
    }

    public function testParseValidAssumption(): void
    {
        $body = [
            'assumption' => [
                'osc_payment_transaction.OXSTATUS' => 'completed'
            ]
        ];

        $request = $this->parser->parse($body);

        $this->assertEquals('osc_payment_transaction', $request->getTableName());
        $this->assertEquals('OXSTATUS', $request->getFieldName());
        $this->assertEquals('completed', $request->getExpectedValue());
        $this->assertEquals('==', $request->getOperator());
    }

    public function testParseWithOperator(): void
    {
        $body = [
            'assumption' => [
                'oxorder.OXTOTALORDERSUM' => '100.00',
                'op' => '>='
            ]
        ];

        $request = $this->parser->parse($body);

        $this->assertEquals('>=',$request->getOperator());
    }

    public function testParseWithWhereClause(): void
    {
        $body = [
            'assumption' => [
                'osc_payment_transaction.OXSTATUS' => 'completed',
                'where' => [
                    'osc_payment_transaction.OXID' => 'abc123'
                ]
            ]
        ];

        $request = $this->parser->parse($body);

        $whereClause = $request->getWhereClause();
        $this->assertArrayHasKey('osc_payment_transaction.OXID', $whereClause);
        $this->assertEquals('abc123', $whereClause['osc_payment_transaction.OXID']);
    }

    public function testParseMissingAssumption(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Missing "assumption" key');

        $this->parser->parse([]);
    }

    public function testParseInvalidOperator(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid operator');

        $body = [
            'assumption' => [
                'oxorder.OXID' => '123',
                'op' => 'INVALID'
            ]
        ];

        $this->parser->parse($body);
    }

    public function testParseSQLInjectionAttempt(): void
    {
        $this->expectException(ValidationException::class);

        $body = [
            'assumption' => [
                'oxorder; DROP TABLE users; --' => 'value'
            ]
        ];

        $this->parser->parse($body);
    }
}
```

### Integration Test Example

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Watch\Tests\Integration;

use PHPUnit\Framework\TestCase;
use OxidSolutionCatalysts\Payments\Watch\Controller\AssumptionController;
use Laminas\Diactoros\ServerRequest;

class AssumptionControllerTest extends TestCase
{
    private AssumptionController $controller;

    protected function setUp(): void
    {
        // Set up dependencies (mock or real)
        $this->controller = $this->getContainer()->get(AssumptionController::class);
    }

    public function testAssumeValidRequest(): void
    {
        // Insert test data
        $this->insertTransaction([
            'OXID' => 'test123',
            'OXSTATUS' => 'completed'
        ]);

        // Create request
        $request = new ServerRequest(
            [],  // serverParams
            [],  // uploadedFiles
            '/paymentwatch/assume',
            'POST',
            'php://memory',
            [
                'Content-Type' => 'application/json',
                'X-API-Key' => $this->getTestApiKey()
            ]
        );

        $request->getBody()->write(json_encode([
            'assumption' => [
                'osc_payment_transaction.OXSTATUS' => 'completed',
                'where' => [
                    'osc_payment_transaction.OXID' => 'test123'
                ]
            ]
        ]));

        // Execute
        $response = $this->controller->assume($request);

        // Assert
        $this->assertEquals(200, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        $this->assertTrue($body['assumption']);
        $this->assertEquals(1, $body['matched_rows']);
    }

    public function testAssumeUnauthorized(): void
    {
        $request = new ServerRequest(
            [],
            [],
            '/paymentwatch/assume',
            'POST',
            'php://memory',
            [
                'Content-Type' => 'application/json',
                'X-API-Key' => 'invalid_key'
            ]
        );

        $request->getBody()->write(json_encode([
            'assumption' => [
                'oxorder.OXID' => '123'
            ]
        ]));

        $response = $this->controller->assume($request);

        $this->assertEquals(401, $response->getStatusCode());
    }
}
```

---

## Security Checklist

Before deploying PaymentWatch:

- [ ] Module disabled by default in metadata.php
- [ ] IP whitelist configured (no wildcard `0.0.0.0/0`)
- [ ] API keys generated with `openssl rand -hex 32`
- [ ] SQL identifier validation implemented (no SQL injection)
- [ ] Constant-time API key comparison (`hash_equals`)
- [ ] HTTPS enforced (no plaintext API keys)
- [ ] Rate limiting configured (optional)
- [ ] Audit logging enabled
- [ ] Firewall rules restrict `/paymentwatch/*` to internal network
- [ ] Module NOT active in production

---

## Performance Optimization

### Database Indexes

Add indexes for frequently queried fields:

```sql
-- Transaction lookups
CREATE INDEX idx_pw_transaction_status ON osc_payment_transaction(OXSTATUS);
CREATE INDEX idx_pw_transaction_provider ON osc_payment_transaction(OXPROVIDERORDERID);

-- Contract lookups
CREATE INDEX idx_pw_contract_state ON osc_payment_contract(OXSTATE);
CREATE INDEX idx_pw_contract_user ON osc_payment_contract(OXUSERID);

-- Order lookups
CREATE INDEX idx_pw_order_transstatus ON oxorder(OXTRANSSTATUS);
```

### Query Caching (Optional)

For high-frequency tests, implement Redis caching:

```php
class CachedQueryBuilder extends QueryBuilder
{
    public function __construct(
        Connection $connection,
        private \Redis $redis,
        private int $ttl = 30  // 30 seconds
    ) {
        parent::__construct($connection);
    }

    public function execute(AssumptionRequest $request): AssumptionResponse
    {
        $cacheKey = $this->buildCacheKey($request);

        // Check cache
        $cached = $this->redis->get($cacheKey);
        if ($cached !== false) {
            return unserialize($cached);
        }

        // Execute query
        $response = parent::execute($request);

        // Cache result
        $this->redis->setex($cacheKey, $this->ttl, serialize($response));

        return $response;
    }

    private function buildCacheKey(AssumptionRequest $request): string
    {
        return 'pw:' . md5(json_encode([
            'field' => $request->getFieldPath(),
            'value' => $request->getExpectedValue(),
            'op' => $request->getOperator(),
            'where' => $request->getWhereClause()
        ]));
    }
}
```

---

## Deployment

### Docker Environment

```yaml
# docker-compose.yml
services:
  web:
    image: oxid-esales/shop:7.4
    environment:
      - PAYMENTWATCH_ENABLED=true
      - PAYMENTWATCH_API_KEY=${PAYMENTWATCH_API_KEY}
    networks:
      - test_network

  test_runner:
    image: playwright:latest
    environment:
      - SHOP_URL=http://web
      - PAYMENTWATCH_API_KEY=${PAYMENTWATCH_API_KEY}
    networks:
      - test_network

networks:
  test_network:
    driver: bridge
```

### Environment Variables

```bash
# .env.test
PAYMENTWATCH_ENABLED=true
PAYMENTWATCH_API_KEY=a1b2c3d4e5f6789012345678901234567890123456789012345678901234abcd
```

---

## Next Steps

1. Implement controller and services (Steps 1-4)
2. Add unit tests (Step 7)
3. Configure module (Step 6)
4. Test with Playwright/Cypress (see README.md examples)
5. Deploy to test environment
6. Integrate with CI/CD pipeline

---

**Implementation checklist complete! Start building PaymentWatch module.**
