# Sprint 1: Domain Layer - Value Objects

**Duration:** 1 week
**Team:** 2 developers
**Goal:** Implement immutable value objects with 100% test coverage

**TDD Phase:** Phase 1 - Domain Layer (inside-out approach)

---

## Sprint Overview

Build the core domain layer with pure PHP value objects following TDD principles. These objects have **zero dependencies** on OXID framework and represent the essential data structures for PaymentWatch.

**Reference:** [TDD Phase 1 Documentation](../tdd/01-phase1-domain.md)

---

## Learning Objectives

- Master RED-GREEN-REFACTOR cycle
- Understand value object pattern
- Practice immutability with `readonly` properties
- Achieve 100% code coverage
- Write clear, descriptive test names

---

## Task 1.1: AssumptionRequest Value Object

### RED: Write Failing Tests

**File:** `tests/Unit/Watch/ValueObject/AssumptionRequestTest.php`

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Watch\Tests\Unit\Watch\ValueObject;

use PHPUnit\Framework\TestCase;
use OxidSolutionCatalysts\Payments\Watch\ValueObject\AssumptionRequest;

/**
 * @group unit
 * @group domain
 * @coversDefaultClass \OxidSolutionCatalysts\Payments\Watch\ValueObject\AssumptionRequest
 */
class AssumptionRequestTest extends TestCase
{
    /**
     * @test
     * @covers ::__construct
     * @covers ::getTableName
     * @covers ::getFieldName
     * @covers ::getExpectedValue
     */
    public function it_creates_valid_assumption_request(): void
    {
        $request = new AssumptionRequest(
            tableName: 'osc_payment_contract',
            fieldName: 'OXSTATE',
            expectedValue: 'completed'
        );

        $this->assertEquals('osc_payment_contract', $request->getTableName());
        $this->assertEquals('OXSTATE', $request->getFieldName());
        $this->assertEquals('completed', $request->getExpectedValue());
    }

    /**
     * @test
     * @covers ::getFieldPath
     */
    public function it_builds_field_path(): void
    {
        $request = new AssumptionRequest(
            tableName: 'osc_payment_contract',
            fieldName: 'OXSTATE',
            expectedValue: 'completed'
        );

        $this->assertEquals(
            'osc_payment_contract.OXSTATE',
            $request->getFieldPath()
        );
    }

    /**
     * @test
     * @covers ::getOperator
     */
    public function it_uses_default_operator_when_not_provided(): void
    {
        $request = new AssumptionRequest(
            tableName: 'oxorder',
            fieldName: 'OXID',
            expectedValue: '123'
        );

        $this->assertEquals('==', $request->getOperator());
    }

    /**
     * @test
     * @covers ::getOperator
     */
    public function it_accepts_custom_operator(): void
    {
        $request = new AssumptionRequest(
            tableName: 'osc_payment_transaction',
            fieldName: 'OXAMOUNT',
            expectedValue: '100.00',
            operator: '>='
        );

        $this->assertEquals('>=', $request->getOperator());
    }

    /**
     * @test
     * @covers ::getWhereClause
     */
    public function it_stores_where_clause(): void
    {
        $whereClause = [
            'osc_payment_contract.OXID' => 'contract-123',
            'osc_payment_contract.OXUSERID' => 'user-456'
        ];

        $request = new AssumptionRequest(
            tableName: 'osc_payment_contract',
            fieldName: 'OXSTATE',
            expectedValue: 'completed',
            operator: '==',
            whereClause: $whereClause
        );

        $this->assertEquals($whereClause, $request->getWhereClause());
    }

    /**
     * @test
     * @covers ::__construct
     */
    public function it_is_immutable(): void
    {
        $request = new AssumptionRequest(
            tableName: 'oxorder',
            fieldName: 'OXID',
            expectedValue: '123'
        );

        // Readonly properties cannot be modified
        $this->expectException(\Error::class);
        $request->tableName = 'other_table'; // This should fail
    }

    /**
     * @test
     * @covers ::getTableName
     */
    public function it_handles_numeric_expected_values(): void
    {
        $request = new AssumptionRequest(
            tableName: 'osc_payment_transaction',
            fieldName: 'OXAMOUNT',
            expectedValue: 99.99
        );

        $this->assertSame(99.99, $request->getExpectedValue());
        $this->assertIsFloat($request->getExpectedValue());
    }

    /**
     * @test
     * @covers ::getExpectedValue
     */
    public function it_handles_null_expected_values(): void
    {
        $request = new AssumptionRequest(
            tableName: 'osc_payment_contract',
            fieldName: 'OXORDERID',
            expectedValue: null,
            operator: 'IS NULL'
        );

        $this->assertNull($request->getExpectedValue());
    }

    /**
     * @test
     * @covers ::getWhereClause
     */
    public function it_returns_empty_array_when_no_where_clause(): void
    {
        $request = new AssumptionRequest(
            tableName: 'oxorder',
            fieldName: 'OXID',
            expectedValue: '123'
        );

        $this->assertEquals([], $request->getWhereClause());
        $this->assertIsArray($request->getWhereClause());
    }
}
```

**Run test (should FAIL):**
```bash
phpunit-watch tests/Unit/Watch/ValueObject/AssumptionRequestTest.php
```

**Expected:** ❌ Class not found error

---

### GREEN: Make Tests Pass

**File:** `src/Watch/ValueObject/AssumptionRequest.php`

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Watch\ValueObject;

/**
 * Value Object representing an assumption request
 *
 * Immutable object containing all data needed to verify a database assumption.
 *
 * @example
 * ```php
 * $request = new AssumptionRequest(
 *     tableName: 'osc_payment_contract',
 *     fieldName: 'OXSTATE',
 *     expectedValue: 'completed',
 *     operator: '==',
 *     whereClause: ['osc_payment_contract.OXID' => 'abc123']
 * );
 * ```
 */
final readonly class AssumptionRequest
{
    /**
     * @param string $tableName Database table name
     * @param string $fieldName Field name within the table
     * @param mixed $expectedValue Expected value to compare against
     * @param string $operator Comparison operator (default: '==')
     * @param array<string, mixed> $whereClause Optional WHERE clause filters
     */
    public function __construct(
        private string $tableName,
        private string $fieldName,
        private mixed $expectedValue,
        private string $operator = '==',
        private array $whereClause = []
    ) {
    }

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

    /**
     * @return array<string, mixed>
     */
    public function getWhereClause(): array
    {
        return $this->whereClause;
    }
}
```

**Run test (should PASS):**
```bash
phpunit-watch tests/Unit/Watch/ValueObject/AssumptionRequestTest.php
```

**Expected:** ✅ All tests passing

---

### REFACTOR: Improve Code

**Add validation (optional for Sprint 1, but good practice):**

```php
// In AssumptionRequest constructor, add:
private function __construct(
    private string $tableName,
    private string $fieldName,
    private mixed $expectedValue,
    private string $operator = '==',
    private array $whereClause = []
) {
    // Validation will be added in Sprint 3 (RequestValidator)
    // For now, keep it simple
}
```

**Run tests again to ensure still green:**
```bash
phpunit-watch tests/Unit/Watch/ValueObject/AssumptionRequestTest.php
```

**Generate coverage:**
```bash
phpunit-watch tests/Unit/Watch/ValueObject/AssumptionRequestTest.php --coverage-text
```

**Expected:** ✅ 100% coverage for AssumptionRequest.php

**Time Estimate:** 2 hours

---

## Task 1.2: AssumptionResponse Value Object

### RED: Write Failing Tests

**File:** `tests/Unit/Watch/ValueObject/AssumptionResponseTest.php`

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Watch\Tests\Unit\Watch\ValueObject;

use PHPUnit\Framework\TestCase;
use OxidSolutionCatalysts\Payments\Watch\ValueObject\AssumptionResponse;

/**
 * @group unit
 * @group domain
 * @coversDefaultClass \OxidSolutionCatalysts\Payments\Watch\ValueObject\AssumptionResponse
 */
class AssumptionResponseTest extends TestCase
{
    /**
     * @test
     * @covers ::__construct
     * @covers ::isMatch
     * @covers ::getMatchedRows
     */
    public function it_creates_successful_response(): void
    {
        $response = new AssumptionResponse(
            isMatch: true,
            matchedRows: 1
        );

        $this->assertTrue($response->isMatch());
        $this->assertEquals(1, $response->getMatchedRows());
    }

    /**
     * @test
     * @covers ::__construct
     * @covers ::isMatch
     * @covers ::getMatchedRows
     * @covers ::getActualValue
     */
    public function it_creates_failed_response_with_actual_value(): void
    {
        $response = new AssumptionResponse(
            isMatch: false,
            matchedRows: 0,
            actualValue: 'pending'
        );

        $this->assertFalse($response->isMatch());
        $this->assertEquals(0, $response->getMatchedRows());
        $this->assertEquals('pending', $response->getActualValue());
    }

    /**
     * @test
     * @covers ::getActualValue
     */
    public function it_returns_null_actual_value_when_not_provided(): void
    {
        $response = new AssumptionResponse(
            isMatch: true,
            matchedRows: 1
        );

        $this->assertNull($response->getActualValue());
    }

    /**
     * @test
     */
    public function it_handles_zero_matched_rows(): void
    {
        $response = new AssumptionResponse(
            isMatch: false,
            matchedRows: 0
        );

        $this->assertEquals(0, $response->getMatchedRows());
        $this->assertIsInt($response->getMatchedRows());
    }

    /**
     * @test
     */
    public function it_is_immutable(): void
    {
        $response = new AssumptionResponse(
            isMatch: true,
            matchedRows: 1
        );

        // Readonly properties cannot be modified
        $this->expectException(\Error::class);
        $response->isMatch = false;
    }
}
```

**Run test:** ❌ Should fail (class not found)

---

### GREEN: Implement

**File:** `src/Watch/ValueObject/AssumptionResponse.php`

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Watch\ValueObject;

/**
 * Value Object representing an assumption response
 *
 * Contains the result of checking an assumption against the database.
 */
final readonly class AssumptionResponse
{
    /**
     * @param bool $isMatch Whether the assumption matched
     * @param int $matchedRows Number of rows that matched
     * @param mixed $actualValue Actual value found (if assumption false)
     */
    public function __construct(
        private bool $isMatch,
        private int $matchedRows,
        private mixed $actualValue = null
    ) {
    }

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

**Run test:** ✅ Should pass

**Time Estimate:** 1.5 hours

---

## Task 1.3: AuthConfig Value Object

### RED: Write Failing Tests

**File:** `tests/Unit/Watch/ValueObject/AuthConfigTest.php`

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Watch\Tests\Unit\Watch\ValueObject;

use PHPUnit\Framework\TestCase;
use OxidSolutionCatalysts\Payments\Watch\ValueObject\AuthConfig;

/**
 * @group unit
 * @group domain
 * @coversDefaultClass \OxidSolutionCatalysts\Payments\Watch\ValueObject\AuthConfig
 */
class AuthConfigTest extends TestCase
{
    /**
     * @test
     * @covers ::__construct
     * @covers ::getAllowedHosts
     */
    public function it_creates_auth_config_with_allowed_hosts(): void
    {
        $allowedHosts = [
            [
                'ip' => '192.168.1.100',
                'api_key' => str_repeat('a', 64),
                'description' => 'Test Server'
            ]
        ];

        $config = new AuthConfig($allowedHosts);

        $this->assertEquals($allowedHosts, $config->getAllowedHosts());
    }

    /**
     * @test
     */
    public function it_handles_multiple_allowed_hosts(): void
    {
        $allowedHosts = [
            [
                'ip' => '192.168.1.100',
                'api_key' => str_repeat('a', 64),
                'description' => 'Server 1'
            ],
            [
                'ip' => '192.168.1.101',
                'api_key' => str_repeat('b', 64),
                'description' => 'Server 2'
            ]
        ];

        $config = new AuthConfig($allowedHosts);

        $this->assertCount(2, $config->getAllowedHosts());
    }

    /**
     * @test
     */
    public function it_handles_empty_allowed_hosts(): void
    {
        $config = new AuthConfig([]);

        $this->assertEmpty($config->getAllowedHosts());
        $this->assertIsArray($config->getAllowedHosts());
    }

    /**
     * @test
     */
    public function it_supports_cidr_notation_in_ip(): void
    {
        $allowedHosts = [
            [
                'ip' => '192.168.1.0/24',
                'api_key' => str_repeat('a', 64),
                'description' => 'Subnet'
            ]
        ];

        $config = new AuthConfig($allowedHosts);

        $this->assertStringContainsString('/', $config->getAllowedHosts()[0]['ip']);
    }
}
```

---

### GREEN: Implement

**File:** `src/Watch/ValueObject/AuthConfig.php`

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Watch\ValueObject;

/**
 * Value Object for authentication configuration
 *
 * Contains list of allowed hosts with their API keys.
 *
 * @example
 * ```php
 * $config = new AuthConfig([
 *     [
 *         'ip' => '192.168.1.100',
 *         'api_key' => 'a1b2c3...',
 *         'description' => 'CI Server'
 *     ]
 * ]);
 * ```
 */
final readonly class AuthConfig
{
    /**
     * @param array<int, array{ip: string, api_key: string, description: string}> $allowedHosts
     */
    public function __construct(
        private array $allowedHosts
    ) {
    }

    /**
     * @return array<int, array{ip: string, api_key: string, description: string}>
     */
    public function getAllowedHosts(): array
    {
        return $this->allowedHosts;
    }
}
```

**Run test:** ✅ Should pass

**Time Estimate:** 1 hour

---

## Task 1.4: Verify Coverage

**Run all domain tests with coverage:**

```bash
phpunit-watch tests/Unit/Watch/ValueObject/ --coverage-html coverage/domain
```

**Open coverage report:**
```bash
open coverage/domain/index.html
# Or: xdg-open coverage/domain/index.html (Linux)
```

**Verify:**
- ✅ AssumptionRequest.php: 100% coverage
- ✅ AssumptionResponse.php: 100% coverage
- ✅ AuthConfig.php: 100% coverage

**Time Estimate:** 30 minutes

---

## Sprint Deliverables

### Code
- ✅ `src/Watch/ValueObject/AssumptionRequest.php`
- ✅ `src/Watch/ValueObject/AssumptionResponse.php`
- ✅ `src/Watch/ValueObject/AuthConfig.php`

### Tests
- ✅ `tests/Unit/Watch/ValueObject/AssumptionRequestTest.php` (9 tests)
- ✅ `tests/Unit/Watch/ValueObject/AssumptionResponseTest.php` (5 tests)
- ✅ `tests/Unit/Watch/ValueObject/AuthConfigTest.php` (4 tests)

### Metrics
- ✅ **Total tests:** 18
- ✅ **Coverage:** 100% for all value objects
- ✅ **Zero dependencies:** Pure PHP, no OXID coupling

---

## Acceptance Criteria

- ✅ All value objects implement `readonly` keyword
- ✅ All tests passing: `phpunit-watch tests/Unit/Watch/ValueObject/`
- ✅ 100% code coverage verified
- ✅ No OXID framework dependencies
- ✅ Clear PHPDoc comments with examples
- ✅ Immutability verified with tests

---

## Sprint Review

**Demo Checklist:**
- [ ] Run all domain tests
- [ ] Show coverage report (100%)
- [ ] Demonstrate immutability (readonly)
- [ ] Explain value object pattern

**Retrospective Questions:**
- How effective was the RED-GREEN-REFACTOR cycle?
- Any challenges with TDD workflow?
- Team comfortable with value objects?
- Improvements for next sprint?

---

## Next Sprint

**Ready for Sprint 2?**

👉 **Continue to [Sprint 2: Infrastructure Layer](sprint-02-infrastructure.md)**

**Prerequisites:**
- ✅ All 18 tests passing
- ✅ 100% domain layer coverage
- ✅ Value objects immutable
- ✅ Zero framework dependencies

---

**Sprint 1 Status:** ⏳ Pending

**Last Updated:** 2025-11-12
