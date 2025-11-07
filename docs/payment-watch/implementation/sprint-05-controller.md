# Sprint 5: Controller Layer (HTTP Endpoint)

**Duration:** 1 week
**Team:** 2 developers
**Prerequisites:** Sprint 4 complete (Database Layer with 10 integration tests)

---

## Sprint Overview

### Goal
Implement the **Presentation Layer** (HTTP Controller) that exposes the PaymentWatch API endpoint:
- **AssumptionController** - POST `/paymentwatch/assume` endpoint
- **Error Handling** - 401 (Unauthorized), 400 (Bad Request), 500 (Server Error)
- **Request ID Tracing** - `X-Request-ID` header for distributed tracing
- **Dependency Injection** - Wire all services together
- **Unit Tests** - Test controller with mocked dependencies

### HTTP Endpoint

```
POST /paymentwatch/assume
Content-Type: application/json
X-API-Key: your-secret-api-key

{
  "table": "oxorder",
  "field": "oxordernr",
  "value": "12345",
  "operator": "==",
  "where": {
    "oxstorno": 0
  }
}
```

**Response (200 OK):**
```json
{
  "success": true,
  "message": "Assumption passed",
  "field_path": "oxorder.oxordernr",
  "request_id": "req-abc123"
}
```

**Response (400 Bad Request):**
```json
{
  "success": false,
  "message": "Invalid table name: OxOrder",
  "request_id": "req-abc123"
}
```

**Response (401 Unauthorized):**
```json
{
  "success": false,
  "message": "Authentication failed: Invalid API key",
  "request_id": "req-abc123"
}
```

### Key Deliverables
1. `AssumptionController` - HTTP request handler
2. **Error handling** for all failure cases
3. **Request ID generation** and propagation
4. **Dependency injection** configuration (services.yaml)
5. **Unit tests** with mocked services

---

## Task 5.1: AssumptionController

**Time Estimate:** 4 hours
**TDD Cycle:** RED → GREEN → REFACTOR

### RED Phase: Write Controller Tests

```bash
docker compose exec php bash -c "mkdir -p /var/www/extensions/stripe/tests/Unit/Watch/Controller"

docker compose exec php bash -c "cat > /var/www/extensions/stripe/tests/Unit/Watch/Controller/AssumptionControllerTest.php << 'EOF'
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Unit\Watch\Controller;

use OxidSolutionCatalysts\Payments\Watch\Controller\AssumptionController;
use OxidSolutionCatalysts\Payments\Watch\Application\AssumptionParser;
use OxidSolutionCatalysts\Payments\Watch\Application\RequestValidator;
use OxidSolutionCatalysts\Payments\Watch\Application\AuthenticationService;
use OxidSolutionCatalysts\Payments\Watch\Application\ValidationResult;
use OxidSolutionCatalysts\Payments\Watch\Application\AuthenticationResult;
use OxidSolutionCatalysts\Payments\Watch\Infrastructure\QueryBuilder;
use OxidSolutionCatalysts\Payments\Watch\Infrastructure\AuditLogger;
use OxidSolutionCatalysts\Payments\Watch\ValueObject\AuthConfig;
use OxidSolutionCatalysts\Payments\Watch\ValueObject\AssumptionRequest;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * @covers \OxidSolutionCatalysts\Payments\Watch\Controller\AssumptionController
 */
class AssumptionControllerTest extends TestCase
{
    private AssumptionController \$controller;
    private AssumptionParser \$parser;
    private RequestValidator \$validator;
    private AuthenticationService \$authService;
    private QueryBuilder \$queryBuilder;
    private AuditLogger \$auditLogger;
    private AuthConfig \$authConfig;

    protected function setUp(): void
    {
        \$this->parser = \$this->createMock(AssumptionParser::class);
        \$this->validator = \$this->createMock(RequestValidator::class);
        \$this->authService = \$this->createMock(AuthenticationService::class);
        \$this->queryBuilder = \$this->createMock(QueryBuilder::class);
        \$this->auditLogger = \$this->createMock(AuditLogger::class);
        \$this->authConfig = new AuthConfig('test-key', ['127.0.0.1']);

        \$this->controller = new AssumptionController(
            \$this->parser,
            \$this->validator,
            \$this->authService,
            \$this->queryBuilder,
            \$this->auditLogger,
            \$this->authConfig
        );
    }

    /**
     * @test
     * Successful assumption returns 200 OK
     */
    public function it_returns_200_for_successful_assumption(): void
    {
        \$requestBody = json_encode([
            'table' => 'oxorder',
            'field' => 'oxordernr',
            'value' => '12345',
            'operator' => '==',
        ]);

        \$request = new Request(
            [],
            [],
            [],
            [],
            [],
            ['REMOTE_ADDR' => '127.0.0.1'],
            \$requestBody
        );
        \$request->headers->set('X-API-Key', 'test-key');
        \$request->headers->set('Content-Type', 'application/json');

        // Mock authentication success
        \$this->authService->method('authenticate')
            ->willReturn(new AuthenticationResult(true));

        // Mock parser
        \$assumptionRequest = new AssumptionRequest('oxorder', 'oxordernr', '12345', '==');
        \$this->parser->method('parse')
            ->willReturn(\$assumptionRequest);

        // Mock validator
        \$this->validator->method('validate')
            ->willReturn(new ValidationResult(true));

        // Mock query builder (assumption passes)
        \$this->queryBuilder->method('assumptionMatches')
            ->willReturn(true);

        \$response = \$this->controller->assume(\$request);

        \$this->assertInstanceOf(JsonResponse::class, \$response);
        \$this->assertSame(200, \$response->getStatusCode());

        \$data = json_decode(\$response->getContent(), true);
        \$this->assertTrue(\$data['success']);
        \$this->assertStringContainsString('Assumption passed', \$data['message']);
        \$this->assertArrayHasKey('request_id', \$data);
    }

    /**
     * @test
     * Authentication failure returns 401 Unauthorized
     */
    public function it_returns_401_for_authentication_failure(): void
    {
        \$request = new Request(
            [],
            [],
            [],
            [],
            [],
            ['REMOTE_ADDR' => '192.168.1.100'],
            '{}'
        );
        \$request->headers->set('X-API-Key', 'wrong-key');

        // Mock authentication failure
        \$this->authService->method('authenticate')
            ->willReturn(new AuthenticationResult(false, ['Invalid API key']));

        \$response = \$this->controller->assume(\$request);

        \$this->assertSame(401, \$response->getStatusCode());

        \$data = json_decode(\$response->getContent(), true);
        \$this->assertFalse(\$data['success']);
        \$this->assertStringContainsString('Authentication failed', \$data['message']);
    }

    /**
     * @test
     * Invalid JSON returns 400 Bad Request
     */
    public function it_returns_400_for_invalid_json(): void
    {
        \$request = new Request(
            [],
            [],
            [],
            [],
            [],
            ['REMOTE_ADDR' => '127.0.0.1'],
            'invalid json{'
        );
        \$request->headers->set('X-API-Key', 'test-key');

        // Mock authentication success
        \$this->authService->method('authenticate')
            ->willReturn(new AuthenticationResult(true));

        // Parser throws JsonException
        \$this->parser->method('parse')
            ->willThrowException(new \JsonException('Invalid JSON'));

        \$response = \$this->controller->assume(\$request);

        \$this->assertSame(400, \$response->getStatusCode());

        \$data = json_decode(\$response->getContent(), true);
        \$this->assertFalse(\$data['success']);
        \$this->assertStringContainsString('Invalid JSON', \$data['message']);
    }

    /**
     * @test
     * Validation failure returns 400 Bad Request
     */
    public function it_returns_400_for_validation_failure(): void
    {
        \$requestBody = json_encode([
            'table' => "oxorder'; DROP TABLE oxorder;--",
            'field' => 'oxordernr',
            'value' => '12345',
        ]);

        \$request = new Request(
            [],
            [],
            [],
            [],
            [],
            ['REMOTE_ADDR' => '127.0.0.1'],
            \$requestBody
        );
        \$request->headers->set('X-API-Key', 'test-key');

        // Mock authentication success
        \$this->authService->method('authenticate')
            ->willReturn(new AuthenticationResult(true));

        // Mock parser
        \$assumptionRequest = new AssumptionRequest(
            "oxorder'; DROP TABLE oxorder;--",
            'oxordernr',
            '12345',
            '=='
        );
        \$this->parser->method('parse')
            ->willReturn(\$assumptionRequest);

        // Mock validator (fails)
        \$this->validator->method('validate')
            ->willReturn(new ValidationResult(false, ['Invalid table name']));

        \$response = \$this->controller->assume(\$request);

        \$this->assertSame(400, \$response->getStatusCode());

        \$data = json_decode(\$response->getContent(), true);
        \$this->assertFalse(\$data['success']);
        \$this->assertStringContainsString('Invalid table name', \$data['message']);
    }

    /**
     * @test
     * Failed assumption (no matching rows) returns 200 with success=false
     */
    public function it_returns_200_for_failed_assumption(): void
    {
        \$requestBody = json_encode([
            'table' => 'oxorder',
            'field' => 'oxordernr',
            'value' => '99999',
            'operator' => '==',
        ]);

        \$request = new Request(
            [],
            [],
            [],
            [],
            [],
            ['REMOTE_ADDR' => '127.0.0.1'],
            \$requestBody
        );
        \$request->headers->set('X-API-Key', 'test-key');

        // Mock authentication success
        \$this->authService->method('authenticate')
            ->willReturn(new AuthenticationResult(true));

        // Mock parser
        \$assumptionRequest = new AssumptionRequest('oxorder', 'oxordernr', '99999', '==');
        \$this->parser->method('parse')
            ->willReturn(\$assumptionRequest);

        // Mock validator
        \$this->validator->method('validate')
            ->willReturn(new ValidationResult(true));

        // Mock query builder (assumption fails - no rows found)
        \$this->queryBuilder->method('assumptionMatches')
            ->willReturn(false);

        \$response = \$this->controller->assume(\$request);

        \$this->assertSame(200, \$response->getStatusCode());

        \$data = json_decode(\$response->getContent(), true);
        \$this->assertFalse(\$data['success']);
        \$this->assertStringContainsString('Assumption failed', \$data['message']);
    }

    /**
     * @test
     * Exception returns 500 Internal Server Error
     */
    public function it_returns_500_for_unexpected_exception(): void
    {
        \$request = new Request(
            [],
            [],
            [],
            [],
            [],
            ['REMOTE_ADDR' => '127.0.0.1'],
            '{}'
        );
        \$request->headers->set('X-API-Key', 'test-key');

        // Mock authentication success
        \$this->authService->method('authenticate')
            ->willReturn(new AuthenticationResult(true));

        // Parser throws unexpected exception
        \$this->parser->method('parse')
            ->willThrowException(new \RuntimeException('Database connection failed'));

        \$response = \$this->controller->assume(\$request);

        \$this->assertSame(500, \$response->getStatusCode());

        \$data = json_decode(\$response->getContent(), true);
        \$this->assertFalse(\$data['success']);
        \$this->assertStringContainsString('Internal server error', \$data['message']);
    }

    /**
     * @test
     * Request ID is generated and included in response
     */
    public function it_generates_and_includes_request_id(): void
    {
        \$requestBody = json_encode([
            'table' => 'oxorder',
            'field' => 'oxordernr',
            'value' => '12345',
        ]);

        \$request = new Request(
            [],
            [],
            [],
            [],
            [],
            ['REMOTE_ADDR' => '127.0.0.1'],
            \$requestBody
        );
        \$request->headers->set('X-API-Key', 'test-key');

        // Mock dependencies
        \$this->authService->method('authenticate')
            ->willReturn(new AuthenticationResult(true));
        \$assumptionRequest = new AssumptionRequest('oxorder', 'oxordernr', '12345', '==');
        \$this->parser->method('parse')->willReturn(\$assumptionRequest);
        \$this->validator->method('validate')->willReturn(new ValidationResult(true));
        \$this->queryBuilder->method('assumptionMatches')->willReturn(true);

        \$response = \$this->controller->assume(\$request);

        \$data = json_decode(\$response->getContent(), true);
        \$this->assertArrayHasKey('request_id', \$data);
        \$this->assertMatchesRegularExpression('/^req-[a-f0-9]{13}$/', \$data['request_id']);
    }

    /**
     * @test
     * Custom request ID from X-Request-ID header is preserved
     */
    public function it_uses_custom_request_id_from_header(): void
    {
        \$customRequestId = 'custom-req-id-12345';

        \$requestBody = json_encode([
            'table' => 'oxorder',
            'field' => 'oxordernr',
            'value' => '12345',
        ]);

        \$request = new Request(
            [],
            [],
            [],
            [],
            [],
            ['REMOTE_ADDR' => '127.0.0.1'],
            \$requestBody
        );
        \$request->headers->set('X-API-Key', 'test-key');
        \$request->headers->set('X-Request-ID', \$customRequestId);

        // Mock dependencies
        \$this->authService->method('authenticate')
            ->willReturn(new AuthenticationResult(true));
        \$assumptionRequest = new AssumptionRequest('oxorder', 'oxordernr', '12345', '==');
        \$this->parser->method('parse')->willReturn(\$assumptionRequest);
        \$this->validator->method('validate')->willReturn(new ValidationResult(true));
        \$this->queryBuilder->method('assumptionMatches')->willReturn(true);

        \$response = \$this->controller->assume(\$request);

        \$data = json_decode(\$response->getContent(), true);
        \$this->assertSame(\$customRequestId, \$data['request_id']);
    }

    /**
     * @test
     * Missing API key header returns 401
     */
    public function it_returns_401_for_missing_api_key(): void
    {
        \$request = new Request(
            [],
            [],
            [],
            [],
            [],
            ['REMOTE_ADDR' => '127.0.0.1'],
            '{}'
        );
        // No X-API-Key header

        \$response = \$this->controller->assume(\$request);

        \$this->assertSame(401, \$response->getStatusCode());

        \$data = json_decode(\$response->getContent(), true);
        \$this->assertFalse(\$data['success']);
        \$this->assertStringContainsString('Missing API key', \$data['message']);
    }
}
EOF"
```

#### Run Tests (Should Fail)
```bash
docker compose exec -T -e XDEBUG_MODE=coverage php vendor/bin/phpunit \
  -c /var/www/extensions/stripe/tests/phpunit.xml \
  --bootstrap=/var/www/source/bootstrap.php \
  --filter AssumptionControllerTest
```

**Expected:** ❌ Class not found

### GREEN Phase: Implement AssumptionController

```bash
docker compose exec php bash -c "mkdir -p /var/www/extensions/stripe/src/Watch/Controller"

docker compose exec php bash -c "cat > /var/www/extensions/stripe/src/Watch/Controller/AssumptionController.php << 'EOF'
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Watch\Controller;

use JsonException;
use OxidSolutionCatalysts\Payments\Watch\Application\AssumptionParser;
use OxidSolutionCatalysts\Payments\Watch\Application\RequestValidator;
use OxidSolutionCatalysts\Payments\Watch\Application\AuthenticationService;
use OxidSolutionCatalysts\Payments\Watch\Infrastructure\QueryBuilder;
use OxidSolutionCatalysts\Payments\Watch\Infrastructure\AuditLogger;
use OxidSolutionCatalysts\Payments\Watch\ValueObject\AuthConfig;
use OxidSolutionCatalysts\Payments\Watch\ValueObject\AssumptionResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Throwable;

/**
 * HTTP Controller for PaymentWatch assumption endpoint
 *
 * Endpoint: POST /paymentwatch/assume
 *
 * Responsibilities:
 * - Extract API key and IP from request
 * - Authenticate request
 * - Parse and validate assumption
 * - Execute query
 * - Return JSON response
 * - Log all requests
 */
final readonly class AssumptionController
{
    public function __construct(
        private AssumptionParser \$parser,
        private RequestValidator \$validator,
        private AuthenticationService \$authService,
        private QueryBuilder \$queryBuilder,
        private AuditLogger \$auditLogger,
        private AuthConfig \$authConfig
    ) {
    }

    /**
     * Handle POST /paymentwatch/assume
     */
    public function assume(Request \$request): JsonResponse
    {
        \$startTime = microtime(true);

        // Generate or extract request ID for tracing
        \$requestId = \$request->headers->get('X-Request-ID') ?? $this->generateRequestId();

        try {
            // Extract authentication credentials
            \$apiKey = \$request->headers->get('X-API-Key', '');
            \$ipAddress = \$request->getClientIp() ?? '';

            // Check for missing API key
            if (\$apiKey === '') {
                return $this->errorResponse(
                    'Missing API key header (X-API-Key)',
                    401,
                    \$requestId
                );
            }

            // Authenticate request
            \$authResult = \$this->authService->authenticate(
                \$apiKey,
                \$ipAddress,
                \$this->authConfig
            );

            if (!\$authResult->isAuthenticated()) {
                \$this->auditLogger->logAuthenticationFailure(\$ipAddress, \$authResult->getFirstError() ?? 'Unknown');

                return $this->errorResponse(
                    'Authentication failed: ' . \$authResult->getFirstError(),
                    401,
                    \$requestId
                );
            }

            // Parse request body
            \$requestBody = \$request->getContent();
            \$assumptionRequest = \$this->parser->parse(\$requestBody);

            // Validate assumption request
            \$validationResult = \$this->validator->validate(\$assumptionRequest);
            if (!\$validationResult->isValid()) {
                return $this->errorResponse(
                    \$validationResult->getFirstError() ?? 'Validation failed',
                    400,
                    \$requestId
                );
            }

            // Execute query
            \$matches = \$this->queryBuilder->assumptionMatches(\$assumptionRequest);

            // Build response
            \$response = new AssumptionResponse(
                \$matches,
                \$matches
                    ? sprintf('Assumption passed: %s', \$assumptionRequest->getFieldPath())
                    : sprintf('Assumption failed: No rows matched %s', \$assumptionRequest->getFieldPath())
            );

            // Log assumption result
            \$duration = microtime(true) - \$startTime;
            \$this->auditLogger->logAssumption(\$assumptionRequest, \$response, \$requestId, \$duration);

            return $this->successResponse(\$response, \$requestId);

        } catch (JsonException \$e) {
            return $this->errorResponse('Invalid JSON: ' . \$e->getMessage(), 400, \$requestId);
        } catch (Throwable \$e) {
            \$this->auditLogger->logException(\$e, \$requestId);

            return $this->errorResponse(
                'Internal server error: ' . \$e->getMessage(),
                500,
                \$requestId
            );
        }
    }

    /**
     * Build success response
     */
    private function successResponse(AssumptionResponse \$response, string \$requestId): JsonResponse
    {
        return new JsonResponse([
            'success' => \$response->isSuccess(),
            'message' => \$response->getMessage(),
            'request_id' => \$requestId,
        ], 200);
    }

    /**
     * Build error response
     */
    private function errorResponse(string \$message, int \$statusCode, string \$requestId): JsonResponse
    {
        return new JsonResponse([
            'success' => false,
            'message' => \$message,
            'request_id' => \$requestId,
        ], \$statusCode);
    }

    /**
     * Generate unique request ID
     *
     * Format: req-{13-char-hex}
     * Example: req-65a1b2c3d4e5f
     */
    private function generateRequestId(): string
    {
        return 'req-' . bin2hex(random_bytes(7));
    }
}
EOF"
```

#### Run Tests (Should Pass)
```bash
docker compose exec -T -e XDEBUG_MODE=coverage php vendor/bin/phpunit \
  -c /var/www/extensions/stripe/tests/phpunit.xml \
  --bootstrap=/var/www/source/bootstrap.php \
  --filter AssumptionControllerTest
```

**Expected:** ✅ 10 tests passing

---

## Task 5.2: Routes Configuration

**Time Estimate:** 30 minutes

### Configure Controller Route

Since PaymentWatch is part of the payment-component (no separate metadata.php), routes are configured in the existing routes.yaml:

```bash
docker compose exec php bash -c "cat >> /var/www/extensions/stripe/routes.yaml << 'EOF'

# PaymentWatch API Endpoint
paymentwatch_assume:
    path: /paymentwatch/assume
    methods: [POST]
    defaults:
        _controller: 'OxidSolutionCatalysts\\Payments\\Watch\\Controller\\AssumptionController::assume'
EOF"
```

---

## Task 5.3: Dependency Injection Configuration

**Time Estimate:** 1 hour

### Create services.yaml for PaymentWatch

```bash
docker compose exec php bash -c "cat > /var/www/extensions/stripe/services-watch.yaml << 'EOF'
services:
    # Value Objects
    OxidSolutionCatalysts\\Payments\\Watch\\ValueObject\\AuthConfig:
        class: OxidSolutionCatalysts\\Payments\\Watch\\ValueObject\\AuthConfig
        arguments:
            \$apiKey: '%paymentwatch.api_key%'
            \$allowedIps: '%paymentwatch.allowed_ips%'

    # Application Services
    OxidSolutionCatalysts\\Payments\\Watch\\Application\\AssumptionParser:
        class: OxidSolutionCatalysts\\Payments\\Watch\\Application\\AssumptionParser

    OxidSolutionCatalysts\\Payments\\Watch\\Application\\RequestValidator:
        class: OxidSolutionCatalysts\\Payments\\Watch\\Application\\RequestValidator

    OxidSolutionCatalysts\\Payments\\Watch\\Application\\ApiKeyValidator:
        class: OxidSolutionCatalysts\\Payments\\Watch\\Application\\ApiKeyValidator

    OxidSolutionCatalysts\\Payments\\Watch\\Application\\IpValidator:
        class: OxidSolutionCatalysts\\Payments\\Watch\\Application\\IpValidator

    OxidSolutionCatalysts\\Payments\\Watch\\Application\\AuthenticationService:
        class: OxidSolutionCatalysts\\Payments\\Watch\\Application\\AuthenticationService
        arguments:
            \$apiKeyValidator: '@OxidSolutionCatalysts\\Payments\\Watch\\Application\\ApiKeyValidator'
            \$ipValidator: '@OxidSolutionCatalysts\\Payments\\Watch\\Application\\IpValidator'

    # Infrastructure Services
    OxidSolutionCatalysts\\Payments\\Watch\\Infrastructure\\SqlSanitizer:
        class: OxidSolutionCatalysts\\Payments\\Watch\\Infrastructure\\SqlSanitizer

    OxidSolutionCatalysts\\Payments\\Watch\\Infrastructure\\OperatorStrategyFactory:
        class: OxidSolutionCatalysts\\Payments\\Watch\\Infrastructure\\OperatorStrategyFactory

    OxidSolutionCatalysts\\Payments\\Watch\\Infrastructure\\QueryBuilder:
        class: OxidSolutionCatalysts\\Payments\\Watch\\Infrastructure\\QueryBuilder
        arguments:
            \$connection: '@doctrine.dbal.default_connection'
            \$sanitizer: '@OxidSolutionCatalysts\\Payments\\Watch\\Infrastructure\\SqlSanitizer'
            \$strategyFactory: '@OxidSolutionCatalysts\\Payments\\Watch\\Infrastructure\\OperatorStrategyFactory'

    OxidSolutionCatalysts\\Payments\\Watch\\Infrastructure\\AuditLogger:
        class: OxidSolutionCatalysts\\Payments\\Watch\\Infrastructure\\AuditLogger
        arguments:
            \$logger: '@logger'

    # Controller
    OxidSolutionCatalysts\\Payments\\Watch\\Controller\\AssumptionController:
        class: OxidSolutionCatalysts\\Payments\\Watch\\Controller\\AssumptionController
        arguments:
            \$parser: '@OxidSolutionCatalysts\\Payments\\Watch\\Application\\AssumptionParser'
            \$validator: '@OxidSolutionCatalysts\\Payments\\Watch\\Application\\RequestValidator'
            \$authService: '@OxidSolutionCatalysts\\Payments\\Watch\\Application\\AuthenticationService'
            \$queryBuilder: '@OxidSolutionCatalysts\\Payments\\Watch\\Infrastructure\\QueryBuilder'
            \$auditLogger: '@OxidSolutionCatalysts\\Payments\\Watch\\Infrastructure\\AuditLogger'
            \$authConfig: '@OxidSolutionCatalysts\\Payments\\Watch\\ValueObject\\AuthConfig'
        public: true
        tags: ['controller.service_arguments']
EOF"
```

### Configuration Parameters

```bash
docker compose exec php bash -c "cat > /var/www/extensions/stripe/config-watch.yaml << 'EOF'
parameters:
    paymentwatch.api_key: '%env(PAYMENTWATCH_API_KEY)%'
    paymentwatch.allowed_ips: '%env(csv:PAYMENTWATCH_ALLOWED_IPS)%'
EOF"
```

### Environment Variables (.env.dist)

```bash
docker compose exec php bash -c "cat >> /var/www/extensions/stripe/.env.dist << 'EOF'

# PaymentWatch Configuration
PAYMENTWATCH_API_KEY=your-secret-api-key-here
PAYMENTWATCH_ALLOWED_IPS=127.0.0.1,10.0.0.0/24
EOF"
```

---

## Sprint 5 Deliverables

### Code Files Created
```
src/Watch/Controller/
└── AssumptionController.php

routes.yaml (updated)
services-watch.yaml
config-watch.yaml
.env.dist (updated)
```

### Test Files Created
```
tests/Unit/Watch/Controller/
└── AssumptionControllerTest.php (10 tests)
```

**Total:** 10 new tests (169 total)

---

## Acceptance Criteria

### Functionality
- ✅ POST /paymentwatch/assume endpoint working
- ✅ Authentication with API key + IP
- ✅ Request ID generation and tracing
- ✅ Error handling (401, 400, 500)
- ✅ JSON responses with proper status codes

### Test Coverage
- ✅ >= 90% coverage for Controller
- ✅ All error paths tested
- ✅ Request ID generation tested

### Configuration
- ✅ Dependency injection configured
- ✅ Routes registered
- ✅ Environment variables documented

---

## Verify Sprint Completion

### Run All Tests
```bash
docker compose exec -T -e XDEBUG_MODE=coverage php vendor/bin/phpunit \
  -c /var/www/extensions/stripe/tests/phpunit.xml \
  --bootstrap=/var/www/source/bootstrap.php \
  --filter Watch
```

**Expected:** ✅ 169 tests passing

### Test Endpoint Manually

```bash
# Test successful assumption
curl -X POST http://localhost/paymentwatch/assume \
  -H "Content-Type: application/json" \
  -H "X-API-Key: your-secret-api-key-here" \
  -d '{
    "table": "oxorder",
    "field": "oxordernr",
    "value": "12345",
    "operator": "=="
  }'

# Expected: 200 OK with success: true/false

# Test authentication failure
curl -X POST http://localhost/paymentwatch/assume \
  -H "Content-Type: application/json" \
  -H "X-API-Key: wrong-key" \
  -d '{}'

# Expected: 401 Unauthorized

# Test validation failure
curl -X POST http://localhost/paymentwatch/assume \
  -H "Content-Type: application/json" \
  -H "X-API-Key: your-secret-api-key-here" \
  -d '{
    "table": "OxOrder",
    "field": "oxordernr",
    "value": "12345"
  }'

# Expected: 400 Bad Request
```

---

## Sprint Review

### Demo Checklist
- [ ] Show controller handling successful request
- [ ] Demonstrate authentication failures (wrong key, wrong IP)
- [ ] Show validation errors (invalid table name)
- [ ] Show request ID tracing in logs
- [ ] Test all operators (==, !=, >, LIKE, etc.)

### Retrospective Questions
1. Should we add rate limiting to prevent abuse?
2. Should response include matched row count?
3. Should we support batch assumptions (multiple at once)?
4. Should we add CORS headers for browser access?

---

## Common Issues

### Issue: Controller not found - 404 error
**Solution:** Clear cache: `docker compose exec php vendor/bin/oe-console oe:cache:clear`
Verify routes.yaml is loaded

### Issue: Dependency injection fails
**Solution:** Check services-watch.yaml syntax
Verify all dependencies are registered
Clear container cache

### Issue: Request ID not included in logs
**Solution:** Check that AuditLogger receives requestId parameter
Verify logger configuration includes context

---

## Next Sprint

**Ready for [Sprint 6: Integration & E2E Testing](sprint-06-testing.md)**

Sprint 6 will implement:
- Real cURL integration tests
- E2E payment flow tests
- SQL injection penetration tests
- Performance benchmarks
- Coverage verification (>= 90%)

---

**Sprint 5 Complete! 🎉**
**Tests:** 10 new tests (169 total)
**HTTP Endpoint:** POST /paymentwatch/assume
**Coverage:** >= 90%
**Next:** Integration & E2E Testing (Week 7)
