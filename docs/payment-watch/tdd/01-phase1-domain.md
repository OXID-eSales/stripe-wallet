# TDD Phase 1: Domain Layer

**Building Immutable Value Objects with Zero Dependencies**

---

## Navigation

📖 **TDD Documentation:**
- [00-overview.md](00-overview.md) - TDD Overview & SOLID Principles
- **[01-phase1-domain.md](01-phase1-domain.md)** ← You are here
- [02-phase2-infrastructure.md](02-phase2-infrastructure.md) - Infrastructure Layer (Strategies)
- [03-phase3-application.md](03-phase3-application.md) - Application Layer (Services)
- [04-best-practices.md](04-best-practices.md) - TDD Best Practices & Clean Code

---

## Phase 1 Overview

**Goal:** Build immutable value objects with zero dependencies

**Test Directory:** `tests/Unit/Watch/ValueObject/`

**Source Directory:** `src/Watch/ValueObject/`

### Components to Build

1. **AssumptionRequest** - Holds assumption query data
2. **AssumptionResponse** - Holds query result data
3. **AuthConfig** - Holds authentication configuration

### Visual Reference
- [../puml/02-class-diagram-solid.puml](../puml/02-class-diagram-solid.puml) - Value Objects section

---

## Step 1.1: AssumptionRequest Value Object

### RED: Write Failing Test

**File:** `tests/Unit/Watch/ValueObject/AssumptionRequestTest.php`

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Watch\Tests\Unit\Watch\ValueObject;

use PHPUnit\Framework\TestCase;
use OxidSolutionCatalysts\Payments\Watch\ValueObject\AssumptionRequest;

class AssumptionRequestTest extends TestCase
{
    /**
     * @test
     */
    public function it_creates_valid_assumption_request(): void
    {
        // Arrange
        $tableName = 'osc_payment_transaction';
        $fieldName = 'OXSTATUS';
        $expectedValue = 'completed';
        $operator = '==';
        $whereClause = ['osc_payment_transaction.OXID' => 'abc123'];

        // Act
        $request = new AssumptionRequest(
            $tableName,
            $fieldName,
            $expectedValue,
            $operator,
            $whereClause
        );

        // Assert
        $this->assertEquals($tableName, $request->getTableName());
        $this->assertEquals($fieldName, $request->getFieldName());
        $this->assertEquals($expectedValue, $request->getExpectedValue());
        $this->assertEquals($operator, $request->getOperator());
        $this->assertEquals($whereClause, $request->getWhereClause());
    }

    /**
     * @test
     */
    public function it_builds_field_path(): void
    {
        // Arrange
        $request = new AssumptionRequest(
            'osc_payment_transaction',
            'OXSTATUS',
            'completed'
        );

        // Act
        $fieldPath = $request->getFieldPath();

        // Assert
        $this->assertEquals('osc_payment_transaction.OXSTATUS', $fieldPath);
    }

    /**
     * @test
     */
    public function it_uses_default_operator_when_not_provided(): void
    {
        // Arrange & Act
        $request = new AssumptionRequest(
            'oxorder',
            'OXID',
            '123'
        );

        // Assert
        $this->assertEquals('==', $request->getOperator());
    }

    /**
     * @test
     */
    public function it_is_immutable(): void
    {
        // Arrange
        $request = new AssumptionRequest(
            'oxorder',
            'OXID',
            '123'
        );

        // Assert: No setters should exist
        $this->assertFalse(
            method_exists($request, 'setTableName'),
            'AssumptionRequest should be immutable'
        );
        $this->assertFalse(
            method_exists($request, 'setExpectedValue'),
            'AssumptionRequest should be immutable'
        );
    }
}
```

**Run test:**
```bash
vendor/bin/phpunit tests/Unit/Watch/ValueObject/AssumptionRequestTest.php
```

**Expected:** ❌ RED (class doesn't exist yet)

---

### GREEN: Implement Minimal Code

**File:** `src/Watch/ValueObject/AssumptionRequest.php`

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Watch\ValueObject;

final class AssumptionRequest
{
    public function __construct(
        private readonly string $tableName,
        private readonly string $fieldName,
        private readonly mixed $expectedValue,
        private readonly string $operator = '==',
        private readonly array $whereClause = []
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
        return $this->tableName . '.' . $this->fieldName;
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

**Run test:**
```bash
vendor/bin/phpunit tests/Unit/Watch/ValueObject/AssumptionRequestTest.php
```

**Expected:** ✅ GREEN

---

### REFACTOR: Improve (if needed)

**No refactoring needed** - code is already clean and minimal.

✅ **Single Responsibility**: Only holds assumption data  
✅ **Immutability**: `readonly` properties  
✅ **Type Safety**: Strict types declared  
✅ **Clean Code**: Clear, descriptive names

---

## Step 1.2: AssumptionResponse Value Object

### RED: Write Failing Test

**File:** `tests/Unit/Watch/ValueObject/AssumptionResponseTest.php`

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Watch\Tests\Unit\Watch\ValueObject;

use PHPUnit\Framework\TestCase;
use OxidSolutionCatalysts\Payments\Watch\ValueObject\AssumptionResponse;

class AssumptionResponseTest extends TestCase
{
    /**
     * @test
     */
    public function it_creates_successful_match_response(): void
    {
        // Arrange & Act
        $response = new AssumptionResponse(
            isMatch: true,
            matchedRows: 1,
            actualValue: 'completed',
            queryTimeMs: 12.5
        );

        // Assert
        $this->assertTrue($response->isMatch());
        $this->assertEquals(1, $response->getMatchedRows());
        $this->assertEquals('completed', $response->getActualValue());
        $this->assertEquals(12.5, $response->getQueryTimeMs());
    }

    /**
     * @test
     */
    public function it_creates_failed_match_response(): void
    {
        // Arrange & Act
        $response = new AssumptionResponse(
            isMatch: false,
            matchedRows: 0,
            actualValue: 'pending',
            queryTimeMs: 8.3
        );

        // Assert
        $this->assertFalse($response->isMatch());
        $this->assertEquals(0, $response->getMatchedRows());
        $this->assertEquals('pending', $response->getActualValue());
    }

    /**
     * @test
     */
    public function it_handles_null_actual_value(): void
    {
        // Arrange & Act
        $response = new AssumptionResponse(
            isMatch: false,
            matchedRows: 0,
            actualValue: null,
            queryTimeMs: 5.0
        );

        // Assert
        $this->assertNull($response->getActualValue());
    }

    /**
     * @test
     */
    public function it_is_immutable(): void
    {
        // Arrange
        $response = new AssumptionResponse(true, 1, 'value', 10.0);

        // Assert: No setters
        $this->assertFalse(method_exists($response, 'setIsMatch'));
        $this->assertFalse(method_exists($response, 'setActualValue'));
    }

    /**
     * @test
     */
    public function it_converts_to_array_for_json_response(): void
    {
        // Arrange
        $response = new AssumptionResponse(
            isMatch: true,
            matchedRows: 1,
            actualValue: 'completed',
            queryTimeMs: 12.567
        );

        // Act
        $array = $response->toArray();

        // Assert
        $this->assertEquals([
            'assumption' => true,
            'matched_rows' => 1,
            'query_time_ms' => 12.57  // Rounded to 2 decimals
        ], $array);
    }

    /**
     * @test
     */
    public function it_includes_actual_value_when_assumption_false(): void
    {
        // Arrange
        $response = new AssumptionResponse(
            isMatch: false,
            matchedRows: 0,
            actualValue: 'pending',
            queryTimeMs: 10.0
        );

        // Act
        $array = $response->toArray();

        // Assert
        $this->assertArrayHasKey('actual_value', $array);
        $this->assertEquals('pending', $array['actual_value']);
    }
}
```

**Run test:**
```bash
vendor/bin/phpunit tests/Unit/Watch/ValueObject/AssumptionResponseTest.php
```

**Expected:** ❌ RED

---

### GREEN: Implement

**File:** `src/Watch/ValueObject/AssumptionResponse.php`

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Watch\ValueObject;

final class AssumptionResponse
{
    public function __construct(
        private readonly bool $isMatch,
        private readonly int $matchedRows,
        private readonly mixed $actualValue = null,
        private readonly float $queryTimeMs = 0.0
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

    public function getQueryTimeMs(): float
    {
        return $this->queryTimeMs;
    }

    public function toArray(): array
    {
        $data = [
            'assumption' => $this->isMatch,
            'matched_rows' => $this->matchedRows,
            'query_time_ms' => round($this->queryTimeMs, 2)
        ];

        if (!$this->isMatch && $this->actualValue !== null) {
            $data['actual_value'] = $this->actualValue;
        }

        return $data;
    }
}
```

**Run test:**
```bash
vendor/bin/phpunit tests/Unit/Watch/ValueObject/AssumptionResponseTest.php
```

**Expected:** ✅ GREEN

---

## Step 1.3: AuthConfig Value Object

### RED: Write Failing Test

**File:** `tests/Unit/Watch/ValueObject/AuthConfigTest.php`

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Watch\Tests\Unit\Watch\ValueObject;

use PHPUnit\Framework\TestCase;
use OxidSolutionCatalysts\Payments\Watch\ValueObject\AuthConfig;

class AuthConfigTest extends TestCase
{
    /**
     * @test
     */
    public function it_stores_allowed_hosts_configuration(): void
    {
        // Arrange
        $allowedHosts = [
            [
                'ip' => '192.168.1.100',
                'api_key' => 'a1b2c3d4e5f6789012345678901234567890123456789012345678901234abcd',
                'description' => 'Test Server'
            ]
        ];

        // Act
        $config = new AuthConfig($allowedHosts);

        // Assert
        $this->assertEquals($allowedHosts, $config->getAllowedHosts());
    }

    /**
     * @test
     */
    public function it_checks_if_ip_is_allowed(): void
    {
        // Arrange
        $config = new AuthConfig([
            ['ip' => '192.168.1.100', 'api_key' => str_repeat('a', 64)]
        ]);

        // Act & Assert
        $this->assertTrue($config->isIpInWhitelist('192.168.1.100'));
        $this->assertFalse($config->isIpInWhitelist('10.0.0.1'));
    }

    /**
     * @test
     */
    public function it_finds_api_key_for_ip(): void
    {
        // Arrange
        $expectedKey = str_repeat('a', 64);
        $config = new AuthConfig([
            ['ip' => '192.168.1.100', 'api_key' => $expectedKey]
        ]);

        // Act
        $actualKey = $config->getApiKeyForIp('192.168.1.100');

        // Assert
        $this->assertEquals($expectedKey, $actualKey);
    }

    /**
     * @test
     */
    public function it_returns_null_for_unknown_ip(): void
    {
        // Arrange
        $config = new AuthConfig([]);

        // Act
        $key = $config->getApiKeyForIp('192.168.1.100');

        // Assert
        $this->assertNull($key);
    }
}
```

**Run test:**
```bash
vendor/bin/phpunit tests/Unit/Watch/ValueObject/AuthConfigTest.php
```

**Expected:** ❌ RED

---

### GREEN: Implement

**File:** `src/Watch/ValueObject/AuthConfig.php`

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Watch\ValueObject;

final class AuthConfig
{
    public function __construct(
        private readonly array $allowedHosts
    ) {}

    public function getAllowedHosts(): array
    {
        return $this->allowedHosts;
    }

    public function isIpInWhitelist(string $ip): bool
    {
        foreach ($this->allowedHosts as $host) {
            if ($host['ip'] === $ip) {
                return true;
            }
        }

        return false;
    }

    public function getApiKeyForIp(string $ip): ?string
    {
        foreach ($this->allowedHosts as $host) {
            if ($host['ip'] === $ip) {
                return $host['api_key'];
            }
        }

        return null;
    }
}
```

**Run test:**
```bash
vendor/bin/phpunit tests/Unit/Watch/ValueObject/AuthConfigTest.php
```

**Expected:** ✅ GREEN

---

## Phase 1 Complete!

### What We Built

✅ **AssumptionRequest** - Immutable value object for assumption queries  
✅ **AssumptionResponse** - Immutable value object for query results  
✅ **AuthConfig** - Immutable value object for authentication configuration

### Key Achievements

1. **Zero Dependencies**: Pure domain objects with no external dependencies
2. **100% Immutable**: All properties are `readonly`
3. **Type Safe**: Strict type declarations throughout
4. **Fully Tested**: Complete test coverage
5. **SOLID Compliant**: Single Responsibility, clear interfaces

### Testing Summary

```bash
# Run all Phase 1 tests (Docker)
docker compose exec -T -e XDEBUG_MODE=coverage php \
  vendor/bin/phpunit \
  -c /var/www/extensions/stripe/tests/phpunit.xml \
  --bootstrap=/var/www/source/bootstrap.php \
  tests/Unit/Watch/ValueObject/

# Expected output:
# AssumptionRequest
#  ✔ It creates valid assumption request
#  ✔ It builds field path
#  ✔ It uses default operator when not provided
#  ✔ It is immutable
#
# AssumptionResponse
#  ✔ It creates successful match response
#  ✔ It creates failed match response
#  ✔ It handles null actual value
#  ✔ It is immutable
#  ✔ It converts to array for json response
#  ✔ It includes actual value when assumption false
#
# AuthConfig
#  ✔ It stores allowed hosts configuration
#  ✔ It checks if ip is allowed
#  ✔ It finds api key for ip
#  ✔ It returns null for unknown ip
```

---

## Next Steps

Continue to **Phase 2: Infrastructure Layer**

📄 [02-phase2-infrastructure.md](02-phase2-infrastructure.md) - Build operator strategies

---

**Phase 1 TDD Complete! Domain layer is solid and ready.** ✅
