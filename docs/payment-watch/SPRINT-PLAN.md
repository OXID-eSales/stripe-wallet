# PaymentWatch Implementation Sprint Plan

**Project Duration:** 12 weeks (3 months)
**Methodology:** Agile Scrum with TDD
**Team Size:** 2-3 developers
**Sprint Length:** 1-2 weeks

---

## Overview

This sprint plan covers the complete implementation of PaymentWatch, from backend PHP/OXID module to JavaScript SDK with full CI/CD automation.

### Project Goals

1. ✅ Build secure, test-driven PaymentWatch module for OXID eShop
2. ✅ Implement JavaScript/TypeScript SDK for E2E testing
3. ✅ Achieve >= 90% test coverage across all components
4. ✅ Set up complete CI/CD pipeline
5. ✅ Publish to NPM with automated releases

---

## Sprint 0: Project Setup & Infrastructure

**Duration:** 1 week
**Team:** Full team
**Goal:** Establish development environment and project structure

### Tasks

#### 0.1 Repository & Module Structure
- [ ] Create module directory: `/source/extensions/stripe/src/Watch/`
- [ ] Create test directories:
  - `/source/extensions/stripe/tests/Unit/Watch/`
  - `/source/extensions/stripe/tests/Integration/Watch/`
  - `/source/extensions/stripe/tests/Acceptance/Watch/`
- [ ] Set up `.gitignore` for PHP artifacts

**Acceptance Criteria:**
- Directory structure matches documentation (01-implementation-guide.md)
- All paths follow namespace convention (`src/Watch/` not `src/Payments/Watch/`)

#### 0.2 Composer Configuration
- [ ] Add autoloading in `composer.json`:
  ```json
  "autoload": {
    "psr-4": {
      "OxidSolutionCatalysts\\Payments\\Watch\\": "./src/Watch"
    }
  }
  ```
- [ ] Install dev dependencies: PHPUnit, mockery, etc.
- [ ] Run `composer dump-autoload`

**Acceptance Criteria:**
- `composer validate` passes
- Namespace autoloading works: `new AssumptionRequest(...)` resolves

#### 0.3 PHPUnit Configuration
- [ ] Create `tests/phpunit.xml` with:
  - Test suites: Unit, Integration, Acceptance
  - Coverage configuration (>= 90% threshold)
  - Bootstrap: `/var/www/source/bootstrap.php`
- [ ] Verify Docker command works:
  ```bash
  docker compose exec -T -e XDEBUG_MODE=coverage php \
    vendor/bin/phpunit \
    -c /var/www/extensions/stripe/tests/phpunit.xml \
    --bootstrap=/var/www/source/bootstrap.php
  ```

**Acceptance Criteria:**
- PHPUnit runs successfully in Docker
- Coverage report generation works
- No permission issues with Docker volumes

#### 0.4 Module Metadata
- [ ] Create `metadata.php`:
  - Module ID: `osc-paymentwatch`
  - Version: 1.0.0
  - Controllers: `paymentwatch_assumption`
  - Settings: `paywatchEnabled`, `paywatchAllowedHosts`
- [ ] Create `routes.yaml`:
  ```yaml
  paymentwatch_assume:
    path: /paymentwatch/assume
    controller: paymentwatch_assumption::assume
    methods: [POST]
  ```

**Acceptance Criteria:**
- Module appears in OXID admin panel
- Module can be activated/deactivated
- Route registered (check `vendor/bin/oe-console debug:router`)

#### 0.5 Development Tools
- [ ] Set up alias for Docker PHPUnit command
- [ ] Configure IDE (PHPStorm/VSCode) for:
  - PSR-4 namespace resolution
  - PHPUnit test runner
  - Xdebug integration

**Deliverables:**
- ✅ Module structure created
- ✅ PHPUnit running in Docker
- ✅ Module registered in OXID
- ✅ Team onboarded to TDD workflow

**Sprint Review:**
- Demo: Run first "hello world" test
- Retrospective: Identify setup blockers

---

## Sprint 1: Domain Layer - Value Objects (TDD Phase 1)

**Duration:** 1 week
**Team:** 2 developers
**Goal:** Implement immutable value objects with 100% test coverage

### Tasks

#### 1.1 AssumptionRequest Value Object

**RED: Write Failing Test**
- [ ] Create `tests/Unit/Watch/ValueObject/AssumptionRequestTest.php`
- [ ] Test: `it_creates_valid_assumption_request()`
- [ ] Test: `it_builds_field_path()` (returns "table.field")
- [ ] Test: `it_uses_default_operator()` (defaults to '==')
- [ ] Test: `it_is_immutable()` (readonly properties)
- [ ] Test: `it_rejects_invalid_table_names()` (validation)
- [ ] Run tests: ❌ FAIL (class not found)

**GREEN: Make Tests Pass**
- [ ] Create `src/Watch/ValueObject/AssumptionRequest.php`:
  ```php
  final class AssumptionRequest {
    public function __construct(
      private readonly string $tableName,
      private readonly string $fieldName,
      private readonly mixed $expectedValue,
      private readonly string $operator = '==',
      private readonly array $whereClause = []
    ) {}
  }
  ```
- [ ] Run tests: ✅ PASS

**REFACTOR: Improve**
- [ ] Extract validation to private methods
- [ ] Add PHPDoc with examples
- [ ] Run tests: ✅ PASS (still green)

**Acceptance Criteria:**
- ✅ 100% code coverage
- ✅ All tests pass
- ✅ Immutable (readonly properties)
- ✅ Clear, descriptive test names

#### 1.2 AssumptionResponse Value Object

**Follow same TDD cycle:**
- [ ] RED: Write failing tests (5+ test cases)
- [ ] GREEN: Implement minimal code
- [ ] REFACTOR: Clean up, improve readability
- [ ] Verify coverage: `phpunit --coverage-html coverage/`

**Key Tests:**
- [ ] Test: `it_creates_successful_response()`
- [ ] Test: `it_creates_failed_response_with_actual_value()`
- [ ] Test: `it_includes_query_time_ms()`
- [ ] Test: `it_includes_matched_rows_count()`

**Acceptance Criteria:**
- ✅ 100% code coverage
- ✅ Properties match API response format (README.md)

#### 1.3 AuthConfig Value Object

**TDD Implementation:**
- [ ] RED: Tests for allowed hosts configuration
- [ ] GREEN: Implement with array of `['ip' => '...', 'api_key' => '...']`
- [ ] REFACTOR: Add validation for IP format, API key length (64 char hex)

**Key Tests:**
- [ ] Test: `it_validates_api_key_format()` (must be 64-char hex)
- [ ] Test: `it_supports_cidr_notation()` (e.g., 192.168.1.0/24)
- [ ] Test: `it_rejects_invalid_ip_addresses()`

**Deliverables:**
- ✅ 3 value objects implemented (AssumptionRequest, AssumptionResponse, AuthConfig)
- ✅ 100% unit test coverage
- ✅ All tests passing in Docker
- ✅ Zero dependencies on OXID framework (pure PHP)

**Sprint Review:**
- Demo: Show value object creation and immutability
- Retrospective: TDD effectiveness, pair programming feedback

---

## Sprint 2: Infrastructure Layer - Operator Strategies (TDD Phase 2)

**Duration:** 1 week
**Team:** 2 developers
**Goal:** Implement Strategy Pattern for operators (Open/Closed Principle)

### Tasks

#### 2.1 OperatorStrategyInterface

**TDD Approach:**
- [ ] RED: Create interface test (verify contract)
- [ ] GREEN: Define interface:
  ```php
  interface OperatorStrategyInterface {
    public function compare(mixed $actual, mixed $expected): bool;
    public function getSupportedOperators(): array;
  }
  ```

**Acceptance Criteria:**
- ✅ Interface follows SOLID (Interface Segregation)
- ✅ Clear method signatures with type hints

#### 2.2 EqualityOperator (==, !=)

**TDD Cycle:**
- [ ] RED: Write tests for equality comparison
  - Test: `it_compares_equal_values()`
  - Test: `it_compares_not_equal_values()`
  - Test: `it_handles_type_coercion()` (loose comparison)
  - Test: `it_returns_supported_operators()` (returns ['==', '!='])
- [ ] GREEN: Implement:
  ```php
  class EqualityOperator implements OperatorStrategyInterface {
    public function compare(mixed $actual, mixed $expected): bool {
      return match ($this->operator) {
        '==' => $actual == $expected,
        '!=' => $actual != $expected,
        default => throw new \InvalidArgumentException()
      };
    }
  }
  ```
- [ ] REFACTOR: Extract operator to constructor

**Acceptance Criteria:**
- ✅ 100% coverage
- ✅ Handles null values correctly

#### 2.3 ComparisonOperator (>, <, >=, <=)

**TDD Implementation:**
- [ ] RED: Tests for numeric comparison
  - Test: `it_compares_greater_than()`
  - Test: `it_compares_less_than_or_equal()`
  - Test: `it_handles_string_comparison()` (lexicographic)
  - Test: `it_throws_exception_for_invalid_operator()`
- [ ] GREEN: Implement with match expression
- [ ] REFACTOR: Add type checking for non-comparable types

#### 2.4 LikeOperator (%like%, like%, %like)

**TDD Implementation:**
- [ ] RED: Tests for pattern matching
  - Test: `it_matches_contains_pattern()` (%like%)
  - Test: `it_matches_starts_with_pattern()` (like%)
  - Test: `it_matches_ends_with_pattern()` (%like)
  - Test: `it_is_case_insensitive()`
- [ ] GREEN: Implement with `str_contains`, `str_starts_with`, `str_ends_with`
- [ ] REFACTOR: Extract pattern parsing

#### 2.5 NullCheckOperator (IS NULL, IS NOT NULL)

**TDD Implementation:**
- [ ] RED: Tests for null checking
  - Test: `it_checks_is_null()`
  - Test: `it_checks_is_not_null()`
  - Test: `it_handles_empty_strings()` (not null!)
  - Test: `it_handles_zero_values()` (not null!)
- [ ] GREEN: Implement with strict null checks (`=== null`)
- [ ] REFACTOR: Add edge case handling

#### 2.6 OperatorStrategyFactory

**TDD Implementation:**
- [ ] RED: Tests for factory pattern
  - Test: `it_creates_operator_by_name()`
  - Test: `it_throws_exception_for_unknown_operator()`
  - Test: `it_registers_custom_operators()` (extensibility)
- [ ] GREEN: Implement factory with registry
- [ ] REFACTOR: Add lazy loading for strategies

**Deliverables:**
- ✅ 5 operator strategies implemented
- ✅ Factory pattern for operator creation
- ✅ 100% unit test coverage
- ✅ Open/Closed Principle demonstrated (can add operators without modifying existing code)

**Sprint Review:**
- Demo: Add new operator without touching existing code
- Retrospective: Strategy pattern effectiveness

---

## Sprint 3: Application Layer - Security Services (TDD Phase 3)

**Duration:** 1 week
**Team:** Full team (security critical!)
**Goal:** Implement security-focused services with attack prevention

### Tasks

#### 3.1 RequestValidator (SQL Injection Prevention) 🔒

**RED: Write Security Tests**
- [ ] Test: `it_rejects_sql_keywords_in_table_names()` (DROP, SELECT, etc.)
- [ ] Test: `it_rejects_special_characters()` (semicolons, quotes)
- [ ] Test: `it_accepts_valid_identifiers()` (alphanumeric + underscore)
- [ ] Test: `it_prevents_injection_via_where_clause()`
- [ ] Test: `it_validates_operator_whitelist()`
- [ ] Run with `--group security` flag

**GREEN: Implement Validation**
```php
class RequestValidator {
  private const SQL_KEYWORDS = ['SELECT', 'INSERT', 'UPDATE', 'DELETE', ...];

  public function validateIdentifier(string $name): bool {
    // Only allow: [a-zA-Z_][a-zA-Z0-9_]*
    if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name)) {
      throw new ValidationException("Invalid identifier: {$name}");
    }

    // Block SQL keywords
    if (in_array(strtoupper($name), self::SQL_KEYWORDS)) {
      throw new ValidationException("SQL keyword not allowed: {$name}");
    }

    return true;
  }
}
```

**REFACTOR: Improve**
- [ ] Extract keyword list to constant
- [ ] Add comprehensive logging for blocked attempts
- [ ] Add rate limiting for repeated attacks

**Security Testing:**
- [ ] Attempt SQL injection: `table'; DROP TABLE users; --`
- [ ] Attempt union attack: `table UNION SELECT * FROM passwords`
- [ ] Attempt comment bypass: `table/* comment */`
- [ ] Verify all blocked with 400 Bad Request

**Acceptance Criteria:**
- ✅ 100% coverage including attack vectors
- ✅ All injection attempts blocked
- ✅ Audit log records all blocked attempts

#### 3.2 ApiKeyValidator (Timing Attack Prevention) 🔒

**RED: Write Timing Attack Tests**
- [ ] Test: `it_validates_correct_api_key()`
- [ ] Test: `it_rejects_incorrect_api_key()`
- [ ] Test: `it_rejects_short_api_key()` (< 64 chars)
- [ ] Test: `it_rejects_non_hex_api_key()`
- [ ] Test: `it_uses_constant_time_comparison()` (security!)

**GREEN: Implement with hash_equals**
```php
class ApiKeyValidator {
  public function validate(string $providedKey, string $expectedKey): bool {
    // Validate format first (fail fast)
    if (!preg_match('/^[a-f0-9]{64}$/i', $providedKey)) {
      throw new ValidationException('Invalid API key format');
    }

    // Constant-time comparison (prevents timing attacks)
    return hash_equals(
      strtolower($expectedKey),
      strtolower($providedKey)
    );
  }
}
```

**REFACTOR: Add**
- [ ] Key rotation support (check against multiple keys)
- [ ] Key expiration (optional timestamp validation)

**Security Testing:**
- [ ] Measure timing for correct vs incorrect keys (should be equal)
- [ ] Attempt timing attack with character-by-character guessing
- [ ] Verify `hash_equals` prevents timing leaks

**Acceptance Criteria:**
- ✅ Uses `hash_equals` for constant-time comparison
- ✅ Timing attack test passes
- ✅ 100% coverage

#### 3.3 IpValidator (CIDR Range Support)

**TDD Implementation:**
- [ ] RED: Tests for IP validation
  - Test: `it_validates_exact_ip_match()`
  - Test: `it_validates_cidr_range()` (192.168.1.0/24)
  - Test: `it_rejects_ip_outside_range()`
  - Test: `it_handles_ipv6_addresses()`
- [ ] GREEN: Implement with `ip2long` and bitwise operations
- [ ] REFACTOR: Add support for multiple ranges

**Acceptance Criteria:**
- ✅ Supports both exact IP and CIDR notation
- ✅ IPv4 and IPv6 support
- ✅ Performance optimized (< 1ms per check)

#### 3.4 AssumptionParser

**TDD Implementation:**
- [ ] RED: Tests for parsing
  - Test: `it_parses_simple_assumption()`
  - Test: `it_parses_assumption_with_operator()`
  - Test: `it_parses_assumption_with_where_clause()`
  - Test: `it_rejects_malformed_json()`
  - Test: `it_validates_all_field_names()` (delegates to RequestValidator)
- [ ] GREEN: Implement JSON parsing with validation
- [ ] REFACTOR: Extract validation to separate concerns

#### 3.5 AuthenticationService

**TDD Implementation (Integration of IP + API Key):**
- [ ] RED: Tests for combined authentication
  - Test: `it_authenticates_valid_ip_and_key()`
  - Test: `it_rejects_invalid_ip()`
  - Test: `it_rejects_invalid_key()`
  - Test: `it_rejects_valid_ip_with_wrong_key()`
- [ ] GREEN: Implement by composing IpValidator and ApiKeyValidator
- [ ] REFACTOR: Add caching for repeated checks

**Deliverables:**
- ✅ 5 security services implemented
- ✅ SQL injection prevention tested
- ✅ Timing attack prevention tested
- ✅ 100% security test coverage
- ✅ Security audit document

**Sprint Review:**
- Demo: Attempt SQL injection (blocked)
- Demo: Timing attack test (constant time)
- Retrospective: Security mindset, threat modeling

**Security Sign-off:**
- [ ] Senior developer review
- [ ] Penetration test (manual attempts)
- [ ] Document attack vectors tested

---

## Sprint 4: Infrastructure Completion - Database Layer (TDD Phase 4)

**Duration:** 1 week
**Team:** 2 developers
**Goal:** Secure database query execution

### Tasks

#### 4.1 SqlSanitizer

**TDD Implementation:**
- [ ] RED: Tests for identifier sanitization
  - Test: `it_quotes_identifiers()`
  - Test: `it_prevents_sql_injection_in_identifiers()`
  - Test: `it_handles_table_prefixes()`
- [ ] GREEN: Implement with DBAL `quoteIdentifier()`
- [ ] REFACTOR: Add caching for repeated sanitization

**Acceptance Criteria:**
- ✅ Uses OXID DBAL for quoting
- ✅ All identifiers properly escaped
- ✅ 100% coverage

#### 4.2 QueryBuilder

**TDD Implementation:**
- [ ] RED: Tests for query construction
  - Test: `it_builds_select_query()`
  - Test: `it_adds_where_clause()`
  - Test: `it_uses_parameter_binding()` (no string interpolation!)
  - Test: `it_limits_to_one_result()`
  - Test: `it_prevents_joins()` (security: no multi-table queries)
- [ ] GREEN: Implement with prepared statements
- [ ] REFACTOR: Extract query parts to builder methods

**Key Implementation:**
```php
class QueryBuilder {
  public function execute(AssumptionRequest $request): AssumptionResponse {
    // Build query
    $sql = "SELECT {$field} FROM {$table}";

    // Add WHERE (parameterized!)
    if ($whereClause) {
      $sql .= " WHERE {$whereField} = ?";
    }

    $sql .= " LIMIT 1";

    // Execute with bound parameters
    $result = $this->connection->fetchAssociative($sql, $params);

    // Compare using operator strategy
    $isMatch = $this->operatorFactory
      ->create($request->getOperator())
      ->compare($actualValue, $expectedValue);

    return new AssumptionResponse($isMatch, ...);
  }
}
```

**Acceptance Criteria:**
- ✅ Always uses prepared statements
- ✅ Never concatenates user input into SQL
- ✅ Properly uses operator strategies
- ✅ 100% coverage

#### 4.3 AuditLogger

**TDD Implementation:**
- [ ] RED: Tests for logging
  - Test: `it_logs_successful_request()`
  - Test: `it_logs_failed_request()`
  - Test: `it_includes_query_time()`
  - Test: `it_sanitizes_sensitive_data()` (API key, IP)
- [ ] GREEN: Implement with PSR-3 logger
- [ ] REFACTOR: Add structured logging (JSON format)

**Log Format:**
```
[2025-11-12 10:30:45] INFO: PaymentWatch request
  IP: 192.168.1.100
  API Key (partial): a1b2c3d4...
  Query: osc_payment_contract.OXSTATE = committed
  Result: assumption=true, rows=1, time=12.5ms
  Request ID: req_abc123xyz
```

**Acceptance Criteria:**
- ✅ All requests logged
- ✅ Sensitive data sanitized
- ✅ Performance impact < 1ms

#### 4.4 Database Integration Tests

**Integration Testing:**
- [ ] Set up test database with schema
- [ ] Create test data fixtures (contracts, transactions, orders)
- [ ] Test QueryBuilder with real database:
  - Test: `it_queries_existing_row()`
  - Test: `it_returns_false_for_non_existent_row()`
  - Test: `it_compares_with_all_operators()`
  - Test: `it_handles_null_values()`
- [ ] Verify query performance (< 20ms on indexed fields)

**Acceptance Criteria:**
- ✅ All integration tests pass
- ✅ No SQL errors
- ✅ Performance benchmarks met

#### 4.5 Database Indexes

**Performance Optimization:**
- [ ] Create indexes on frequently queried fields:
  ```sql
  CREATE INDEX idx_pw_transaction_status
    ON osc_payment_transaction(OXSTATUS);

  CREATE INDEX idx_pw_transaction_provider
    ON osc_payment_transaction(OXPROVIDERORDERID);

  CREATE INDEX idx_pw_contract_state
    ON osc_payment_contract(OXSTATE);

  CREATE INDEX idx_pw_contract_order
    ON osc_payment_contract(OXORDERID);
  ```
- [ ] Run `EXPLAIN` on common queries
- [ ] Benchmark query times (before/after indexes)

**Acceptance Criteria:**
- ✅ Indexed queries < 10ms
- ✅ No full table scans on large tables
- ✅ Index usage verified with EXPLAIN

**Deliverables:**
- ✅ QueryBuilder with prepared statements
- ✅ Audit logging implemented
- ✅ Database integration tests passing
- ✅ Performance indexes created
- ✅ 100% coverage (unit + integration)

**Sprint Review:**
- Demo: Real database query with all operators
- Demo: Performance comparison (with/without indexes)
- Retrospective: Database testing challenges

---

## Sprint 5: Presentation Layer - Controller (TDD Phase 5)

**Duration:** 1 week
**Team:** 2 developers
**Goal:** HTTP endpoint with comprehensive error handling

### Tasks

#### 5.1 AssumptionController Implementation

**TDD Approach:**
- [ ] RED: Write controller tests (mock dependencies)
  - Test: `it_returns_200_for_valid_request()`
  - Test: `it_returns_401_for_invalid_auth()`
  - Test: `it_returns_400_for_invalid_json()`
  - Test: `it_returns_500_for_database_errors()`
  - Test: `it_includes_x_request_id_header()`
  - Test: `it_logs_all_requests()`
- [ ] GREEN: Implement controller (dependency injection)
- [ ] REFACTOR: Extract error handling to middleware

**Key Implementation:**
```php
class AssumptionController {
  public function __construct(
    private AuthenticationService $authService,
    private AssumptionParser $parser,
    private QueryBuilder $queryBuilder,
    private AuditLogger $auditLogger,
    private LoggerInterface $logger
  ) {}

  public function assume(ServerRequestInterface $request): ResponseInterface {
    $startTime = microtime(true);
    $requestId = $request->getHeaderLine('X-Request-ID') ?: uniqid('pwreq_');

    try {
      // 1. Authenticate
      $clientIp = $this->getClientIp($request);
      $apiKey = $request->getHeaderLine('X-API-Key');
      $this->authService->authenticate($clientIp, $apiKey);

      // 2. Parse request
      $body = json_decode((string) $request->getBody(), true);
      $assumptionRequest = $this->parser->parse($body);

      // 3. Execute query
      $result = $this->queryBuilder->execute($assumptionRequest);

      // 4. Audit log
      $this->auditLogger->logRequest($requestId, $clientIp, ...);

      // 5. Return response
      return $this->jsonResponse([
        'assumption' => $result->isMatch(),
        'query_time_ms' => (microtime(true) - $startTime) * 1000,
        'matched_rows' => $result->getMatchedRows()
      ], 200);

    } catch (AuthenticationException $e) {
      return $this->jsonResponse(['error' => 'Unauthorized'], 401);
    } catch (ValidationException $e) {
      return $this->jsonResponse(['error' => $e->getMessage()], 400);
    } catch (\Throwable $e) {
      $this->logger->error('PaymentWatch error', ['trace' => $e->getTraceAsString()]);
      return $this->jsonResponse(['error' => 'Internal server error'], 500);
    }
  }
}
```

**Acceptance Criteria:**
- ✅ All HTTP status codes covered
- ✅ Error responses include helpful messages
- ✅ Request ID included in all responses
- ✅ 100% coverage

#### 5.2 Dependency Injection Configuration

**Configure services.yaml:**
```yaml
services:
  # Value Objects (factories)
  assumption_request_factory:
    class: OxidSolutionCatalysts\Payments\Watch\Factory\AssumptionRequestFactory

  # Services
  paymentwatch.auth_service:
    class: OxidSolutionCatalysts\Payments\Watch\Service\AuthenticationService
    arguments:
      - '@paymentwatch.auth_config'

  paymentwatch.query_builder:
    class: OxidSolutionCatalysts\Payments\Watch\Service\QueryBuilder
    arguments:
      - '@oxid_esales.doctrine.connection'
      - '@paymentwatch.operator_factory'

  # Controller
  paymentwatch.controller:
    class: OxidSolutionCatalysts\Payments\Watch\Controller\AssumptionController
    arguments:
      - '@paymentwatch.auth_service'
      - '@paymentwatch.parser'
      - '@paymentwatch.query_builder'
      - '@paymentwatch.audit_logger'
      - '@logger'
    public: true
```

**Acceptance Criteria:**
- ✅ All services properly injected
- ✅ No manual instantiation in controller
- ✅ Services can be swapped for testing

#### 5.3 Route Testing

**Manual Testing:**
- [ ] Activate module in OXID admin
- [ ] Test endpoint with cURL:
  ```bash
  curl -X POST http://localhost/paymentwatch/assume \
    -H "Content-Type: application/json" \
    -H "X-API-Key: test_key_64_chars..." \
    -d '{
      "assumption": {
        "oxorder.OXID": "test123"
      }
    }'
  ```
- [ ] Verify response format
- [ ] Check audit log written

**Acceptance Criteria:**
- ✅ Route accessible
- ✅ Returns valid JSON
- ✅ HTTP status codes correct

**Deliverables:**
- ✅ Controller implemented
- ✅ Dependency injection configured
- ✅ All HTTP error codes handled
- ✅ Unit tests with mocked dependencies
- ✅ Manual endpoint testing successful

**Sprint Review:**
- Demo: Live cURL request to endpoint
- Demo: Error handling (401, 400, 500)
- Retrospective: DI challenges, OXID framework integration

---

## Sprint 6: Integration & E2E Tests (TDD Phase 6)

**Duration:** 1 week
**Team:** Full team
**Goal:** Comprehensive integration and E2E testing

### Tasks

#### 6.1 Real cURL Integration Tests

**Create Integration Test:**
```php
// tests/Integration/Watch/Controller/AssumptionControllerIntegrationTest.php
class AssumptionControllerIntegrationTest extends TestCase {
  private string $endpoint = 'http://localhost/paymentwatch/assume';
  private string $apiKey;

  protected function setUp(): void {
    parent::setUp();
    $this->apiKey = getenv('PAYMENTWATCH_API_KEY');
    $this->createTestData();  // Insert fixtures
  }

  public function testRealCurlRequest(): void {
    $ch = curl_init($this->endpoint);
    curl_setopt_array($ch, [
      CURLOPT_POST => true,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'X-API-Key: ' . $this->apiKey
      ],
      CURLOPT_POSTFIELDS => json_encode([
        'assumption' => [
          'osc_payment_contract.OXSTATE' => 'pending',
          'where' => [
            'osc_payment_contract.OXID' => 'test-contract-123'
          ]
        ]
      ])
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $this->assertEquals(200, $httpCode);

    $data = json_decode($response, true);
    $this->assertTrue($data['assumption']);
    $this->assertArrayHasKey('query_time_ms', $data);
  }
}
```

**Tests to Implement:**
- [ ] Test: `testSuccessfulAssumption()` - Happy path
- [ ] Test: `testFailedAssumption()` - Condition not met
- [ ] Test: `testAllOperators()` - ==, !=, >, <, >=, <=, %like%, IS NULL
- [ ] Test: `testAuthenticationFailure()` - Invalid API key (401)
- [ ] Test: `testValidationError()` - Malformed JSON (400)
- [ ] Test: `testSqlInjectionAttempt()` - Attack blocked (400)
- [ ] Test: `testTimingAttackResistance()` - Constant time
- [ ] Test: `testConcurrentRequests()` - Race conditions
- [ ] Test: `testPerformance()` - Response time < 50ms

**Acceptance Criteria:**
- ✅ All integration tests pass
- ✅ Real HTTP requests (not mocked)
- ✅ Real database queries (not mocked)
- ✅ Performance benchmarks met

#### 6.2 E2E Payment Flow Test

**Complete Scenario Test:**
```php
class CompletePaymentFlowTest extends TestCase {
  public function testCompletePaymentFlow(): void {
    // 1. Create contract
    $contractId = $this->createTestContract();
    $this->assertContractState($contractId, 'pending');

    // 2. Authorize payment
    $this->authorizePayment($contractId);
    $this->assertContractState($contractId, 'ready_to_commit');

    // 3. Fulfill conditions
    $this->fulfillConditions($contractId);
    $this->assertContractState($contractId, 'committed');

    // 4. Verify order created
    $this->assertOrderLinked($contractId);
    $this->assertOrderStatus($contractId, 'OK');

    // 5. Verify transaction recorded
    $this->assertTransactionExists($contractId);
    $this->assertTransactionStatus($contractId, 'completed');
  }

  private function assertContractState(string $id, string $expectedState): void {
    $result = $this->paymentWatchAssume(
      'osc_payment_contract.OXSTATE',
      $expectedState,
      ['osc_payment_contract.OXID' => $id]
    );

    $this->assertTrue($result['assumption'],
      "Contract state should be {$expectedState}, got: " .
      ($result['actual_value'] ?? 'unknown')
    );
  }
}
```

**Scenarios to Test:**
- [ ] Test: `testCompletePaymentFlow()` - Happy path
- [ ] Test: `testFailedPaymentFlow()` - Fraud check fails
- [ ] Test: `testExpiredContractFlow()` - Timeout
- [ ] Test: `testRefundFlow()` - Refund transaction
- [ ] Test: `testConcurrentPayments()` - Multiple users

**Acceptance Criteria:**
- ✅ Full payment lifecycle tested
- ✅ All state transitions verified
- ✅ Database consistency checked

#### 6.3 Security Validation Tests

**Attack Simulation:**
- [ ] Test: SQL injection attempts (50+ variations)
- [ ] Test: Timing attack measurement
- [ ] Test: Rate limiting (100 requests/second)
- [ ] Test: Authentication bypass attempts
- [ ] Test: Parameter pollution
- [ ] Test: Unicode/encoding attacks

**Acceptance Criteria:**
- ✅ All attacks blocked
- ✅ Audit log records attempts
- ✅ No sensitive data leaked

#### 6.4 Performance Testing

**Benchmark Tests:**
```php
class PerformanceTest extends TestCase {
  public function testQueryPerformance(): void {
    $times = [];

    for ($i = 0; $i < 100; $i++) {
      $start = microtime(true);
      $this->paymentWatchAssume(...);
      $times[] = (microtime(true) - $start) * 1000;
    }

    $avgTime = array_sum($times) / count($times);
    $maxTime = max($times);

    $this->assertLessThan(50, $avgTime, "Average time should be < 50ms");
    $this->assertLessThan(100, $maxTime, "Max time should be < 100ms");
  }
}
```

**Performance Targets:**
- [ ] Average response time: < 50ms
- [ ] P95 response time: < 100ms
- [ ] P99 response time: < 200ms
- [ ] Throughput: > 100 requests/second
- [ ] Database query time: < 20ms

**Acceptance Criteria:**
- ✅ All performance targets met
- ✅ No memory leaks detected
- ✅ No N+1 query problems

#### 6.5 Test Coverage Verification

**Coverage Check:**
```bash
# Generate coverage report
docker compose exec -T -e XDEBUG_MODE=coverage php \
  vendor/bin/phpunit \
  -c /var/www/extensions/stripe/tests/phpunit.xml \
  --bootstrap=/var/www/source/bootstrap.php \
  --coverage-html coverage/ \
  --coverage-clover coverage.xml

# Check thresholds
docker compose exec -T php \
  vendor/bin/phpunit \
  --coverage-text \
  --coverage-clover coverage.xml \
  | grep "Lines:" | awk '{print $2}' | grep -E '^(9[0-9]|100)%'
```

**Coverage Requirements:**
- [ ] Overall coverage: >= 90%
- [ ] Controller coverage: >= 95%
- [ ] Security services: = 100%
- [ ] Value objects: = 100%

**Acceptance Criteria:**
- ✅ All coverage targets met
- ✅ No untested critical paths
- ✅ Coverage report generated

**Deliverables:**
- ✅ 15+ integration tests with real cURL
- ✅ Complete E2E payment flow tested
- ✅ Security attack tests (all blocked)
- ✅ Performance benchmarks (all met)
- ✅ >= 90% overall test coverage
- ✅ Test report generated

**Sprint Review:**
- Demo: Run full E2E test suite
- Demo: Show coverage report
- Demo: Performance benchmark results
- Retrospective: Testing challenges, flaky tests

**Quality Gate:**
- [ ] Senior developer sign-off
- [ ] Security audit passed
- [ ] Performance benchmarks met
- [ ] Ready for production deployment

---

## Sprint 7: JavaScript SDK Development (Week 8-9)

**Duration:** 2 weeks
**Team:** 2 developers (1 focused on TypeScript, 1 on testing)
**Goal:** Build and test JavaScript/TypeScript SDK

### Tasks

#### 7.1 Repository Setup

**Create New Repository:**
- [ ] Create GitHub repository: `OXID-eSales/paymentwatch-client`
- [ ] Initialize Git:
  ```bash
  git init
  git branch -M main
  ```
- [ ] Add `.gitignore` (node_modules, dist, coverage)
- [ ] Create initial README.md

**Acceptance Criteria:**
- ✅ Repository public on GitHub
- ✅ Branch protection rules set (require PR reviews)
- ✅ Issue templates configured

#### 7.2 Node.js Project Initialization

**Setup Project:**
```bash
# Initialize package.json
npm init -y

# Install TypeScript & build tools
npm install --save-dev typescript tsup @types/node

# Install Vitest for testing
npm install --save-dev vitest @vitest/ui @vitest/coverage-v8

# Install linting & formatting
npm install --save-dev eslint @typescript-eslint/parser @typescript-eslint/eslint-plugin prettier eslint-config-prettier
```

**Configure Files:**
- [ ] Create `package.json` with scripts
- [ ] Create `tsconfig.json` (strict mode)
- [ ] Create `vitest.config.ts` (coverage thresholds: 90%)
- [ ] Create `.eslintrc.json`
- [ ] Create `.prettierrc`

**Acceptance Criteria:**
- ✅ `npm run build` works
- ✅ `npm test` runs Vitest
- ✅ `npm run lint` checks code
- ✅ TypeScript strict mode enabled

#### 7.3 TDD: Error Classes

**RED: Write Tests**
```typescript
// tests/unit/errors.test.ts
describe('TimeoutError', () => {
  it('should create TimeoutError with message', () => {
    const error = new TimeoutError('Operation timed out');
    expect(error).toBeInstanceOf(Error);
    expect(error.message).toBe('Operation timed out');
  });

  it('should store last value', () => {
    const error = new TimeoutError('Timeout', 'pending');
    expect(error.lastValue).toBe('pending');
  });
});
```

**GREEN: Implement**
```typescript
// src/errors.ts
export class TimeoutError extends Error {
  public readonly name = 'TimeoutError';
  public readonly lastValue?: any;

  constructor(message: string, lastValue?: any) {
    super(message);
    this.lastValue = lastValue;
    Object.setPrototypeOf(this, TimeoutError.prototype);
  }
}
```

**REFACTOR: Extract Base Class**
```typescript
abstract class PaymentWatchError extends Error {
  constructor(message: string) {
    super(message);
    Object.setPrototypeOf(this, new.target.prototype);
  }
}
```

**Tests to Implement:**
- [ ] TimeoutError (with lastValue)
- [ ] AssertionError (with expected/actual)
- [ ] ValidationError
- [ ] AuthenticationError

**Acceptance Criteria:**
- ✅ All error tests pass
- ✅ 100% coverage for errors.ts
- ✅ Proper prototype chain

#### 7.4 TDD: Type Definitions

**Create Types:**
```typescript
// src/types.ts
export interface ClientConfig {
  baseUrl: string;
  apiKey: string;
  timeout?: number;
  requestId?: string;
  fetch?: typeof fetch;
}

export type Operator =
  | '==' | '!='
  | '>' | '<' | '>=' | '<='
  | '%like%' | 'like%' | '%like'
  | 'IS NULL' | 'IS NOT NULL';

export interface AssumptionOptions {
  operator?: Operator;
  whereClause?: Record<string, any>;
}

export interface WaitForOptions extends AssumptionOptions {
  timeout?: number;
  interval?: number;
  backoff?: boolean;
  maxInterval?: number;
}

export interface AssumptionResult {
  assumption: boolean;
  query_time_ms: number;
  matched_rows: number;
  actual_value?: any;
  expected_value?: any;
}
```

**Tests:**
- [ ] Type definitions compile
- [ ] All operators type-safe
- [ ] Config validation works

**Acceptance Criteria:**
- ✅ TypeScript compilation passes
- ✅ No `any` types (except where necessary)
- ✅ Exported types documented

#### 7.5 TDD: Retry Logic

**RED: Write Tests**
```typescript
describe('retryWithBackoff', () => {
  it('should succeed on first attempt', async () => {
    const fn = vi.fn().mockResolvedValue('success');
    const result = await retryWithBackoff(fn, {
      timeout: 5000,
      interval: 100,
      shouldRetry: () => false
    });
    expect(result).toBe('success');
    expect(fn).toHaveBeenCalledTimes(1);
  });

  it('should retry until success', async () => {
    const fn = vi.fn()
      .mockResolvedValueOnce('pending')
      .mockResolvedValueOnce('pending')
      .mockResolvedValue('completed');

    const result = await retryWithBackoff(fn, {
      timeout: 10000,
      interval: 100,
      shouldRetry: (r) => r === 'pending'
    });

    expect(result).toBe('completed');
    expect(fn).toHaveBeenCalledTimes(3);
  });

  it('should use exponential backoff', async () => {
    // ... test backoff timing
  });
});
```

**GREEN: Implement**
```typescript
// src/utils/retry.ts
export async function retryWithBackoff<T>(
  fn: () => Promise<T>,
  options: RetryOptions<T>
): Promise<T> {
  const { timeout, interval, backoff, maxInterval, shouldRetry } = options;
  const startTime = Date.now();
  let currentInterval = interval;

  while (Date.now() - startTime < timeout) {
    const result = await fn();
    if (!shouldRetry(result)) {
      return result;
    }

    await sleep(currentInterval);

    if (backoff) {
      currentInterval = Math.min(currentInterval * 2, maxInterval);
    }
  }

  throw new TimeoutError(`Timeout after ${timeout}ms`);
}
```

**Acceptance Criteria:**
- ✅ Retry tests pass
- ✅ Exponential backoff works
- ✅ Timeout throws error
- ✅ 100% coverage

#### 7.6 TDD: PaymentWatchClient

**RED: Write Client Tests**
```typescript
describe('PaymentWatchClient', () => {
  let client: PaymentWatchClient;
  let fetchMock: ReturnType<typeof vi.fn>;

  beforeEach(() => {
    fetchMock = vi.fn();
    global.fetch = fetchMock;

    client = new PaymentWatchClient({
      baseUrl: 'https://shop.test',
      apiKey: 'a'.repeat(64)
    });
  });

  describe('assume()', () => {
    it('should make correct API request', async () => {
      fetchMock.mockResolvedValue({
        ok: true,
        json: async () => ({ assumption: true, query_time_ms: 10, matched_rows: 1 })
      });

      const result = await client.assume(
        'osc_payment_contract.OXSTATE',
        'committed',
        { whereClause: { 'osc_payment_contract.OXID': '123' } }
      );

      expect(result.assumption).toBe(true);
      expect(fetchMock).toHaveBeenCalledWith(
        'https://shop.test/paymentwatch/assume',
        expect.objectContaining({
          method: 'POST',
          headers: expect.objectContaining({
            'X-API-Key': 'a'.repeat(64)
          })
        })
      );
    });
  });

  describe('waitFor()', () => {
    it('should retry until condition met', async () => {
      // ... mock retries
    });
  });

  describe('assertExists()', () => {
    it('should check IS NOT NULL', async () => {
      // ... test
    });
  });

  describe('assertChain()', () => {
    it('should fail fast on first mismatch', async () => {
      // ... test
    });
  });
});
```

**GREEN: Implement Client**
```typescript
// src/client.ts
export class PaymentWatchClient {
  constructor(private config: ClientConfig) {}

  async assume(
    field: string,
    expectedValue: any,
    options: AssumptionOptions = {}
  ): Promise<AssumptionResult> {
    const payload = {
      assumption: {
        [field]: expectedValue,
        ...(options.operator && { op: options.operator }),
        ...(options.whereClause && { where: options.whereClause })
      }
    };

    const response = await this.fetch(payload);
    return await response.json();
  }

  async waitFor(...) { /* ... */ }
  async assertExists(...) { /* ... */ }
  async assertChain(...) { /* ... */ }

  private async fetch(payload: any): Promise<Response> {
    const response = await (this.config.fetch || fetch)(
      `${this.config.baseUrl}/paymentwatch/assume`,
      {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-API-Key': this.config.apiKey,
          ...(this.config.requestId && { 'X-Request-ID': this.config.requestId })
        },
        body: JSON.stringify(payload)
      }
    );

    if (!response.ok) {
      // Handle errors
    }

    return response;
  }
}
```

**Acceptance Criteria:**
- ✅ All client methods tested
- ✅ Error handling tested
- ✅ >= 90% coverage
- ✅ All public APIs documented (TSDoc)

#### 7.7 Build & Package

**Build Configuration:**
```typescript
// tsup.config.ts
import { defineConfig } from 'tsup';

export default defineConfig({
  entry: ['src/index.ts'],
  format: ['cjs', 'esm'],
  dts: true,
  splitting: false,
  sourcemap: true,
  clean: true
});
```

**Verify Build:**
```bash
npm run build

# Check output
ls -la dist/
# Should have:
# - index.cjs (CommonJS)
# - index.mjs (ESM)
# - index.d.ts (TypeScript definitions)
```

**Acceptance Criteria:**
- ✅ Dual module output (CJS + ESM)
- ✅ Type definitions generated
- ✅ Source maps included
- ✅ Package size < 50KB

#### 7.8 Integration Tests

**Test Against Real Server:**
```typescript
// tests/integration/paymentwatch-api.test.ts
describe('PaymentWatch API Integration', () => {
  let client: PaymentWatchClient;

  beforeAll(() => {
    client = new PaymentWatchClient({
      baseUrl: process.env.PAYMENTWATCH_URL!,
      apiKey: process.env.PAYMENTWATCH_API_KEY!
    });
  });

  it('should connect to real server', async () => {
    // Insert test data in database
    const contractId = await insertTestContract();

    // Query via SDK
    const result = await client.assume(
      'osc_payment_contract.OXSTATE',
      'pending',
      { whereClause: { 'osc_payment_contract.OXID': contractId } }
    );

    expect(result.assumption).toBe(true);
  });
});
```

**Acceptance Criteria:**
- ✅ Integration tests pass against real server
- ✅ All operators tested
- ✅ Error scenarios tested

**Deliverables:**
- ✅ TypeScript SDK implemented
- ✅ All unit tests passing (>= 90% coverage)
- ✅ Integration tests passing
- ✅ Dual module build (ESM + CJS)
- ✅ Type definitions generated
- ✅ README with usage examples

**Sprint Review:**
- Demo: Install SDK from local build
- Demo: Use in Playwright test
- Retrospective: TypeScript challenges, testing strategies

---

## Sprint 8: JavaScript SDK CI/CD & Publishing (Week 10)

**Duration:** 1 week
**Team:** 1 developer (DevOps focus)
**Goal:** Automated testing, releases, and NPM publishing

### Tasks

#### 8.1 GitHub Actions: CI Workflow

**Create `.github/workflows/ci.yml`:**
```yaml
name: CI

on:
  push:
    branches: [main, develop]
  pull_request:
    branches: [main, develop]

jobs:
  test:
    name: Test on Node ${{ matrix.node }}
    runs-on: ubuntu-latest

    strategy:
      matrix:
        node: [16, 18, 20]

    steps:
      - uses: actions/checkout@v4

      - name: Setup Node.js
        uses: actions/setup-node@v4
        with:
          node-version: ${{ matrix.node }}
          cache: 'npm'

      - name: Install dependencies
        run: npm ci

      - name: Lint
        run: npm run lint

      - name: Type check
        run: npm run typecheck

      - name: Run tests
        run: npm run test:coverage

      - name: Upload coverage
        uses: codecov/codecov-action@v3
        if: matrix.node == '20'
        with:
          files: ./coverage/lcov.info

      - name: Build
        run: npm run build

      - name: Verify build output
        run: |
          test -f dist/index.cjs
          test -f dist/index.mjs
          test -f dist/index.d.ts
```

**Test CI:**
- [ ] Push to branch, verify CI runs
- [ ] Check all matrix jobs pass (Node 16, 18, 20)
- [ ] Verify coverage uploaded to Codecov

**Acceptance Criteria:**
- ✅ CI runs on every push
- ✅ All Node versions tested
- ✅ Coverage report uploaded
- ✅ Build artifacts verified

#### 8.2 GitHub Actions: Release Workflow

**Create `.github/workflows/release.yml`:**
```yaml
name: Release

on:
  push:
    tags:
      - 'v*'

jobs:
  release:
    runs-on: ubuntu-latest

    steps:
      - uses: actions/checkout@v4

      - name: Setup Node.js
        uses: actions/setup-node@v4
        with:
          node-version: '20'
          registry-url: 'https://registry.npmjs.org'

      - name: Install dependencies
        run: npm ci

      - name: Run tests
        run: npm test

      - name: Build
        run: npm run build

      - name: Publish to NPM
        run: npm publish --access public
        env:
          NODE_AUTH_TOKEN: ${{ secrets.NPM_TOKEN }}

      - name: Create GitHub Release
        uses: actions/create-release@v1
        env:
          GITHUB_TOKEN: ${{ secrets.GITHUB_TOKEN }}
        with:
          tag_name: ${{ github.ref }}
          release_name: Release ${{ github.ref }}
          draft: false
          prerelease: false
```

**Setup NPM Publishing:**
- [ ] Create NPM account
- [ ] Generate NPM token (automation)
- [ ] Add token to GitHub secrets: `NPM_TOKEN`
- [ ] Reserve package name: `@oxid-esales/paymentwatch-client`

**Test Release Process:**
```bash
# Dry run (don't actually publish)
npm publish --dry-run

# Check what files will be included
npm pack
tar -tzf oxid-esales-paymentwatch-client-*.tgz
```

**Acceptance Criteria:**
- ✅ NPM token configured
- ✅ Package name reserved
- ✅ Dry run successful
- ✅ Release workflow tested

#### 8.3 Codecov Integration

**Setup Coverage Reporting:**
- [ ] Sign up for Codecov (free for open source)
- [ ] Connect GitHub repository
- [ ] Add Codecov badge to README:
  ```markdown
  [![codecov](https://codecov.io/gh/OXID-eSales/paymentwatch-client/branch/main/graph/badge.svg)](https://codecov.io/gh/OXID-eSales/paymentwatch-client)
  ```

**Configure Coverage Thresholds:**
```yaml
# codecov.yml
coverage:
  status:
    project:
      default:
        target: 90%
        threshold: 1%
    patch:
      default:
        target: 90%
```

**Acceptance Criteria:**
- ✅ Codecov integrated
- ✅ Coverage badge shows in README
- ✅ Pull requests show coverage changes

#### 8.4 Documentation

**Create Comprehensive README:**
```markdown
# @oxid-esales/paymentwatch-client

Official JavaScript/TypeScript client for PaymentWatch API

## Installation

```bash
npm install --save-dev @oxid-esales/paymentwatch-client
```

## Quick Start

```typescript
import { PaymentWatchClient } from '@oxid-esales/paymentwatch-client';

const client = new PaymentWatchClient({
  baseUrl: 'https://shop.example.com',
  apiKey: process.env.PAYMENTWATCH_API_KEY
});

await client.waitFor(
  'osc_payment_contract.OXSTATE',
  'committed',
  { whereClause: { 'osc_payment_contract.OXID': contractId } }
);
```

## Features

- ✅ TypeScript support
- ✅ Promise-based API
- ✅ Retry logic with backoff
- ✅ Framework agnostic
- ✅ ESM & CommonJS

## API Reference

[Full documentation →](https://docs.oxid-esales.com/paymentwatch)

## License

MIT
```

**Additional Docs:**
- [ ] API reference (auto-generated from TSDoc)
- [ ] Framework integration guides (Playwright, Cypress, Jest)
- [ ] Troubleshooting guide
- [ ] Migration guide (future versions)

**Acceptance Criteria:**
- ✅ README clear and comprehensive
- ✅ Installation instructions work
- ✅ Quick start example runs
- ✅ All links work

#### 8.5 Pre-Release Checklist

**Verify Before v1.0.0:**
- [ ] All tests passing (>= 90% coverage)
- [ ] No TypeScript errors
- [ ] No ESLint errors
- [ ] Build successful
- [ ] Package.json metadata complete:
  - Name, version, description
  - Author, license, repository
  - Keywords for NPM search
- [ ] README badges work (CI, coverage, NPM)
- [ ] CHANGELOG.md created
- [ ] LICENSE file present

**Trial Release:**
```bash
# Test version bump
npm version patch --dry-run

# Test publish (dry run)
npm publish --dry-run

# Actually release (when ready)
npm version patch  # Creates git tag
git push origin main --tags  # Triggers CI/CD
```

**Acceptance Criteria:**
- ✅ All checklist items complete
- ✅ Trial release successful
- ✅ No breaking changes

**Deliverables:**
- ✅ CI/CD pipelines working
- ✅ Automated NPM publishing
- ✅ Coverage reporting integrated
- ✅ Comprehensive documentation
- ✅ Ready for v1.0.0 release

**Sprint Review:**
- Demo: Trigger release with git tag
- Demo: Package appears on NPM
- Demo: Install from NPM and use
- Retrospective: CI/CD challenges, automation benefits

---

## Sprint 9: Documentation & Integration Examples (Week 11)

**Duration:** 1 week
**Team:** Full team
**Goal:** Comprehensive examples and guides

### Tasks

#### 9.1 Playwright Integration Example

**Create Example Repository:**
```bash
mkdir paymentwatch-playwright-example
cd paymentwatch-playwright-example
npm init -y
npm install --save-dev @playwright/test @oxid-esales/paymentwatch-client
```

**Example Test:**
```typescript
// tests/payment-flow.spec.ts
import { test, expect } from '@playwright/test';
import { PaymentWatchClient } from '@oxid-esales/paymentwatch-client';

test.describe('Stripe Payment Flow', () => {
  let paymentWatch: PaymentWatchClient;

  test.beforeEach(async ({ request }) => {
    paymentWatch = new PaymentWatchClient({
      baseUrl: 'https://shop.example.com',
      apiKey: process.env.PAYMENTWATCH_API_KEY!
    });
  });

  test('completes payment successfully', async ({ page }) => {
    // 1. Navigate to checkout
    await page.goto('/checkout');
    await page.click('#payment-method-stripe');
    await page.click('#place-order');

    // 2. Extract contract ID from URL
    await page.waitForURL(/stripe\.com/);
    const contractId = extractContractId(page.url());

    // 3. Verify contract created
    const created = await paymentWatch.assume(
      'osc_payment_contract.OXSTATE',
      'pending',
      { whereClause: { 'osc_payment_contract.OXID': contractId } }
    );
    expect(created.assumption).toBe(true);

    // 4. Complete Stripe payment
    await page.fill('#cardNumber', '4242424242424242');
    await page.fill('#cardExpiry', '12/34');
    await page.fill('#cardCvc', '123');
    await page.click('#submit-payment');

    // 5. Wait for authorization
    await paymentWatch.waitFor(
      'osc_payment_contract.OXSTATE',
      'ready_to_commit',
      {
        whereClause: { 'osc_payment_contract.OXID': contractId },
        timeout: 15000
      }
    );

    // 6. Verify order created
    await paymentWatch.assertExists(
      'osc_payment_contract.OXORDERID',
      { 'osc_payment_contract.OXID': contractId }
    );

    // 7. Verify order completed
    await paymentWatch.waitFor(
      'osc_payment_contract.OXSTATE',
      'committed',
      { whereClause: { 'osc_payment_contract.OXID': contractId } }
    );
  });
});
```

**Documentation:**
- [ ] Create detailed README with setup instructions
- [ ] Add playwright.config.ts example
- [ ] Document environment variables
- [ ] Add troubleshooting section

**Acceptance Criteria:**
- ✅ Example runs successfully
- ✅ All steps documented
- ✅ Can be cloned and run by others

#### 9.2 Cypress Integration Example

**Create Example:**
```javascript
// cypress/support/commands.js
import { PaymentWatchClient } from '@oxid-esales/paymentwatch-client';

let paymentWatchClient;

before(() => {
  paymentWatchClient = new PaymentWatchClient({
    baseUrl: Cypress.env('SHOP_URL'),
    apiKey: Cypress.env('PAYMENTWATCH_API_KEY')
  });
});

Cypress.Commands.add('pwWaitFor', (field, value, options) => {
  return paymentWatchClient.waitFor(field, value, options);
});

// cypress/e2e/payment-flow.cy.js
describe('Payment Flow', () => {
  it('completes Stripe payment', () => {
    cy.visit('/checkout');
    cy.get('#payment-method-stripe').click();
    cy.get('#place-order').click();

    cy.location('href').then((url) => {
      const contractId = extractContractId(url);

      cy.pwWaitFor(
        'osc_payment_contract.OXSTATE',
        'pending',
        {
          whereClause: { 'osc_payment_contract.OXID': contractId },
          timeout: 10000
        }
      );
    });
  });
});
```

**Acceptance Criteria:**
- ✅ Cypress example works
- ✅ Custom commands documented
- ✅ Environment setup explained

#### 9.3 Jest Integration Example

**Create Example:**
```typescript
// __tests__/payment-api.test.ts
import { PaymentWatchClient } from '@oxid-esales/paymentwatch-client';

describe('Payment API Integration', () => {
  let paymentWatch: PaymentWatchClient;

  beforeAll(() => {
    paymentWatch = new PaymentWatchClient({
      baseUrl: process.env.SHOP_URL!,
      apiKey: process.env.PAYMENTWATCH_API_KEY!
    });
  });

  it('should verify contract state', async () => {
    const contractId = await createPaymentContract();

    const result = await paymentWatch.assume(
      'osc_payment_contract.OXSTATE',
      'pending',
      { whereClause: { 'osc_payment_contract.OXID': contractId } }
    );

    expect(result.assumption).toBe(true);
  }, 30000);
});
```

**Acceptance Criteria:**
- ✅ Jest example works
- ✅ Async handling documented
- ✅ Timeout configuration explained

#### 9.4 Reference Implementation Repository

**Create Complete Example:**
- [ ] Repository: `paymentwatch-examples`
- [ ] Structure:
  ```
  paymentwatch-examples/
  ├── playwright/
  │   ├── tests/
  │   └── README.md
  ├── cypress/
  │   ├── e2e/
  │   └── README.md
  ├── jest/
  │   ├── __tests__/
  │   └── README.md
  ├── docker-compose.yml  # Test shop environment
  └── README.md
  ```
- [ ] Docker setup for local testing
- [ ] Seed data scripts
- [ ] Complete E2E scenarios

**Acceptance Criteria:**
- ✅ Can clone and run all examples
- ✅ Docker environment included
- ✅ All frameworks represented

#### 9.5 Video Tutorials (Optional)

**Create Screencasts:**
- [ ] Video 1: "Getting Started with PaymentWatch" (5 min)
- [ ] Video 2: "Writing Your First E2E Test" (10 min)
- [ ] Video 3: "Advanced Patterns: Retry Logic & Assertions" (8 min)
- [ ] Video 4: "Debugging Failed Tests" (7 min)

**Platform:** YouTube, host on OXID channel

**Acceptance Criteria:**
- ✅ Videos published
- ✅ Links in documentation
- ✅ Subtitles/captions added

#### 9.6 Troubleshooting Guide

**Common Issues & Solutions:**

**Issue: 401 Unauthorized**
```markdown
**Cause:** Invalid API key or IP not whitelisted

**Solution:**
1. Verify API key is 64-character hex
2. Check IP whitelist in OXID admin
3. Ensure HTTPS is used (not HTTP)

**Debug:**
```bash
# Check API key format
echo $PAYMENTWATCH_API_KEY | wc -c  # Should be 65 (64 + newline)

# Check IP
curl https://api.ipify.org
```
```

**Document 10+ common issues:**
- [ ] 401 Unauthorized
- [ ] 400 Bad Request (malformed JSON)
- [ ] Timeout errors
- [ ] Connection refused
- [ ] Slow queries
- [ ] Flaky tests
- [ ] TypeScript errors
- [ ] Node version issues
- [ ] Environment variable problems
- [ ] CORS errors (if applicable)

**Acceptance Criteria:**
- ✅ 10+ issues documented
- ✅ Solutions tested
- ✅ Examples provided

**Deliverables:**
- ✅ Playwright example complete
- ✅ Cypress example complete
- ✅ Jest example complete
- ✅ Reference repository published
- ✅ Video tutorials (optional)
- ✅ Comprehensive troubleshooting guide

**Sprint Review:**
- Demo: Run Playwright example
- Demo: Run Cypress example
- Demo: Clone and run reference repository
- Retrospective: Documentation quality, missing information

---

## Sprint 10: Production Readiness & Release (Week 12)

**Duration:** 1 week
**Team:** Full team
**Goal:** Final hardening and v1.0.0 release

### Tasks

#### 10.1 Security Audit

**Penetration Testing:**
- [ ] SQL injection attempts (100+ variations)
  - Table name injection
  - Field name injection
  - WHERE clause injection
  - Operator injection
  - Unicode attacks
- [ ] Authentication bypass attempts
  - API key brute force
  - Timing attacks
  - IP spoofing
  - Header injection
- [ ] Denial of Service (DoS)
  - Large payloads
  - Recursive queries
  - Infinite loops
- [ ] Parameter pollution
- [ ] XSS attempts (if HTML rendering)

**Security Checklist:**
- [ ] All user input validated
- [ ] All SQL queries use prepared statements
- [ ] API keys use constant-time comparison
- [ ] Rate limiting configured
- [ ] Audit logging enabled
- [ ] Error messages don't leak sensitive data
- [ ] HTTPS enforced
- [ ] No hardcoded secrets

**External Audit:**
- [ ] Run OWASP ZAP scan
- [ ] Run Burp Suite scan
- [ ] Run SQLMap
- [ ] Code review by security expert

**Acceptance Criteria:**
- ✅ No critical vulnerabilities
- ✅ All attacks blocked
- ✅ Security report generated
- ✅ External audit passed

#### 10.2 Performance Optimization

**Benchmark Current Performance:**
```bash
# Load test
ab -n 1000 -c 10 \
  -H "X-API-Key: test_key..." \
  -H "Content-Type: application/json" \
  -p payload.json \
  http://localhost/paymentwatch/assume
```

**Optimization Tasks:**
- [ ] Add query result caching (Redis)
  - Cache duration: 30 seconds
  - Cache key: hash of query
- [ ] Optimize database queries
  - Review EXPLAIN plans
  - Add missing indexes
  - Avoid N+1 queries
- [ ] Enable HTTP/2
- [ ] Add connection pooling
- [ ] Implement response compression (gzip)

**Performance Targets:**
- [ ] Average response time: < 30ms (down from 50ms)
- [ ] P95 response time: < 75ms (down from 100ms)
- [ ] Throughput: > 200 req/s (up from 100 req/s)
- [ ] Memory usage: < 50MB per process
- [ ] CPU usage: < 5% under normal load

**Acceptance Criteria:**
- ✅ All performance targets met
- ✅ No regressions
- ✅ Load test results documented

#### 10.3 Load Testing

**Simulate Production Load:**
```bash
# Apache Bench
ab -n 10000 -c 100 \
  -H "X-API-Key: test_key..." \
  -p payload.json \
  http://localhost/paymentwatch/assume

# k6 load test
k6 run --vus 100 --duration 60s load-test.js
```

**Load Test Scenarios:**
- [ ] Normal load: 50 req/s for 10 minutes
- [ ] Peak load: 200 req/s for 5 minutes
- [ ] Stress test: 500 req/s for 1 minute
- [ ] Spike test: 0 → 1000 req/s instantly
- [ ] Endurance test: 100 req/s for 1 hour

**Monitor:**
- [ ] Response times
- [ ] Error rates
- [ ] Memory usage
- [ ] CPU usage
- [ ] Database connections
- [ ] Log file sizes

**Acceptance Criteria:**
- ✅ Handles 200 req/s without errors
- ✅ No memory leaks detected
- ✅ No connection pool exhaustion
- ✅ Load test report generated

#### 10.4 Deployment Guide

**Create Deployment Documentation:**

```markdown
# PaymentWatch Production Deployment Guide

## Prerequisites

- OXID eShop 7.0+ installed
- PHP 8.1+
- MySQL 8.0+
- Redis (optional, for caching)

## Step 1: Install Module

```bash
composer require oxid-esales/paymentwatch
vendor/bin/oe-console oe:module:activate osc-paymentwatch
```

## Step 2: Configure Settings

**Admin Panel:**
1. Navigate to Extensions → Modules → PaymentWatch
2. Configure:
   - Enabled: Yes
   - Allowed Hosts:
     ```json
     [
       {
         "ip": "192.168.1.100",
         "api_key": "generated_key_here",
         "description": "CI Server"
       }
     ]
     ```

## Step 3: Generate API Keys

```bash
openssl rand -hex 32
```

## Step 4: Configure Firewall

**Nginx:**
```nginx
location /paymentwatch {
  # Restrict to internal network
  allow 192.168.1.0/24;
  deny all;

  proxy_pass http://backend;
}
```

## Step 5: Enable Caching (Optional)

```php
// config.inc.php
$this->redis = [
  'host' => 'localhost',
  'port' => 6379,
  'timeout' => 2.5,
];
```

## Step 6: Verify Installation

```bash
curl -X POST https://shop.example.com/paymentwatch/assume \
  -H "X-API-Key: your_key" \
  -d '{"assumption": {"oxorder.OXID": "test"}}'
```

## Monitoring

**Key Metrics:**
- Response time (target: < 50ms)
- Error rate (target: < 0.1%)
- Request volume
- Cache hit rate (target: > 80%)

**Recommended Tools:**
- Prometheus + Grafana
- ELK Stack (logs)
- Sentry (error tracking)

## Troubleshooting

See [TROUBLESHOOTING.md](TROUBLESHOOTING.md)

## Rollback

If issues occur:

```bash
vendor/bin/oe-console oe:module:deactivate osc-paymentwatch
```
```

**Acceptance Criteria:**
- ✅ Deployment guide complete
- ✅ All steps tested on staging
- ✅ Rollback procedure documented

#### 10.5 Release Preparation

**Version 1.0.0 Checklist:**
- [ ] All tests passing (>= 90% coverage)
- [ ] Security audit passed
- [ ] Performance benchmarks met
- [ ] Load testing completed
- [ ] Deployment guide written
- [ ] CHANGELOG.md updated
- [ ] README.md finalized
- [ ] API documentation complete
- [ ] Examples working
- [ ] Video tutorials published (optional)

**Create Release Notes:**
```markdown
# PaymentWatch v1.0.0

## 🎉 Initial Release

PaymentWatch enables secure, remote E2E testing of payment workflows in OXID eShop.

### Features

- ✅ RESTful JSON API for database state verification
- ✅ IP + API key authentication
- ✅ Support for 11 comparison operators
- ✅ SQL injection prevention
- ✅ Timing attack prevention
- ✅ Comprehensive audit logging
- ✅ >= 90% test coverage

### JavaScript SDK

- ✅ TypeScript support
- ✅ Promise-based API
- ✅ Retry logic with exponential backoff
- ✅ Framework integrations (Playwright, Cypress, Jest)
- ✅ NPM package: `@oxid-esales/paymentwatch-client`

### Documentation

- Server implementation guide
- JavaScript SDK documentation
- E2E test scenarios
- TDD guides
- Integration examples

### Breaking Changes

None (initial release)

### Migration Guide

None (initial release)

### Known Issues

None

### Contributors

- Development Team
- Security Auditors
- Beta Testers

## Installation

**PHP Module:**
```bash
composer require oxid-esales/paymentwatch
```

**JavaScript SDK:**
```bash
npm install --save-dev @oxid-esales/paymentwatch-client
```

## Resources

- Documentation: https://docs.oxid-esales.com/paymentwatch
- GitHub: https://github.com/OXID-eSales/paymentwatch
- NPM: https://www.npmjs.com/package/@oxid-esales/paymentwatch-client
- Examples: https://github.com/OXID-eSales/paymentwatch-examples
```

**Acceptance Criteria:**
- ✅ Release notes complete
- ✅ All links work
- ✅ Version numbers consistent

#### 10.6 Release Execution

**Release Day Checklist:**

**PHP Module:**
- [ ] Tag version: `git tag v1.0.0`
- [ ] Push tag: `git push origin v1.0.0`
- [ ] Create GitHub release with notes
- [ ] Publish to Packagist (Composer)
- [ ] Update OXID module repository

**JavaScript SDK:**
- [ ] Tag version: `git tag v1.0.0`
- [ ] Push tag: `git push origin v1.0.0` (triggers CI/CD)
- [ ] Verify NPM package published
- [ ] Create GitHub release

**Documentation:**
- [ ] Publish to documentation site
- [ ] Update main OXID docs
- [ ] Create announcement blog post

**Communication:**
- [ ] Post to OXID forum
- [ ] Tweet from OXID account
- [ ] Email to beta testers
- [ ] Post in developer Slack/Discord

**Monitoring:**
- [ ] Set up alerts for errors
- [ ] Monitor NPM downloads
- [ ] Monitor GitHub issues
- [ ] Check first user feedback

**Acceptance Criteria:**
- ✅ v1.0.0 released
- ✅ Published to all channels
- ✅ Announcement made
- ✅ Monitoring active

**Deliverables:**
- ✅ v1.0.0 released (PHP + JavaScript)
- ✅ Security audit passed
- ✅ Performance optimized
- ✅ Deployment guide complete
- ✅ Release announced
- ✅ Monitoring active

**Sprint Review:**
- Demo: Show released packages
- Demo: Install from NPM
- Demo: Deploy to staging
- Retrospective: Project learnings, successes, challenges

---

## Post-Release Support Plan

### Week 13-14: Stabilization

**Tasks:**
- Monitor for issues
- Respond to user feedback
- Fix critical bugs (if any)
- Update documentation based on questions
- Create FAQ

**Team:** 1 developer on support rotation

---

## Sprint Summary

### Timeline Overview

| Sprint | Duration | Team Size | Focus | Key Deliverable |
|--------|----------|-----------|-------|-----------------|
| Sprint 0 | 1 week | 3 | Setup | Development environment |
| Sprint 1 | 1 week | 2 | Domain | Value Objects (100% coverage) |
| Sprint 2 | 1 week | 2 | Infrastructure | Operator Strategies |
| Sprint 3 | 1 week | 3 | Security | Security Services (critical) |
| Sprint 4 | 1 week | 2 | Database | QueryBuilder & Integration |
| Sprint 5 | 1 week | 2 | Presentation | Controller & HTTP Endpoint |
| Sprint 6 | 1 week | 3 | Testing | Integration & E2E Tests |
| Sprint 7 | 2 weeks | 2 | JavaScript | TypeScript SDK |
| Sprint 8 | 1 week | 1 | DevOps | CI/CD & NPM Publishing |
| Sprint 9 | 1 week | 3 | Docs | Examples & Guides |
| Sprint 10 | 1 week | 3 | Release | Security Audit & v1.0.0 |
| **Total** | **12 weeks** | **2-3** | | **Production-ready v1.0.0** |

### Success Metrics

**Code Quality:**
- ✅ >= 90% test coverage
- ✅ 0 critical security vulnerabilities
- ✅ 0 TypeScript errors
- ✅ 0 ESLint errors

**Performance:**
- ✅ Average response time < 50ms
- ✅ Throughput > 100 req/s
- ✅ P95 response time < 100ms

**Deliverables:**
- ✅ PHP module (OXID)
- ✅ JavaScript SDK (TypeScript)
- ✅ 100% documentation
- ✅ CI/CD automation
- ✅ NPM package published
- ✅ Examples repository
- ✅ Security audit passed

**Adoption:**
- ✅ 10+ beta testers
- ✅ NPM downloads tracked
- ✅ GitHub stars growing
- ✅ Community feedback positive

---

## Risk Management

### Identified Risks

| Risk | Impact | Mitigation |
|------|--------|------------|
| Security vulnerability discovered | High | Continuous security testing, external audit |
| Performance doesn't meet targets | Medium | Early benchmarking, optimization sprint |
| OXID framework changes | Medium | Version pinning, automated tests |
| Team member unavailable | Medium | Knowledge sharing, pair programming |
| NPM package name already taken | Low | Reserve early, alternative names ready |
| TypeScript breaking changes | Low | Pin TypeScript version |
| Integration test flakiness | Medium | Retry logic, better fixtures |

---

## Retrospective Questions (End of Each Sprint)

### What went well?
- TDD process?
- Team collaboration?
- Technical decisions?

### What could be improved?
- Communication?
- Testing approach?
- Documentation?

### Action items for next sprint
- Process improvements
- Technical improvements
- Team improvements

---

**Ready to start Sprint 0!** 🚀

Let's build PaymentWatch with confidence through TDD and Agile practices.
