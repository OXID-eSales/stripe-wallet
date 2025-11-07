# TDD Best Practices & Clean Code

**Principles for Maintaining High-Quality Code**

---

## Navigation

📖 **TDD Documentation:**
- [00-overview.md](00-overview.md) - TDD Overview & SOLID Principles
- [01-phase1-domain.md](01-phase1-domain.md) - Domain Layer (Value Objects)
- [02-phase2-infrastructure.md](02-phase2-infrastructure.md) - Infrastructure Layer (Strategies)
- [03-phase3-application.md](03-phase3-application.md) - Application Layer (Services)
- **[04-best-practices.md](04-best-practices.md)** ← You are here

---

## TDD Best Practices

### 1. Test Naming Convention

Use descriptive names that explain the behavior:

```php
// ✅ Good
public function it_validates_correct_api_key(): void
public function it_rejects_sql_injection_attempt(): void
public function it_compares_values_using_loose_equality(): void

// ❌ Bad
public function testValidate(): void
public function test1(): void
public function testApiKey(): void
```

**Pattern:** `it_<describes_behavior_in_plain_english>()`

---

### 2. Arrange-Act-Assert Pattern

Structure all tests consistently:

```php
public function it_compares_equal_strings(): void
{
    // Arrange: Set up test data
    $operator = new EqualityOperator();
    $value1 = 'completed';
    $value2 = 'completed';

    // Act: Execute the method being tested
    $result = $operator->compare($value1, $value2);

    // Assert: Verify the outcome
    $this->assertTrue($result);
}
```

---

### 3. One Logical Assertion Per Test

Focus each test on a single concept:

```php
// ✅ Good: Single logical assertion
public function it_validates_ip_in_cidr_range(): void
{
    $this->assertTrue(
        $this->validator->validate('192.168.1.100', '192.168.1.0/24')
    );
}

// ✅ Also good: Multiple assertions testing same concept
public function it_validates_multiple_ips_in_same_range(): void
{
    $cidr = '192.168.1.0/24';
    $this->assertTrue($this->validator->validate('192.168.1.1', $cidr));
    $this->assertTrue($this->validator->validate('192.168.1.100', $cidr));
    $this->assertFalse($this->validator->validate('192.168.2.1', $cidr));
}
```

---

### 4. Test Independence

Each test should be independent:

```php
class AssumptionRequestTest extends TestCase
{
    protected function setUp(): void
    {
        // Fresh instance for each test
        $this->validator = new RequestValidator();
    }

    // Each test is isolated
    public function it_validates_identifier(): void
    {
        // No dependency on other tests
    }
}
```

---

### 5. Test Categories with Annotations

Organize tests by category:

```php
/**
 * @test
 * @group security
 */
public function it_prevents_sql_injection(): void
{
    // Security-critical test
}

/**
 * @test
 * @group integration
 */
public function it_queries_database(): void
{
    // Requires database
}
```

**Run specific groups:**
```bash
vendor/bin/phpunit --group security
vendor/bin/phpunit --exclude-group integration
```

---

## Clean Code Principles

### 1. Meaningful Names

```php
// ✅ Good
$assumptionRequest = new AssumptionRequest(...);
$isMatch = $operator->compare($actual, $expected);
$validatedIdentifier = $this->sanitize($tableName);

// ❌ Bad
$req = new Req(...);
$r = $op->cmp($a, $e);
$v = $this->s($t);
```

---

### 2. Small Functions

**Single Responsibility:**

```php
// ✅ Good: Single responsibility
public function validate(string $providedKey, string $expectedKey): bool
{
    return hash_equals(
        strtolower($expectedKey),
        strtolower($providedKey)
    );
}

// ❌ Bad: Too many responsibilities
public function validateAndLogAndNotifyAndAudit(...): mixed
{
    // 50 lines doing multiple things
}
```

---

### 3. Early Returns

Reduce nesting with early returns:

```php
// ✅ Good
public function validate(string $clientIp, string $allowedIp): bool
{
    if (str_contains($allowedIp, '/')) {
        return $this->isInCidrRange($clientIp, $allowedIp);
    }

    return $clientIp === $allowedIp;
}

// ❌ Bad
public function validate(string $clientIp, string $allowedIp): bool
{
    $result = false;
    if (str_contains($allowedIp, '/')) {
        $result = $this->isInCidrRange($clientIp, $allowedIp);
    } else {
        $result = $clientIp === $allowedIp;
    }
    return $result;
}
```

---

### 4. Immutability

Use `readonly` properties for value objects:

```php
// ✅ Good: Readonly properties
final class AssumptionRequest
{
    public function __construct(
        private readonly string $tableName,
        private readonly string $fieldName,
        // ...
    ) {}

    // No setters!
}
```

---

### 5. Explicit over Implicit

Make intent clear:

```php
// ✅ Good: Clear intent
public function compare(mixed $actual, mixed $expected): bool
{
    return $actual == $expected;  // Documented as loose comparison
}

// ❌ Bad: Unclear
public function compare($a, $b)
{
    return $a == $b ? true : false;  // Redundant ternary
}
```

---

## Continuous Refactoring

### When to Refactor

✅ **Refactor when:**
- Tests are GREEN ✅
- You see duplication (Rule of Three)
- Complexity increases
- New patterns emerge

❌ **Don't refactor when:**
- Tests are RED ❌
- Deadline is imminent
- Pattern not yet clear

---

### Rule of Three

**Only extract abstraction after 3+ occurrences:**

```php
// First occurrence - keep it simple
class EqualityOperator {
    public function compare($a, $b): bool {
        return $a == $b;
    }
}

// Second occurrence - note similarity
class ComparisonOperator {
    public function compare($a, $b): bool {
        return $a > $b;
    }
}

// Third occurrence - NOW refactor
abstract class AbstractOperator {
    abstract protected function performComparison($a, $b): bool;

    public function compare($a, $b): bool {
        // Common logic extracted
        if ($a === null && $b === null) {
            return true;
        }

        return $this->performComparison($a, $b);
    }
}
```

---

## Testing Commands Reference

### 🐳 Docker Environment (REQUIRED)

**ALWAYS run tests in Docker container with Xdebug coverage enabled:**

```bash
docker compose exec -T -e XDEBUG_MODE=coverage php \
  vendor/bin/phpunit \
  -c /var/www/extensions/stripe/tests/phpunit.xml \
  --bootstrap=/var/www/source/bootstrap.php
```

**Why Docker?**
- Consistent environment across machines
- Proper database access for integration tests
- Xdebug coverage support
- Matches production PHP version and extensions

---

### Run All Tests (Docker)
```bash
docker compose exec -T -e XDEBUG_MODE=coverage php \
  vendor/bin/phpunit \
  -c /var/www/extensions/stripe/tests/phpunit.xml \
  --bootstrap=/var/www/source/bootstrap.php
```

### Run Specific Suite (Docker)
```bash
# Unit tests only
docker compose exec -T -e XDEBUG_MODE=coverage php \
  vendor/bin/phpunit \
  -c /var/www/extensions/stripe/tests/phpunit.xml \
  --bootstrap=/var/www/source/bootstrap.php \
  --testsuite Unit

# Integration tests only
docker compose exec -T -e XDEBUG_MODE=coverage php \
  vendor/bin/phpunit \
  -c /var/www/extensions/stripe/tests/phpunit.xml \
  --bootstrap=/var/www/source/bootstrap.php \
  --testsuite Integration
```

### Run Specific Directory (Docker)
```bash
docker compose exec -T -e XDEBUG_MODE=coverage php \
  vendor/bin/phpunit \
  -c /var/www/extensions/stripe/tests/phpunit.xml \
  --bootstrap=/var/www/source/bootstrap.php \
  tests/Unit/Watch/ValueObject/
```

### Run Specific Test File (Docker)
```bash
docker compose exec -T -e XDEBUG_MODE=coverage php \
  vendor/bin/phpunit \
  -c /var/www/extensions/stripe/tests/phpunit.xml \
  --bootstrap=/var/www/source/bootstrap.php \
  tests/Unit/Watch/ValueObject/AssumptionRequestTest.php
```

### Run with Coverage (Docker)
```bash
docker compose exec -T -e XDEBUG_MODE=coverage php \
  vendor/bin/phpunit \
  -c /var/www/extensions/stripe/tests/phpunit.xml \
  --bootstrap=/var/www/source/bootstrap.php \
  --coverage-html coverage/

# View coverage report (from host)
open coverage/index.html
```

### Run with Human-Readable Output (Docker)
```bash
docker compose exec -T -e XDEBUG_MODE=coverage php \
  vendor/bin/phpunit \
  -c /var/www/extensions/stripe/tests/phpunit.xml \
  --bootstrap=/var/www/source/bootstrap.php \
  --testdox
```

**Example Output:**
```
AssumptionRequest
 ✔ It creates valid assumption request
 ✔ It builds field path
 ✔ It uses default operator when not provided
 ✔ It is immutable

EqualityOperator
 ✔ It compares equal strings
 ✔ It compares unequal strings
 ✔ It uses loose comparison
 ✔ It builds sql condition
```

### Run Only Security Tests (Docker)
```bash
docker compose exec -T -e XDEBUG_MODE=coverage php \
  vendor/bin/phpunit \
  -c /var/www/extensions/stripe/tests/phpunit.xml \
  --bootstrap=/var/www/source/bootstrap.php \
  --group security
```

---

### Short Alias (Optional)

Add to your shell profile for convenience:

```bash
# Add to ~/.bashrc or ~/.zshrc
alias phpunit-watch='docker compose exec -T -e XDEBUG_MODE=coverage php vendor/bin/phpunit -c /var/www/extensions/stripe/tests/phpunit.xml --bootstrap=/var/www/source/bootstrap.php'

# Usage
phpunit-watch
phpunit-watch --testsuite Unit
phpunit-watch tests/Unit/Watch/ValueObject/AssumptionRequestTest.php
phpunit-watch --testdox
```

---

## Code Review Checklist

Before committing code, verify:

- [ ] All tests passing ✅
- [ ] Test coverage > 80%
- [ ] No security vulnerabilities
- [ ] SOLID principles applied
- [ ] Clean code practices followed
- [ ] Meaningful names used
- [ ] No code duplication
- [ ] Comments explain "why", not "what"
- [ ] Visual diagrams updated (if architecture changed)
- [ ] Documentation updated

---

## Common Pitfalls to Avoid

### ❌ Testing Implementation Details

```php
// ❌ Bad: Testing private method
public function testValidateIdentifierCallsRegexMatch(): void
{
    // Don't test how it works
}

// ✅ Good: Testing behavior
public function it_rejects_invalid_identifier(): void
{
    $this->expectException(ValidationException::class);
    $this->validator->validateIdentifier('invalid!name');
}
```

### ❌ Mocking Everything

```php
// ❌ Bad: Over-mocking value objects
$mockRequest = $this->createMock(AssumptionRequest::class);
$mockRequest->method('getTableName')->willReturn('table');

// ✅ Good: Use real value objects
$request = new AssumptionRequest('table', 'field', 'value');
```

### ❌ Not Testing Edge Cases

```php
// ❌ Bad: Only happy path
public function it_validates_ip(): void
{
    $this->assertTrue($this->validator->validate('192.168.1.1', '192.168.1.1'));
}

// ✅ Good: Test edge cases
public function it_handles_null_values(): void { /* ... */ }
public function it_handles_empty_strings(): void { /* ... */ }
public function it_handles_unicode_characters(): void { /* ... */ }
```

---

## Summary

### TDD Core Principles

1. **RED** → Write failing test
2. **GREEN** → Make it pass (simplest code)
3. **REFACTOR** → Improve while staying green

### SOLID Recap

- **S**: Single Responsibility
- **O**: Open/Closed
- **L**: Liskov Substitution
- **I**: Interface Segregation
- **D**: Dependency Inversion

### Clean Code Essentials

- Meaningful names
- Small functions
- Early returns
- Immutability
- Explicit intent

### Testing Guidelines

- Descriptive test names
- Arrange-Act-Assert
- One assertion per concept
- Test independence
- Security-critical tests tagged

---

## Next Steps

1. **Apply these practices** to your implementation
2. **Review code regularly** against this checklist
3. **Refactor continuously** when tests are green
4. **Maintain discipline** - TDD saves time in the long run

---

**Keep tests green, code clean, and architecture solid!** ✅🧪
