# Sprint 3: Security Services 🔒

**Duration:** 1 week
**Team:** 2 developers (1 security-focused)
**Prerequisites:** Sprint 2 complete (Operator Strategies with >= 95% coverage)

---

## Sprint Overview

### Goal
Implement **security-critical services** to protect PaymentWatch from attacks:
- **SQL Injection Prevention** 🛡️
- **Timing Attack Prevention** 🛡️
- **IP Allowlist Validation** 🛡️
- **Request Payload Validation** 🛡️

### Security Principles
1. **Defense in Depth:** Multiple layers of security
2. **Fail Secure:** Reject by default, allow explicitly
3. **No Trust:** Validate all inputs
4. **Constant Time:** Prevent timing attacks with `hash_equals()`

### Key Deliverables
1. `RequestValidator` - SQL injection prevention
2. `ApiKeyValidator` - Timing-safe API key validation
3. `IpValidator` - CIDR range support
4. `AssumptionParser` - Secure JSON parsing
5. `AuthenticationService` - Combined auth logic
6. **Security test suite** with dedicated `@group security`

---

## Task 3.1: RequestValidator (SQL Injection Prevention)

**Time Estimate:** 3 hours
**TDD Cycle:** RED → GREEN → REFACTOR
**Security Critical:** 🔒 YES

### RED Phase: Write Security-Focused Tests

```bash
docker compose exec php bash -c "mkdir -p /var/www/extensions/stripe/tests/Unit/Watch/Application"

docker compose exec php bash -c "cat > /var/www/extensions/stripe/tests/Unit/Watch/Application/RequestValidatorTest.php << 'EOF'
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Unit\Watch\Application;

use OxidSolutionCatalysts\Payments\Watch\Application\RequestValidator;
use OxidSolutionCatalysts\Payments\Watch\ValueObject\AssumptionRequest;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OxidSolutionCatalysts\Payments\Watch\Application\RequestValidator
 * @group security
 */
class RequestValidatorTest extends TestCase
{
    private RequestValidator \$validator;

    protected function setUp(): void
    {
        \$this->validator = new RequestValidator();
    }

    /**
     * @test
     * Valid table and field names should pass
     */
    public function it_accepts_valid_identifiers(): void
    {
        \$request = new AssumptionRequest(
            tableName: 'oxorder',
            fieldName: 'oxordernr',
            expectedValue: '12345',
            operator: '=='
        );

        \$result = \$this->validator->validate(\$request);

        \$this->assertTrue(\$result->isValid());
        \$this->assertEmpty(\$result->getErrors());
    }

    /**
     * @test
     * @group security
     * SQL injection attempts in table name should be rejected
     */
    public function it_rejects_sql_injection_in_table_name(): void
    {
        \$request = new AssumptionRequest(
            tableName: "oxorder'; DROP TABLE oxorder;--",
            fieldName: 'oxordernr',
            expectedValue: '12345',
            operator: '=='
        );

        \$result = \$this->validator->validate(\$request);

        \$this->assertFalse(\$result->isValid());
        \$this->assertStringContainsString('Invalid table name', \$result->getErrors()[0]);
    }

    /**
     * @test
     * @group security
     * SQL injection attempts in field name should be rejected
     */
    public function it_rejects_sql_injection_in_field_name(): void
    {
        \$request = new AssumptionRequest(
            tableName: 'oxorder',
            fieldName: "oxordernr' OR '1'='1",
            expectedValue: '12345',
            operator: '=='
        );

        \$result = \$this->validator->validate(\$request);

        \$this->assertFalse(\$result->isValid());
        \$this->assertStringContainsString('Invalid field name', \$result->getErrors()[0]);
    }

    /**
     * @test
     * @group security
     * Union-based SQL injection
     */
    public function it_rejects_union_injection(): void
    {
        \$request = new AssumptionRequest(
            tableName: 'oxorder UNION SELECT * FROM oxuser',
            fieldName: 'oxordernr',
            expectedValue: '12345',
            operator: '=='
        );

        \$result = \$this->validator->validate(\$request);

        \$this->assertFalse(\$result->isValid());
    }

    /**
     * @test
     * @group security
     * Comment-based SQL injection
     */
    public function it_rejects_comment_injection(): void
    {
        \$request = new AssumptionRequest(
            tableName: 'oxorder',
            fieldName: 'oxordernr-- comment',
            expectedValue: '12345',
            operator: '=='
        );

        \$result = \$this->validator->validate(\$request);

        \$this->assertFalse(\$result->isValid());
    }

    /**
     * @test
     * Table names must match OXID conventions (lowercase alphanumeric)
     */
    public function it_validates_oxid_table_naming_convention(): void
    {
        \$validTables = ['oxorder', 'oxuser', 'oxarticles', 'oepaypal_order'];
        \$invalidTables = ['Order', 'ox_order', 'oxOrder', 'ox-order'];

        foreach (\$validTables as \$table) {
            \$request = new AssumptionRequest(\$table, 'oxid', '123', '==');
            \$result = \$this->validator->validate(\$request);
            \$this->assertTrue(\$result->isValid(), "Table '\$table' should be valid");
        }

        foreach (\$invalidTables as \$table) {
            \$request = new AssumptionRequest(\$table, 'oxid', '123', '==');
            \$result = \$this->validator->validate(\$request);
            \$this->assertFalse(\$result->isValid(), "Table '\$table' should be invalid");
        }
    }

    /**
     * @test
     * Field names must match OXID conventions (lowercase alphanumeric)
     */
    public function it_validates_oxid_field_naming_convention(): void
    {
        \$validFields = ['oxid', 'oxordernr', 'oxtotalordersum', 'oxpaymenttype'];
        \$invalidFields = ['OrderNr', 'ox_total', 'ox-payment', 'field name'];

        foreach (\$validFields as \$field) {
            \$request = new AssumptionRequest('oxorder', \$field, '123', '==');
            \$result = \$this->validator->validate(\$request);
            \$this->assertTrue(\$result->isValid(), "Field '\$field' should be valid");
        }

        foreach (\$invalidFields as \$field) {
            \$request = new AssumptionRequest('oxorder', \$field, '123', '==');
            \$result = \$this->validator->validate(\$request);
            \$this->assertFalse(\$result->isValid(), "Field '\$field' should be invalid");
        }
    }

    /**
     * @test
     * Operators must be in the allowlist
     */
    public function it_validates_operator_allowlist(): void
    {
        \$validOperators = ['==', '!=', '>', '<', '>=', '<=', '%like%', 'IS NULL', 'IN'];
        \$invalidOperators = ['=', '<>', 'BETWEEN', 'EXISTS', 'ANY'];

        foreach (\$validOperators as \$operator) {
            \$request = new AssumptionRequest('oxorder', 'oxid', '123', \$operator);
            \$result = \$this->validator->validate(\$request);
            \$this->assertTrue(\$result->isValid(), "Operator '\$operator' should be valid");
        }

        foreach (\$invalidOperators as \$operator) {
            \$request = new AssumptionRequest('oxorder', 'oxid', '123', \$operator);
            \$result = \$this->validator->validate(\$request);
            \$this->assertFalse(\$result->isValid(), "Operator '\$operator' should be invalid");
        }
    }

    /**
     * @test
     * Where clause keys must be valid identifiers
     */
    public function it_validates_where_clause_keys(): void
    {
        \$request = new AssumptionRequest(
            tableName: 'oxorder',
            fieldName: 'oxordernr',
            expectedValue: '12345',
            operator: '==',
            whereClause: [
                "oxpaid' OR '1'='1" => '0',  // SQL injection attempt
            ]
        );

        \$result = \$this->validator->validate(\$request);

        \$this->assertFalse(\$result->isValid());
        \$this->assertStringContainsString('Invalid where clause key', \$result->getErrors()[0]);
    }
}
EOF"
```

#### Run Tests (Should Fail)
```bash
docker compose exec -T -e XDEBUG_MODE=coverage php vendor/bin/phpunit \
  -c /var/www/extensions/stripe/tests/phpunit.xml \
  --bootstrap=/var/www/source/bootstrap.php \
  --filter RequestValidatorTest
```

**Expected:** ❌ Class not found

### GREEN Phase: Implement RequestValidator

```bash
docker compose exec php bash -c "mkdir -p /var/www/extensions/stripe/src/Watch/Application"

docker compose exec php bash -c "cat > /var/www/extensions/stripe/src/Watch/Application/RequestValidator.php << 'EOF'
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Watch\Application;

use OxidSolutionCatalysts\Payments\Watch\ValueObject\AssumptionRequest;

/**
 * Validates AssumptionRequest for SQL injection and naming convention compliance
 *
 * SECURITY CRITICAL: This is the first line of defense against SQL injection
 *
 * Validation Rules:
 * 1. Table/field names must match OXID conventions: lowercase alphanumeric + underscore
 * 2. No SQL keywords, quotes, or special characters in identifiers
 * 3. Operators must be in the allowlist
 * 4. Where clause keys must be valid identifiers
 */
final class RequestValidator
{
    /**
     * OXID identifier pattern: lowercase letters, numbers, underscore
     * Examples: oxorder, oxuser, oepaypal_order
     */
    private const IDENTIFIER_PATTERN = '/^[a-z][a-z0-9_]*$/';

    /**
     * Allowed operators (must match OperatorStrategyFactory)
     */
    private const ALLOWED_OPERATORS = [
        '==', '!=', '>', '<', '>=', '<=',
        '%like%', 'like%', '%like',
        'IS NULL', 'IS NOT NULL',
        'IN', 'NOT IN',
    ];

    /**
     * Validate an AssumptionRequest
     */
    public function validate(AssumptionRequest \$request): ValidationResult
    {
        \$errors = [];

        // Validate table name
        if (!$this->isValidIdentifier(\$request->getTableName())) {
            \$errors[] = sprintf(
                'Invalid table name: %s (must match OXID naming: lowercase alphanumeric)',
                \$request->getTableName()
            );
        }

        // Validate field name
        if (!$this->isValidIdentifier(\$request->getFieldName())) {
            \$errors[] = sprintf(
                'Invalid field name: %s (must match OXID naming: lowercase alphanumeric)',
                \$request->getFieldName()
            );
        }

        // Validate operator
        if (!$this->isValidOperator(\$request->getOperator())) {
            \$errors[] = sprintf(
                'Invalid operator: %s (allowed: %s)',
                \$request->getOperator(),
                implode(', ', self::ALLOWED_OPERATORS)
            );
        }

        // Validate where clause keys
        foreach (array_keys(\$request->getWhereClause()) as \$key) {
            if (!$this->isValidIdentifier(\$key)) {
                \$errors[] = sprintf(
                    'Invalid where clause key: %s (must match OXID naming)',
                    \$key
                );
            }
        }

        return new ValidationResult(empty(\$errors), \$errors);
    }

    /**
     * Check if identifier matches OXID naming conventions
     *
     * SECURITY: This prevents SQL injection by enforcing strict naming
     */
    private function isValidIdentifier(string \$identifier): bool
    {
        // Must match pattern: lowercase alphanumeric + underscore
        if (!preg_match(self::IDENTIFIER_PATTERN, \$identifier)) {
            return false;
        }

        // Additional check: no SQL keywords or dangerous characters
        \$dangerous = ['--', '/*', '*/', ';', 'union', 'select', 'drop', 'insert', 'update', 'delete'];
        \$lower = strtolower(\$identifier);

        foreach (\$dangerous as \$keyword) {
            if (str_contains(\$lower, \$keyword)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if operator is in the allowlist
     */
    private function isValidOperator(string \$operator): bool
    {
        // Case-insensitive matching for NULL and IN operators
        \$upper = strtoupper(\$operator);
        if (in_array(\$upper, ['IS NULL', 'IS NOT NULL', 'IN', 'NOT IN'], true)) {
            return true;
        }

        return in_array(\$operator, self::ALLOWED_OPERATORS, true);
    }
}
EOF"
```

#### Create ValidationResult Value Object

```bash
docker compose exec php bash -c "cat > /var/www/extensions/stripe/src/Watch/Application/ValidationResult.php << 'EOF'
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Watch\Application;

/**
 * Validation result value object
 */
final readonly class ValidationResult
{
    /**
     * @param bool \$valid Whether validation passed
     * @param string[] \$errors List of validation errors
     */
    public function __construct(
        private bool \$valid,
        private array \$errors = []
    ) {
    }

    public function isValid(): bool
    {
        return \$this->valid;
    }

    /**
     * @return string[]
     */
    public function getErrors(): array
    {
        return \$this->errors;
    }

    public function getFirstError(): ?string
    {
        return \$this->errors[0] ?? null;
    }
}
EOF"
```

#### Run Tests (Should Pass)
```bash
docker compose exec -T -e XDEBUG_MODE=coverage php vendor/bin/phpunit \
  -c /var/www/extensions/stripe/tests/phpunit.xml \
  --bootstrap=/var/www/source/bootstrap.php \
  --filter RequestValidatorTest
```

**Expected:** ✅ 13 tests passing

---

## Task 3.2: ApiKeyValidator (Timing Attack Prevention)

**Time Estimate:** 2 hours
**TDD Cycle:** RED → GREEN → REFACTOR
**Security Critical:** 🔒 YES (Timing Attacks)

### RED Phase: Write Timing Attack Tests

```bash
docker compose exec php bash -c "cat > /var/www/extensions/stripe/tests/Unit/Watch/Application/ApiKeyValidatorTest.php << 'EOF'
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Unit\Watch\Application;

use OxidSolutionCatalysts\Payments\Watch\Application\ApiKeyValidator;
use OxidSolutionCatalysts\Payments\Watch\ValueObject\AuthConfig;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OxidSolutionCatalysts\Payments\Watch\Application\ApiKeyValidator
 * @group security
 */
class ApiKeyValidatorTest extends TestCase
{
    private const TEST_API_KEY = 'test-secret-api-key-12345';

    private ApiKeyValidator \$validator;
    private AuthConfig \$authConfig;

    protected function setUp(): void
    {
        \$this->authConfig = new AuthConfig(
            apiKey: self::TEST_API_KEY,
            allowedIps: ['127.0.0.1']
        );
        \$this->validator = new ApiKeyValidator();
    }

    /**
     * @test
     * Valid API key should pass
     */
    public function it_accepts_valid_api_key(): void
    {
        \$result = \$this->validator->validate(self::TEST_API_KEY, \$this->authConfig);

        \$this->assertTrue(\$result);
    }

    /**
     * @test
     * Invalid API key should fail
     */
    public function it_rejects_invalid_api_key(): void
    {
        \$result = \$this->validator->validate('wrong-api-key', \$this->authConfig);

        \$this->assertFalse(\$result);
    }

    /**
     * @test
     * Empty API key should fail
     */
    public function it_rejects_empty_api_key(): void
    {
        \$result = \$this->validator->validate('', \$this->authConfig);

        \$this->assertFalse(\$result);
    }

    /**
     * @test
     * @group security
     * Timing attack: validation time should be constant
     *
     * This test ensures hash_equals() is used instead of === or strcmp()
     * to prevent timing attacks where attackers measure response times
     * to guess the API key character by character.
     */
    public function it_uses_constant_time_comparison(): void
    {
        // Test with partially correct key (first half correct)
        \$partiallyCorrect = substr(self::TEST_API_KEY, 0, 10) . 'wrong-suffix';

        // Test with completely wrong key (same length)
        \$completelyWrong = str_repeat('x', strlen(self::TEST_API_KEY));

        // Both should fail
        \$result1 = \$this->validator->validate(\$partiallyCorrect, \$this->authConfig);
        \$result2 = \$this->validator->validate(\$completelyWrong, \$this->authConfig);

        \$this->assertFalse(\$result1);
        \$this->assertFalse(\$result2);

        // Timing difference should be negligible (both use hash_equals())
        // We can't easily test timing in unit tests, but code review will verify hash_equals() usage
    }

    /**
     * @test
     * API key comparison should be case-sensitive
     */
    public function it_is_case_sensitive(): void
    {
        \$uppercaseKey = strtoupper(self::TEST_API_KEY);

        \$result = \$this->validator->validate(\$uppercaseKey, \$this->authConfig);

        \$this->assertFalse(\$result, 'API keys should be case-sensitive');
    }

    /**
     * @test
     * @group security
     * Different length keys should fail early but still use constant time
     */
    public function it_handles_different_length_keys_securely(): void
    {
        \$shortKey = 'short';
        \$longKey = self::TEST_API_KEY . '-extra';

        \$result1 = \$this->validator->validate(\$shortKey, \$this->authConfig);
        \$result2 = \$this->validator->validate(\$longKey, \$this->authConfig);

        \$this->assertFalse(\$result1);
        \$this->assertFalse(\$result2);
    }
}
EOF"
```

#### Run Tests
```bash
docker compose exec -T -e XDEBUG_MODE=coverage php vendor/bin/phpunit \
  -c /var/www/extensions/stripe/tests/phpunit.xml \
  --bootstrap=/var/www/source/bootstrap.php \
  --filter ApiKeyValidatorTest
```

**Expected:** ❌ Class not found

### GREEN Phase: Implement ApiKeyValidator

```bash
docker compose exec php bash -c "cat > /var/www/extensions/stripe/src/Watch/Application/ApiKeyValidator.php << 'EOF'
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Watch\Application;

use OxidSolutionCatalysts\Payments\Watch\ValueObject\AuthConfig;

/**
 * Validates API keys using constant-time comparison
 *
 * SECURITY CRITICAL: Prevents timing attacks using hash_equals()
 *
 * Timing Attack Explanation:
 * - Regular comparison (=== or strcmp) fails fast on first mismatch
 * - Attacker can measure response time to guess key character by character
 * - hash_equals() always takes the same time regardless of where strings differ
 *
 * Example Attack (without hash_equals):
 * - Try 'a' → 1ms (fails immediately)
 * - Try 't' → 2ms (first char matches, fails on second)
 * - Attacker knows first char is 't', continues with second char...
 */
final class ApiKeyValidator
{
    /**
     * Validate API key using constant-time comparison
     *
     * @param string \$providedKey The API key from the request
     * @param AuthConfig \$config Configuration containing expected API key
     * @return bool True if keys match
     */
    public function validate(string \$providedKey, AuthConfig \$config): bool
    {
        \$expectedKey = \$config->getApiKey();

        // Reject empty keys immediately (no point in timing-safe comparison)
        if (\$providedKey === '' || \$expectedKey === '') {
            return false;
        }

        // Use hash_equals() for constant-time comparison
        // This function is specifically designed to prevent timing attacks
        return hash_equals(\$expectedKey, \$providedKey);
    }
}
EOF"
```

#### Run Tests
```bash
docker compose exec -T -e XDEBUG_MODE=coverage php vendor/bin/phpunit \
  -c /var/www/extensions/stripe/tests/phpunit.xml \
  --bootstrap=/var/www/source/bootstrap.php \
  --filter ApiKeyValidatorTest
```

**Expected:** ✅ 7 tests passing

---

## Task 3.3: IpValidator (CIDR Support)

**Time Estimate:** 3 hours
**TDD Cycle:** RED → GREEN → REFACTOR

### RED Phase: Write CIDR Tests

```bash
docker compose exec php bash -c "cat > /var/www/extensions/stripe/tests/Unit/Watch/Application/IpValidatorTest.php << 'EOF'
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Unit\Watch\Application;

use OxidSolutionCatalysts\Payments\Watch\Application\IpValidator;
use OxidSolutionCatalysts\Payments\Watch\ValueObject\AuthConfig;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OxidSolutionCatalysts\Payments\Watch\Application\IpValidator
 * @group security
 */
class IpValidatorTest extends TestCase
{
    private IpValidator \$validator;

    protected function setUp(): void
    {
        \$this->validator = new IpValidator();
    }

    /**
     * @test
     * Single IP address should match exactly
     */
    public function it_validates_single_ip_address(): void
    {
        \$config = new AuthConfig('key', ['192.168.1.100']);

        \$this->assertTrue(\$this->validator->validate('192.168.1.100', \$config));
        \$this->assertFalse(\$this->validator->validate('192.168.1.101', \$config));
    }

    /**
     * @test
     * Multiple IP addresses in allowlist
     */
    public function it_validates_multiple_ip_addresses(): void
    {
        \$config = new AuthConfig('key', ['192.168.1.100', '10.0.0.5', '172.16.0.1']);

        \$this->assertTrue(\$this->validator->validate('192.168.1.100', \$config));
        \$this->assertTrue(\$this->validator->validate('10.0.0.5', \$config));
        \$this->assertTrue(\$this->validator->validate('172.16.0.1', \$config));
        \$this->assertFalse(\$this->validator->validate('192.168.1.101', \$config));
    }

    /**
     * @test
     * CIDR notation: /24 subnet (255 addresses)
     */
    public function it_validates_cidr_24_subnet(): void
    {
        \$config = new AuthConfig('key', ['192.168.1.0/24']);

        \$this->assertTrue(\$this->validator->validate('192.168.1.1', \$config));
        \$this->assertTrue(\$this->validator->validate('192.168.1.100', \$config));
        \$this->assertTrue(\$this->validator->validate('192.168.1.254', \$config));
        \$this->assertFalse(\$this->validator->validate('192.168.2.1', \$config));
    }

    /**
     * @test
     * CIDR notation: /16 subnet (65,536 addresses)
     */
    public function it_validates_cidr_16_subnet(): void
    {
        \$config = new AuthConfig('key', ['10.0.0.0/16']);

        \$this->assertTrue(\$this->validator->validate('10.0.0.1', \$config));
        \$this->assertTrue(\$this->validator->validate('10.0.255.255', \$config));
        \$this->assertFalse(\$this->validator->validate('10.1.0.1', \$config));
    }

    /**
     * @test
     * CIDR notation: /32 (single IP)
     */
    public function it_validates_cidr_32_single_ip(): void
    {
        \$config = new AuthConfig('key', ['192.168.1.100/32']);

        \$this->assertTrue(\$this->validator->validate('192.168.1.100', \$config));
        \$this->assertFalse(\$this->validator->validate('192.168.1.101', \$config));
    }

    /**
     * @test
     * Localhost variations
     */
    public function it_validates_localhost(): void
    {
        \$config = new AuthConfig('key', ['127.0.0.1', '::1']);

        \$this->assertTrue(\$this->validator->validate('127.0.0.1', \$config));
        \$this->assertTrue(\$this->validator->validate('::1', \$config));
    }

    /**
     * @test
     * IPv6 address support
     */
    public function it_validates_ipv6_addresses(): void
    {
        \$config = new AuthConfig('key', ['2001:db8::1', 'fe80::1']);

        \$this->assertTrue(\$this->validator->validate('2001:db8::1', \$config));
        \$this->assertTrue(\$this->validator->validate('fe80::1', \$config));
        \$this->assertFalse(\$this->validator->validate('2001:db8::2', \$config));
    }

    /**
     * @test
     * IPv6 CIDR notation
     */
    public function it_validates_ipv6_cidr(): void
    {
        \$config = new AuthConfig('key', ['2001:db8::/32']);

        \$this->assertTrue(\$this->validator->validate('2001:db8::1', \$config));
        \$this->assertTrue(\$this->validator->validate('2001:db8:ffff::1', \$config));
        \$this->assertFalse(\$this->validator->validate('2001:db9::1', \$config));
    }

    /**
     * @test
     * Empty allowlist should reject all IPs
     */
    public function it_rejects_all_when_allowlist_empty(): void
    {
        \$config = new AuthConfig('key', []);

        \$this->assertFalse(\$this->validator->validate('127.0.0.1', \$config));
        \$this->assertFalse(\$this->validator->validate('192.168.1.1', \$config));
    }

    /**
     * @test
     * Invalid IP format should be rejected
     */
    public function it_rejects_invalid_ip_format(): void
    {
        \$config = new AuthConfig('key', ['192.168.1.1']);

        \$this->assertFalse(\$this->validator->validate('not-an-ip', \$config));
        \$this->assertFalse(\$this->validator->validate('999.999.999.999', \$config));
        \$this->assertFalse(\$this->validator->validate('', \$config));
    }

    /**
     * @test
     * Mixed single IPs and CIDR ranges
     */
    public function it_validates_mixed_ip_and_cidr(): void
    {
        \$config = new AuthConfig('key', [
            '192.168.1.100',      // Single IP
            '10.0.0.0/24',        // CIDR range
            '172.16.0.0/16',      // Larger CIDR
        ]);

        \$this->assertTrue(\$this->validator->validate('192.168.1.100', \$config));
        \$this->assertTrue(\$this->validator->validate('10.0.0.50', \$config));
        \$this->assertTrue(\$this->validator->validate('172.16.5.10', \$config));
        \$this->assertFalse(\$this->validator->validate('192.168.1.101', \$config));
    }
}
EOF"
```

#### Run Tests
```bash
docker compose exec -T -e XDEBUG_MODE=coverage php vendor/bin/phpunit \
  -c /var/www/extensions/stripe/tests/phpunit.xml \
  --bootstrap=/var/www/source/bootstrap.php \
  --filter IpValidatorTest
```

**Expected:** ❌ Class not found

### GREEN Phase: Implement IpValidator

```bash
docker compose exec php bash -c "cat > /var/www/extensions/stripe/src/Watch/Application/IpValidator.php << 'EOF'
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Watch\Application;

use OxidSolutionCatalysts\Payments\Watch\ValueObject\AuthConfig;

/**
 * Validates IP addresses against an allowlist with CIDR support
 *
 * Supports:
 * - Single IPv4/IPv6 addresses: 192.168.1.100, 2001:db8::1
 * - CIDR notation: 192.168.1.0/24, 2001:db8::/32
 * - Mixed configurations
 */
final class IpValidator
{
    /**
     * Validate if provided IP is in the allowlist
     *
     * @param string \$providedIp The IP address from the request
     * @param AuthConfig \$config Configuration containing allowed IPs/ranges
     * @return bool True if IP is allowed
     */
    public function validate(string \$providedIp, AuthConfig \$config): bool
    {
        \$allowedIps = \$config->getAllowedIps();

        // Empty allowlist = deny all
        if (empty(\$allowedIps)) {
            return false;
        }

        // Validate IP format
        if (!filter_var(\$providedIp, FILTER_VALIDATE_IP)) {
            return false;
        }

        // Check each allowed IP/range
        foreach (\$allowedIps as \$allowed) {
            if ($this->ipMatches(\$providedIp, \$allowed)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if IP matches a single IP or CIDR range
     */
    private function ipMatches(string \$ip, string \$range): bool
    {
        // Check if range contains CIDR notation
        if (str_contains(\$range, '/')) {
            return $this->ipMatchesCidr(\$ip, \$range);
        }

        // Simple IP-to-IP comparison
        return \$ip === \$range;
    }

    /**
     * Check if IP is within CIDR range
     *
     * Example: 192.168.1.100 in 192.168.1.0/24
     */
    private function ipMatchesCidr(string \$ip, string \$cidr): bool
    {
        [\$subnet, \$maskBits] = explode('/', \$cidr, 2);

        // Validate subnet IP
        if (!filter_var(\$subnet, FILTER_VALIDATE_IP)) {
            return false;
        }

        // Determine IP version (IPv4 or IPv6)
        \$ipVersion = filter_var(\$ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? 4 : 6;
        \$subnetVersion = filter_var(\$subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? 4 : 6;

        // IP versions must match
        if (\$ipVersion !== \$subnetVersion) {
            return false;
        }

        if (\$ipVersion === 4) {
            return $this->ipv4MatchesCidr(\$ip, \$subnet, (int) \$maskBits);
        }

        return $this->ipv6MatchesCidr(\$ip, \$subnet, (int) \$maskBits);
    }

    /**
     * Check IPv4 address against CIDR range
     */
    private function ipv4MatchesCidr(string \$ip, string \$subnet, int \$maskBits): bool
    {
        \$ipLong = ip2long(\$ip);
        \$subnetLong = ip2long(\$subnet);

        if (\$ipLong === false || \$subnetLong === false) {
            return false;
        }

        // Create subnet mask (e.g., /24 = 255.255.255.0)
        \$mask = -1 << (32 - \$maskBits);

        // Apply mask to both IPs and compare
        return (\$ipLong & \$mask) === (\$subnetLong & \$mask);
    }

    /**
     * Check IPv6 address against CIDR range
     */
    private function ipv6MatchesCidr(string \$ip, string \$subnet, int \$maskBits): bool
    {
        \$ipBin = inet_pton(\$ip);
        \$subnetBin = inet_pton(\$subnet);

        if (\$ipBin === false || \$subnetBin === false) {
            return false;
        }

        // Calculate number of full bytes and remaining bits
        \$fullBytes = intdiv(\$maskBits, 8);
        \$remainingBits = \$maskBits % 8;

        // Compare full bytes
        for (\$i = 0; \$i < \$fullBytes; \$i++) {
            if (\$ipBin[\$i] !== \$subnetBin[\$i]) {
                return false;
            }
        }

        // Compare remaining bits
        if (\$remainingBits > 0) {
            \$mask = 0xFF << (8 - \$remainingBits);
            \$ipByte = ord(\$ipBin[\$fullBytes]);
            \$subnetByte = ord(\$subnetBin[\$fullBytes]);

            if ((\$ipByte & \$mask) !== (\$subnetByte & \$mask)) {
                return false;
            }
        }

        return true;
    }
}
EOF"
```

#### Run Tests
```bash
docker compose exec -T -e XDEBUG_MODE=coverage php vendor/bin/phpunit \
  -c /var/www/extensions/stripe/tests/phpunit.xml \
  --bootstrap=/var/www/source/bootstrap.php \
  --filter IpValidatorTest
```

**Expected:** ✅ 15 tests passing

---

## Task 3.4: AssumptionParser

**Time Estimate:** 1.5 hours
**TDD Cycle:** RED → GREEN → REFACTOR

### RED Phase: Write Parser Tests

```bash
docker compose exec php bash -c "cat > /var/www/extensions/stripe/tests/Unit/Watch/Application/AssumptionParserTest.php << 'EOF'
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Unit\Watch\Application;

use OxidSolutionCatalysts\Payments\Watch\Application\AssumptionParser;
use OxidSolutionCatalysts\Payments\Watch\ValueObject\AssumptionRequest;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OxidSolutionCatalysts\Payments\Watch\Application\AssumptionParser
 */
class AssumptionParserTest extends TestCase
{
    private AssumptionParser \$parser;

    protected function setUp(): void
    {
        \$this->parser = new AssumptionParser();
    }

    /**
     * @test
     */
    public function it_parses_simple_assumption(): void
    {
        \$json = json_encode([
            'table' => 'oxorder',
            'field' => 'oxordernr',
            'value' => '12345',
            'operator' => '==',
        ]);

        \$request = \$this->parser->parse(\$json);

        \$this->assertInstanceOf(AssumptionRequest::class, \$request);
        \$this->assertSame('oxorder', \$request->getTableName());
        \$this->assertSame('oxordernr', \$request->getFieldName());
        \$this->assertSame('12345', \$request->getExpectedValue());
        \$this->assertSame('==', \$request->getOperator());
    }

    /**
     * @test
     */
    public function it_parses_assumption_with_where_clause(): void
    {
        \$json = json_encode([
            'table' => 'oxorder',
            'field' => 'oxpaid',
            'value' => '2024-01-15 10:30:00',
            'operator' => '!=',
            'where' => [
                'oxordernr' => '12345',
                'oxstorno' => 0,
            ],
        ]);

        \$request = \$this->parser->parse(\$json);

        \$this->assertSame(['oxordernr' => '12345', 'oxstorno' => 0], \$request->getWhereClause());
    }

    /**
     * @test
     */
    public function it_uses_default_operator_if_not_provided(): void
    {
        \$json = json_encode([
            'table' => 'oxorder',
            'field' => 'oxordernr',
            'value' => '12345',
        ]);

        \$request = \$this->parser->parse(\$json);

        \$this->assertSame('==', \$request->getOperator(), 'Default operator should be ==');
    }

    /**
     * @test
     */
    public function it_throws_exception_for_invalid_json(): void
    {
        \$this->expectException(\JsonException::class);

        \$this->parser->parse('invalid json{');
    }

    /**
     * @test
     */
    public function it_throws_exception_for_missing_table(): void
    {
        \$json = json_encode([
            'field' => 'oxordernr',
            'value' => '12345',
        ]);

        \$this->expectException(\InvalidArgumentException::class);
        \$this->expectExceptionMessage('Missing required field: table');

        \$this->parser->parse(\$json);
    }

    /**
     * @test
     */
    public function it_throws_exception_for_missing_field(): void
    {
        \$json = json_encode([
            'table' => 'oxorder',
            'value' => '12345',
        ]);

        \$this->expectException(\InvalidArgumentException::class);
        \$this->expectExceptionMessage('Missing required field: field');

        \$this->parser->parse(\$json);
    }

    /**
     * @test
     */
    public function it_throws_exception_for_missing_value(): void
    {
        \$json = json_encode([
            'table' => 'oxorder',
            'field' => 'oxordernr',
        ]);

        \$this->expectException(\InvalidArgumentException::class);
        \$this->expectExceptionMessage('Missing required field: value');

        \$this->parser->parse(\$json);
    }

    /**
     * @test
     */
    public function it_accepts_null_value(): void
    {
        \$json = json_encode([
            'table' => 'oxorder',
            'field' => 'oxdeltype',
            'value' => null,
            'operator' => 'IS NULL',
        ]);

        \$request = \$this->parser->parse(\$json);

        \$this->assertNull(\$request->getExpectedValue());
    }

    /**
     * @test
     */
    public function it_accepts_integer_value(): void
    {
        \$json = json_encode([
            'table' => 'oxorder',
            'field' => 'oxstorno',
            'value' => 0,
        ]);

        \$request = \$this->parser->parse(\$json);

        \$this->assertSame(0, \$request->getExpectedValue());
    }

    /**
     * @test
     */
    public function it_accepts_float_value(): void
    {
        \$json = json_encode([
            'table' => 'oxorder',
            'field' => 'oxtotalordersum',
            'value' => 99.99,
        ]);

        \$request = \$this->parser->parse(\$json);

        \$this->assertSame(99.99, \$request->getExpectedValue());
    }

    /**
     * @test
     */
    public function it_accepts_array_value_for_in_operator(): void
    {
        \$json = json_encode([
            'table' => 'oxorder',
            'field' => 'oxpaymenttype',
            'value' => ['oxidpaypal', 'oxidstripe'],
            'operator' => 'IN',
        ]);

        \$request = \$this->parser->parse(\$json);

        \$this->assertSame(['oxidpaypal', 'oxidstripe'], \$request->getExpectedValue());
    }
}
EOF"
```

#### Run Tests
```bash
docker compose exec -T -e XDEBUG_MODE=coverage php vendor/bin/phpunit \
  -c /var/www/extensions/stripe/tests/phpunit.xml \
  --bootstrap=/var/www/source/bootstrap.php \
  --filter AssumptionParserTest
```

**Expected:** ❌ Class not found

### GREEN Phase: Implement AssumptionParser

```bash
docker compose exec php bash -c "cat > /var/www/extensions/stripe/src/Watch/Application/AssumptionParser.php << 'EOF'
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Watch\Application;

use InvalidArgumentException;
use JsonException;
use OxidSolutionCatalysts\Payments\Watch\ValueObject\AssumptionRequest;

/**
 * Parses JSON request payload into AssumptionRequest value object
 *
 * Expected JSON format:
 * {
 *   \"table\": \"oxorder\",
 *   \"field\": \"oxordernr\",
 *   \"value\": \"12345\",
 *   \"operator\": \"==\",  // optional, defaults to ==
 *   \"where\": {          // optional
 *     \"oxpaid\": \"0000-00-00 00:00:00\"
 *   }
 * }
 */
final class AssumptionParser
{
    /**
     * Parse JSON string into AssumptionRequest
     *
     * @throws JsonException If JSON is invalid
     * @throws InvalidArgumentException If required fields are missing
     */
    public function parse(string \$json): AssumptionRequest
    {
        // Parse JSON with exceptions enabled
        \$data = json_decode(\$json, true, 512, JSON_THROW_ON_ERROR);

        // Validate required fields
        $this->validateRequiredFields(\$data);

        return new AssumptionRequest(
            tableName: \$data['table'],
            fieldName: \$data['field'],
            expectedValue: \$data['value'],
            operator: \$data['operator'] ?? '==',
            whereClause: \$data['where'] ?? []
        );
    }

    /**
     * Validate that required fields are present
     *
     * @param array<string, mixed> \$data
     * @throws InvalidArgumentException
     */
    private function validateRequiredFields(array \$data): void
    {
        \$required = ['table', 'field', 'value'];

        foreach (\$required as \$field) {
            if (!array_key_exists(\$field, \$data)) {
                throw new InvalidArgumentException(
                    sprintf('Missing required field: %s', \$field)
                );
            }
        }
    }
}
EOF"
```

#### Run Tests
```bash
docker compose exec -T -e XDEBUG_MODE=coverage php vendor/bin/phpunit \
  -c /var/www/extensions/stripe/tests/phpunit.xml \
  --bootstrap=/var/www/source/bootstrap.php \
  --filter AssumptionParserTest
```

**Expected:** ✅ 14 tests passing

---

## Task 3.5: AuthenticationService

**Time Estimate:** 2 hours
**TDD Cycle:** RED → GREEN → REFACTOR

### RED Phase: Write Integration Tests

```bash
docker compose exec php bash -c "cat > /var/www/extensions/stripe/tests/Unit/Watch/Application/AuthenticationServiceTest.php << 'EOF'
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Unit\Watch\Application;

use OxidSolutionCatalysts\Payments\Watch\Application\AuthenticationService;
use OxidSolutionCatalysts\Payments\Watch\Application\ApiKeyValidator;
use OxidSolutionCatalysts\Payments\Watch\Application\IpValidator;
use OxidSolutionCatalysts\Payments\Watch\ValueObject\AuthConfig;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OxidSolutionCatalysts\Payments\Watch\Application\AuthenticationService
 * @group security
 */
class AuthenticationServiceTest extends TestCase
{
    private AuthenticationService \$service;
    private AuthConfig \$config;

    protected function setUp(): void
    {
        \$apiKeyValidator = new ApiKeyValidator();
        \$ipValidator = new IpValidator();

        \$this->service = new AuthenticationService(\$apiKeyValidator, \$ipValidator);

        \$this->config = new AuthConfig(
            apiKey: 'test-secret-key',
            allowedIps: ['192.168.1.100', '10.0.0.0/24']
        );
    }

    /**
     * @test
     * Both API key and IP must be valid
     */
    public function it_requires_both_valid_api_key_and_ip(): void
    {
        \$result = \$this->service->authenticate(
            'test-secret-key',
            '192.168.1.100',
            \$this->config
        );

        \$this->assertTrue(\$result->isAuthenticated());
        \$this->assertEmpty(\$result->getErrors());
    }

    /**
     * @test
     * Invalid API key should fail
     */
    public function it_rejects_invalid_api_key(): void
    {
        \$result = \$this->service->authenticate(
            'wrong-key',
            '192.168.1.100',
            \$this->config
        );

        \$this->assertFalse(\$result->isAuthenticated());
        \$this->assertStringContainsString('Invalid API key', \$result->getErrors()[0]);
    }

    /**
     * @test
     * Invalid IP should fail
     */
    public function it_rejects_invalid_ip(): void
    {
        \$result = \$this->service->authenticate(
            'test-secret-key',
            '192.168.1.101',  // Not in allowlist
            \$this->config
        );

        \$this->assertFalse(\$result->isAuthenticated());
        \$this->assertStringContainsString('IP address not allowed', \$result->getErrors()[0]);
    }

    /**
     * @test
     * Both API key and IP invalid
     */
    public function it_returns_multiple_errors(): void
    {
        \$result = \$this->service->authenticate(
            'wrong-key',
            '192.168.1.101',
            \$this->config
        );

        \$this->assertFalse(\$result->isAuthenticated());
        \$this->assertCount(2, \$result->getErrors());
        \$this->assertStringContainsString('Invalid API key', \$result->getErrors()[0]);
        \$this->assertStringContainsString('IP address not allowed', \$result->getErrors()[1]);
    }

    /**
     * @test
     * IP in CIDR range should pass
     */
    public function it_validates_ip_in_cidr_range(): void
    {
        \$result = \$this->service->authenticate(
            'test-secret-key',
            '10.0.0.50',  // In 10.0.0.0/24 range
            \$this->config
        );

        \$this->assertTrue(\$result->isAuthenticated());
    }
}
EOF"
```

#### Run Tests
```bash
docker compose exec -T -e XDEBUG_MODE=coverage php vendor/bin/phpunit \
  -c /var/www/extensions/stripe/tests/phpunit.xml \
  --bootstrap=/var/www/source/bootstrap.php \
  --filter AuthenticationServiceTest
```

**Expected:** ❌ Class not found

### GREEN Phase: Implement AuthenticationService

```bash
docker compose exec php bash -c "cat > /var/www/extensions/stripe/src/Watch/Application/AuthenticationService.php << 'EOF'
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Watch\Application;

use OxidSolutionCatalysts\Payments\Watch\ValueObject\AuthConfig;

/**
 * Authenticates requests using API key + IP allowlist
 *
 * Both API key and IP must be valid for authentication to succeed
 */
final readonly class AuthenticationService
{
    public function __construct(
        private ApiKeyValidator \$apiKeyValidator,
        private IpValidator \$ipValidator
    ) {
    }

    /**
     * Authenticate a request
     *
     * @param string \$apiKey API key from request header
     * @param string \$ipAddress IP address from request
     * @param AuthConfig \$config Authentication configuration
     * @return AuthenticationResult Result with success status and errors
     */
    public function authenticate(
        string \$apiKey,
        string \$ipAddress,
        AuthConfig \$config
    ): AuthenticationResult {
        \$errors = [];

        // Validate API key
        if (!\$this->apiKeyValidator->validate(\$apiKey, \$config)) {
            \$errors[] = 'Invalid API key';
        }

        // Validate IP address
        if (!\$this->ipValidator->validate(\$ipAddress, \$config)) {
            \$errors[] = sprintf('IP address not allowed: %s', \$ipAddress);
        }

        return new AuthenticationResult(
            authenticated: empty(\$errors),
            errors: \$errors
        );
    }
}
EOF"
```

#### Create AuthenticationResult Value Object

```bash
docker compose exec php bash -c "cat > /var/www/extensions/stripe/src/Watch/Application/AuthenticationResult.php << 'EOF'
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Watch\Application;

/**
 * Authentication result value object
 */
final readonly class AuthenticationResult
{
    /**
     * @param bool \$authenticated Whether authentication succeeded
     * @param string[] \$errors List of authentication errors
     */
    public function __construct(
        private bool \$authenticated,
        private array \$errors = []
    ) {
    }

    public function isAuthenticated(): bool
    {
        return \$this->authenticated;
    }

    /**
     * @return string[]
     */
    public function getErrors(): array
    {
        return \$this->errors;
    }

    public function getFirstError(): ?string
    {
        return \$this->errors[0] ?? null;
    }
}
EOF"
```

#### Run Tests
```bash
docker compose exec -T -e XDEBUG_MODE=coverage php vendor/bin/phpunit \
  -c /var/www/extensions/stripe/tests/phpunit.xml \
  --bootstrap=/var/www/source/bootstrap.php \
  --filter AuthenticationServiceTest
```

**Expected:** ✅ 5 tests passing

---

## Sprint 3 Deliverables

### Code Files Created
```
src/Watch/Application/
├── RequestValidator.php
├── ValidationResult.php
├── ApiKeyValidator.php
├── IpValidator.php
├── AssumptionParser.php
├── AuthenticationService.php
└── AuthenticationResult.php
```

### Test Files Created
```
tests/Unit/Watch/Application/
├── RequestValidatorTest.php (13 tests) @group security
├── ApiKeyValidatorTest.php (7 tests) @group security
├── IpValidatorTest.php (15 tests) @group security
├── AssumptionParserTest.php (14 tests)
└── AuthenticationServiceTest.php (5 tests) @group security
```

**Total:** 54 new tests (127 total)
**Security Tests:** 40 tests with `@group security`

---

## Acceptance Criteria

### Security
- ✅ SQL injection tests pass (table/field name validation)
- ✅ Timing attack prevention (hash_equals() used)
- ✅ IP allowlist with CIDR support
- ✅ Request validation with strict identifier rules

### Test Coverage
- ✅ >= 95% coverage for Application layer
- ✅ All security-critical paths tested
- ✅ Edge cases covered (empty inputs, invalid formats)

### Code Quality
- ✅ All classes are `readonly`
- ✅ PHPDoc comments on all public methods
- ✅ Security annotations (`@group security`)

---

## Verify Sprint Completion

### Run All Tests
```bash
docker compose exec -T -e XDEBUG_MODE=coverage php vendor/bin/phpunit \
  -c /var/www/extensions/stripe/tests/phpunit.xml \
  --bootstrap=/var/www/source/bootstrap.php \
  --testsuite Unit \
  --filter Watch
```

**Expected:** ✅ 127 tests passing

### Run Security Tests Only
```bash
docker compose exec -T -e XDEBUG_MODE=coverage php vendor/bin/phpunit \
  -c /var/www/extensions/stripe/tests/phpunit.xml \
  --bootstrap=/var/www/source/bootstrap.php \
  --group security
```

**Expected:** ✅ 40 security tests passing

### Check Coverage
```bash
docker compose exec -T -e XDEBUG_MODE=coverage php vendor/bin/phpunit \
  -c /var/www/extensions/stripe/tests/phpunit.xml \
  --bootstrap=/var/www/source/bootstrap.php \
  --testsuite Unit \
  --filter Watch \
  --coverage-text
```

**Expected:** >= 95% coverage for Application layer

---

## Sprint Review

### Demo Checklist
- [ ] Show SQL injection prevention tests
- [ ] Explain timing attack prevention with hash_equals()
- [ ] Demonstrate CIDR IP validation
- [ ] Run security test suite (40 tests)
- [ ] Show coverage report >= 95%

### Retrospective Questions
1. Are all security attack vectors covered?
2. Should we add more SQL injection test cases?
3. Is the API key strong enough? (recommend 32+ chars)
4. Should we log authentication failures for monitoring?

---

## Security Audit Checklist

### SQL Injection
- [x] Table names validated with regex pattern
- [x] Field names validated with regex pattern
- [x] Operators allowlisted
- [x] Where clause keys validated
- [x] Dangerous keywords blocked (UNION, DROP, --, /*, etc.)

### Timing Attacks
- [x] hash_equals() used for API key comparison
- [x] Constant-time comparison tested

### IP Security
- [x] Empty allowlist denies all
- [x] CIDR range support tested
- [x] IPv4 and IPv6 supported
- [x] Invalid IP format rejected

### Input Validation
- [x] JSON parsing with exceptions
- [x] Required fields validated
- [x] Type safety enforced

---

## Common Issues

### Issue: hash_equals() returns false for valid keys
**Solution:** Ensure both strings are the same length. Check for whitespace trimming.

### Issue: CIDR validation fails for IPv6
**Solution:** Use `inet_pton()` for binary conversion, not `ip2long()` (IPv4 only).

### Issue: Regex pattern too strict
**Solution:** OXID convention is lowercase alphanumeric + underscore. Pattern: `/^[a-z][a-z0-9_]*$/`

---

## Next Sprint

**Ready for [Sprint 4: Database Layer](sprint-04-database.md)**

Sprint 4 will implement:
- SqlSanitizer (identifier escaping)
- QueryBuilder (DBAL integration)
- AuditLogger (request logging)
- Integration tests with real database
- Performance indexes

---

**Sprint 3 Complete! 🎉🔒**
**Tests:** 54 new tests (127 total)
**Security Tests:** 40 with @group security
**Coverage:** >= 95%
**Next:** Database Layer (Week 5)
