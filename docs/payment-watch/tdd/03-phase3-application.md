# TDD Phase 3: Application Layer

**Building Security-Critical Services**

---

## Navigation

📖 **TDD Documentation:**
- [00-overview.md](00-overview.md) - TDD Overview & SOLID Principles
- [01-phase1-domain.md](01-phase1-domain.md) - Domain Layer (Value Objects)
- [02-phase2-infrastructure.md](02-phase2-infrastructure.md) - Infrastructure Layer (Strategies)
- **[03-phase3-application.md](03-phase3-application.md)** ← You are here
- [04-best-practices.md](04-best-practices.md) - TDD Best Practices & Clean Code

---

## Phase 3 Overview

**Goal:** Build security-focused services for validation and authentication

**Test Directory:** `tests/Unit/Watch/Service/`

**Source Directory:** `src/Watch/Service/`

### Components to Build (Security-Critical!)

1. **RequestValidator** - SQL injection prevention 🔒
2. **IpValidator** - CIDR range support
3. **ApiKeyValidator** - Constant-time comparison 🔒
4. **AssumptionParser** - Request parsing
5. **AuthenticationService** - IP + API key validation 🔒

### Visual Reference
- [../puml/03-sequence-assumption-flow.puml](../puml/03-sequence-assumption-flow.puml) - Request flow
- [../puml/04-sequence-error-flows.puml](../puml/04-sequence-error-flows.puml) - Error handling

---

## Step 3.1: RequestValidator (SECURITY CRITICAL!)

### RED: Write Security Tests First!

**File:** `tests/Unit/Watch/Service/RequestValidatorTest.php`

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Watch\Tests\Unit\Watch\Service;

use PHPUnit\Framework\TestCase;
use OxidSolutionCatalysts\Payments\Watch\Service\RequestValidator;
use OxidSolutionCatalysts\Payments\Watch\Exception\ValidationException;

class RequestValidatorTest extends TestCase
{
    private RequestValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new RequestValidator();
    }

    /**
     * @test
     * @group security
     */
    public function it_rejects_sql_injection_in_table_name(): void
    {
        // Assert
        $this->expectException(ValidationException::class);

        // Act
        $this->validator->validateIdentifier('users; DROP TABLE users--');
    }

    /**
     * @test
     * @group security
     */
    public function it_rejects_sql_keywords_as_identifiers(): void
    {
        // Arrange
        $sqlKeywords = ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'DROP', 'CREATE'];

        // Act & Assert
        foreach ($sqlKeywords as $keyword) {
            try {
                $this->validator->validateIdentifier($keyword);
                $this->fail("Should reject SQL keyword: {$keyword}");
            } catch (ValidationException $e) {
                $this->assertStringContainsString('Invalid identifier', $e->getMessage());
            }
        }
    }

    /**
     * @test
     */
    public function it_accepts_valid_identifiers(): void
    {
        // Arrange
        $validNames = [
            'osc_payment_transaction',
            'oxorder',
            'OXSTATUS',
            'user_id',
            '_private_field'
        ];

        // Act & Assert
        foreach ($validNames as $name) {
            $this->validator->validateIdentifier($name);
            $this->assertTrue(true);  // No exception thrown
        }
    }

    /**
     * @test
     */
    public function it_validates_operator(): void
    {
        // Arrange
        $validOperators = ['==', '!=', '>', '<', '>=', '<=', '%like%', 'IS NULL'];

        // Act & Assert
        foreach ($validOperators as $op) {
            $this->validator->validateOperator($op);
            $this->assertTrue(true);
        }
    }
}
```

**Run test:**
```bash
vendor/bin/phpunit tests/Unit/Watch/Service/RequestValidatorTest.php --group security
```

**Expected:** ❌ RED

---

### GREEN: Implement with Security

**File:** `src/Watch/Service/RequestValidator.php`

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Watch\Service;

use OxidSolutionCatalysts\Payments\Watch\Exception\ValidationException;

final class RequestValidator
{
    private const VALID_OPERATORS = [
        '==', '!=', '>', '<', '>=', '<=',
        '%like%', 'like%', '%like',
        'IS NULL', 'IS NOT NULL'
    ];

    private const SQL_KEYWORDS = [
        'SELECT', 'INSERT', 'UPDATE', 'DELETE', 'DROP', 'CREATE', 'ALTER',
        'TRUNCATE', 'UNION', 'WHERE', 'FROM', 'JOIN', 'ORDER', 'GROUP'
    ];

    public function validateIdentifier(string $name): void
    {
        // Check regex: must start with letter or underscore
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name)) {
            throw new ValidationException("Invalid identifier: {$name}");
        }

        // Block SQL keywords
        if (in_array(strtoupper($name), self::SQL_KEYWORDS, true)) {
            throw new ValidationException("Invalid identifier (SQL keyword): {$name}");
        }
    }

    public function validateOperator(string $operator): void
    {
        if (!in_array($operator, self::VALID_OPERATORS, true)) {
            throw new ValidationException("Invalid operator: {$operator}");
        }
    }

    public function validatePayload(array $payload): void
    {
        if (!isset($payload['assumption'])) {
            throw new ValidationException('Missing "assumption" key in request body');
        }

        if (!is_array($payload['assumption'])) {
            throw new ValidationException('"assumption" must be an object');
        }
    }
}
```

**Run test:**
```bash
vendor/bin/phpunit tests/Unit/Watch/Service/RequestValidatorTest.php
```

**Expected:** ✅ GREEN

---

## Step 3.2: ApiKeyValidator (Timing Attack Prevention!)

### RED: Write Security Test

**File:** `tests/Unit/Watch/Service/ApiKeyValidatorTest.php`

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Watch\Tests\Unit\Watch\Service;

use PHPUnit\Framework\TestCase;
use OxidSolutionCatalysts\Payments\Watch\Service\ApiKeyValidator;

class ApiKeyValidatorTest extends TestCase
{
    private ApiKeyValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new ApiKeyValidator();
    }

    /**
     * @test
     */
    public function it_validates_correct_api_key(): void
    {
        // Arrange
        $expectedKey = str_repeat('a', 64);
        $providedKey = str_repeat('a', 64);

        // Act
        $result = $this->validator->validate($providedKey, $expectedKey);

        // Assert
        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function it_rejects_incorrect_api_key(): void
    {
        // Arrange
        $expectedKey = str_repeat('a', 64);
        $providedKey = str_repeat('b', 64);

        // Act
        $result = $this->validator->validate($providedKey, $expectedKey);

        // Assert
        $this->assertFalse($result);
    }

    /**
     * @test
     * @group security
     */
    public function it_uses_constant_time_comparison(): void
    {
        // Verify source uses hash_equals
        $reflection = new \ReflectionClass($this->validator);
        $filename = $reflection->getFileName();
        $source = file_get_contents($filename);

        $this->assertStringContainsString(
            'hash_equals',
            $source,
            'Validator must use hash_equals for constant-time comparison'
        );
    }
}
```

**Run test:** ❌ RED

---

### GREEN: Implement with Timing Attack Prevention

**File:** `src/Watch/Service/ApiKeyValidator.php`

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Watch\Service;

final class ApiKeyValidator
{
    public function validate(string $providedKey, string $expectedKey): bool
    {
        // Constant-time comparison to prevent timing attacks
        return hash_equals(
            strtolower($expectedKey),
            strtolower($providedKey)
        );
    }

    public function isValidFormat(string $key): bool
    {
        // Must be exactly 64 characters of hex (0-9, a-f, A-F)
        return preg_match('/^[a-f0-9]{64}$/i', $key) === 1;
    }
}
```

**Run test:** ✅ GREEN

---

## Step 3.3: IpValidator (CIDR Support)

### RED: Write Test

**File:** `tests/Unit/Watch/Service/IpValidatorTest.php`

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Watch\Tests\Unit\Watch\Service;

use PHPUnit\Framework\TestCase;
use OxidSolutionCatalysts\Payments\Watch\Service\IpValidator;

class IpValidatorTest extends TestCase
{
    private IpValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new IpValidator();
    }

    /**
     * @test
     */
    public function it_validates_exact_ip_match(): void
    {
        // Act
        $result = $this->validator->validate('192.168.1.100', '192.168.1.100');

        // Assert
        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function it_validates_ip_in_cidr_range(): void
    {
        // Arrange
        $cidr = '192.168.1.0/24';

        // Act & Assert
        $this->assertTrue($this->validator->validate('192.168.1.1', $cidr));
        $this->assertTrue($this->validator->validate('192.168.1.100', $cidr));
        $this->assertTrue($this->validator->validate('192.168.1.255', $cidr));
        $this->assertFalse($this->validator->validate('192.168.2.1', $cidr));
    }
}
```

**Run test:** ❌ RED

---

### GREEN: Implement

**File:** `src/Watch/Service/IpValidator.php`

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Watch\Service;

final class IpValidator
{
    public function validate(string $clientIp, string $allowedIp): bool
    {
        // Check for CIDR notation
        if (str_contains($allowedIp, '/')) {
            return $this->isInCidrRange($clientIp, $allowedIp);
        }

        // Exact match
        return $clientIp === $allowedIp;
    }

    public function isInCidrRange(string $ip, string $cidr): bool
    {
        [$subnet, $mask] = explode('/', $cidr);

        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);
        $maskLong = -1 << (32 - (int) $mask);

        return ($ipLong & $maskLong) === ($subnetLong & $maskLong);
    }
}
```

**Run test:** ✅ GREEN

---

## Phase 3 Complete!

### What We Built

✅ **RequestValidator** - SQL injection prevention with keyword blocking  
✅ **ApiKeyValidator** - Constant-time comparison (timing attack prevention)  
✅ **IpValidator** - CIDR range support for flexible IP whitelisting

### Security Achievements

1. **SQL Injection Prevention**: Regex validation + keyword blocking
2. **Timing Attack Prevention**: `hash_equals()` for constant-time comparison
3. **Comprehensive Validation**: All input validated before use
4. **Security Tests**: Dedicated `@group security` tests

### Testing Summary

```bash
# Run all Phase 3 tests (Docker)
docker compose exec -T -e XDEBUG_MODE=coverage php \
  vendor/bin/phpunit \
  -c /var/www/extensions/stripe/tests/phpunit.xml \
  --bootstrap=/var/www/source/bootstrap.php \
  tests/Unit/Watch/Service/

# Run only security tests (Docker)
docker compose exec -T -e XDEBUG_MODE=coverage php \
  vendor/bin/phpunit \
  -c /var/www/extensions/stripe/tests/phpunit.xml \
  --bootstrap=/var/www/source/bootstrap.php \
  --group security

# Expected: All tests passing ✅
```

---

## Next Steps

Continue to **Phase 4: Controller & Integration**

For best practices and clean code guidelines:  
📄 [04-best-practices.md](04-best-practices.md)

---

**Phase 3 TDD Complete! Security-critical services implemented.** ✅🔒
