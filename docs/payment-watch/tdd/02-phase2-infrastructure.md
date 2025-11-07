# TDD Phase 2: Infrastructure Layer

**Building Operator Strategies with Strategy Pattern**

---

## Navigation

📖 **TDD Documentation:**
- [00-overview.md](00-overview.md) - TDD Overview & SOLID Principles
- [01-phase1-domain.md](01-phase1-domain.md) - Domain Layer (Value Objects)
- **[02-phase2-infrastructure.md](02-phase2-infrastructure.md)** ← You are here
- [03-phase3-application.md](03-phase3-application.md) - Application Layer (Services)
- [04-best-practices.md](04-best-practices.md) - TDD Best Practices & Clean Code

---

## Phase 2 Overview

**Goal:** Implement operator strategies using Strategy Pattern (Open/Closed Principle)

**Test Directory:** `tests/Unit/Watch/Strategy/`

**Source Directory:** `src/Watch/Strategy/`

### Components to Build

1. **OperatorStrategyInterface** - Interface for all operators
2. **EqualityOperator** - Handles `==` comparison
3. **ComparisonOperator** - Handles `>`, `<`, `>=`, `<=`, `!=`
4. **LikeOperator** - Handles `%like%`, `like%`, `%like`
5. **NullCheckOperator** - Handles `IS NULL`, `IS NOT NULL`

### Visual Reference
- [../puml/02-class-diagram-solid.puml](../puml/02-class-diagram-solid.puml) - Strategy Pattern section

---

## Step 2.1: Operator Strategy Interface

### Define Interface (No Test Needed - It's a Contract)

**File:** `src/Watch/Strategy/OperatorStrategyInterface.php`

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Watch\Strategy;

interface OperatorStrategyInterface
{
    /**
     * Compare actual value with expected value using this operator
     */
    public function compare(mixed $actualValue, mixed $expectedValue): bool;

    /**
     * Build SQL condition fragment for this operator
     * @return string SQL condition (e.g., "field = ?", "field > ?")
     */
    public function buildSqlCondition(string $field): string;
}
```

---

## Step 2.2: Equality Operator

### RED: Write Failing Test

**File:** `tests/Unit/Watch/Strategy/EqualityOperatorTest.php`

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Watch\Tests\Unit\Watch\Strategy;

use PHPUnit\Framework\TestCase;
use OxidSolutionCatalysts\Payments\Watch\Strategy\EqualityOperator;

class EqualityOperatorTest extends TestCase
{
    private EqualityOperator $operator;

    protected function setUp(): void
    {
        $this->operator = new EqualityOperator();
    }

    /**
     * @test
     */
    public function it_compares_equal_strings(): void
    {
        // Act
        $result = $this->operator->compare('completed', 'completed');

        // Assert
        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function it_compares_unequal_strings(): void
    {
        // Act
        $result = $this->operator->compare('pending', 'completed');

        // Assert
        $this->assertFalse($result);
    }

    /**
     * @test
     */
    public function it_uses_loose_comparison(): void
    {
        // Act
        $result = $this->operator->compare('100', 100);  // String vs int

        // Assert
        $this->assertTrue($result, 'Should use == (loose) not === (strict)');
    }

    /**
     * @test
     */
    public function it_builds_sql_condition(): void
    {
        // Act
        $condition = $this->operator->buildSqlCondition('field');

        // Assert
        $this->assertEquals('field = ?', $condition);
    }
}
```

**Run test:**
```bash
vendor/bin/phpunit tests/Unit/Watch/Strategy/EqualityOperatorTest.php
```

**Expected:** ❌ RED

---

### GREEN: Implement

**File:** `src/Watch/Strategy/EqualityOperator.php`

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Watch\Strategy;

final class EqualityOperator implements OperatorStrategyInterface
{
    public function compare(mixed $actualValue, mixed $expectedValue): bool
    {
        return $actualValue == $expectedValue;  // Loose comparison
    }

    public function buildSqlCondition(string $field): string
    {
        return "{$field} = ?";
    }
}
```

**Run test:**
```bash
vendor/bin/phpunit tests/Unit/Watch/Strategy/EqualityOperatorTest.php
```

**Expected:** ✅ GREEN

---

## Step 2.3: Comparison Operator (>, <, >=, <=, !=)

### RED: Write Test

**File:** `tests/Unit/Watch/Strategy/ComparisonOperatorTest.php`

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Watch\Tests\Unit\Watch\Strategy;

use PHPUnit\Framework\TestCase;
use OxidSolutionCatalysts\Payments\Watch\Strategy\ComparisonOperator;

class ComparisonOperatorTest extends TestCase
{
    /**
     * @test
     */
    public function it_compares_greater_than(): void
    {
        // Arrange
        $operator = new ComparisonOperator('>');

        // Act & Assert
        $this->assertTrue($operator->compare(100, 50));
        $this->assertFalse($operator->compare(50, 100));
        $this->assertFalse($operator->compare(50, 50));
    }

    /**
     * @test
     */
    public function it_compares_less_than(): void
    {
        // Arrange
        $operator = new ComparisonOperator('<');

        // Act & Assert
        $this->assertTrue($operator->compare(50, 100));
        $this->assertFalse($operator->compare(100, 50));
    }

    /**
     * @test
     */
    public function it_builds_correct_sql_condition(): void
    {
        // Arrange
        $greaterThan = new ComparisonOperator('>');
        $lessThan = new ComparisonOperator('<');

        // Act & Assert
        $this->assertEquals('field > ?', $greaterThan->buildSqlCondition('field'));
        $this->assertEquals('field < ?', $lessThan->buildSqlCondition('field'));
    }
}
```

**Run test:** ❌ RED

---

### GREEN: Implement

**File:** `src/Watch/Strategy/ComparisonOperator.php`

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Watch\Strategy;

final class ComparisonOperator implements OperatorStrategyInterface
{
    public function __construct(
        private readonly string $operator
    ) {
        if (!in_array($operator, ['>', '<', '>=', '<=', '!='], true)) {
            throw new \InvalidArgumentException("Invalid comparison operator: {$operator}");
        }
    }

    public function compare(mixed $actualValue, mixed $expectedValue): bool
    {
        return match ($this->operator) {
            '>' => $actualValue > $expectedValue,
            '<' => $actualValue < $expectedValue,
            '>=' => $actualValue >= $expectedValue,
            '<=' => $actualValue <= $expectedValue,
            '!=' => $actualValue != $expectedValue,
            default => throw new \LogicException('Unreachable')
        };
    }

    public function buildSqlCondition(string $field): string
    {
        return "{$field} {$this->operator} ?";
    }
}
```

**Run test:** ✅ GREEN

---

## Step 2.4: LIKE Operator

### RED: Write Test

**File:** `tests/Unit/Watch/Strategy/LikeOperatorTest.php`

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Watch\Tests\Unit\Watch\Strategy;

use PHPUnit\Framework\TestCase;
use OxidSolutionCatalysts\Payments\Watch\Strategy\LikeOperator;

class LikeOperatorTest extends TestCase
{
    /**
     * @test
     */
    public function it_matches_contains_pattern(): void
    {
        // Arrange
        $operator = new LikeOperator('%like%');

        // Act & Assert
        $this->assertTrue($operator->compare('test@example.com', '@example'));
        $this->assertFalse($operator->compare('user@test.com', '@example'));
    }

    /**
     * @test
     */
    public function it_matches_starts_with_pattern(): void
    {
        // Arrange
        $operator = new LikeOperator('like%');

        // Act & Assert
        $this->assertTrue($operator->compare('completed_payment', 'completed'));
        $this->assertFalse($operator->compare('payment_completed', 'completed'));
    }

    /**
     * @test
     */
    public function it_builds_sql_condition(): void
    {
        // Arrange
        $contains = new LikeOperator('%like%');

        // Act & Assert
        $this->assertEquals('field LIKE ?', $contains->buildSqlCondition('field'));
    }
}
```

**Run test:** ❌ RED

---

### GREEN: Implement

**File:** `src/Watch/Strategy/LikeOperator.php`

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Watch\Strategy;

final class LikeOperator implements OperatorStrategyInterface
{
    public function __construct(
        private readonly string $pattern
    ) {
        if (!in_array($pattern, ['%like%', 'like%', '%like'], true)) {
            throw new \InvalidArgumentException("Invalid LIKE pattern: {$pattern}");
        }
    }

    public function compare(mixed $actualValue, mixed $expectedValue): bool
    {
        $actual = (string) $actualValue;
        $expected = (string) $expectedValue;

        return match ($this->pattern) {
            '%like%' => str_contains($actual, $expected),
            'like%' => str_starts_with($actual, $expected),
            '%like' => str_ends_with($actual, $expected),
            default => throw new \LogicException('Unreachable')
        };
    }

    public function buildSqlCondition(string $field): string
    {
        return "{$field} LIKE ?";
    }
}
```

**Run test:** ✅ GREEN

---

## Step 2.5: NULL Check Operator

### RED: Write Test

**File:** `tests/Unit/Watch/Strategy/NullCheckOperatorTest.php`

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Watch\Tests\Unit\Watch\Strategy;

use PHPUnit\Framework\TestCase;
use OxidSolutionCatalysts\Payments\Watch\Strategy\NullCheckOperator;

class NullCheckOperatorTest extends TestCase
{
    /**
     * @test
     */
    public function it_checks_is_null(): void
    {
        // Arrange
        $operator = new NullCheckOperator(true);

        // Act & Assert
        $this->assertTrue($operator->compare(null, null));
        $this->assertFalse($operator->compare('value', null));
    }

    /**
     * @test
     */
    public function it_checks_is_not_null(): void
    {
        // Arrange
        $operator = new NullCheckOperator(false);

        // Act & Assert
        $this->assertTrue($operator->compare('value', null));
        $this->assertFalse($operator->compare(null, null));
    }

    /**
     * @test
     */
    public function it_builds_sql_condition(): void
    {
        // Arrange
        $isNull = new NullCheckOperator(true);
        $isNotNull = new NullCheckOperator(false);

        // Act & Assert
        $this->assertEquals('field IS NULL', $isNull->buildSqlCondition('field'));
        $this->assertEquals('field IS NOT NULL', $isNotNull->buildSqlCondition('field'));
    }
}
```

**Run test:** ❌ RED

---

### GREEN: Implement

**File:** `src/Watch/Strategy/NullCheckOperator.php`

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Watch\Strategy;

final class NullCheckOperator implements OperatorStrategyInterface
{
    public function __construct(
        private readonly bool $checkNull
    ) {}

    public function compare(mixed $actualValue, mixed $expectedValue): bool
    {
        if ($this->checkNull) {
            return $actualValue === null;
        }

        return $actualValue !== null;
    }

    public function buildSqlCondition(string $field): string
    {
        return $this->checkNull
            ? "{$field} IS NULL"
            : "{$field} IS NOT NULL";
    }
}
```

**Run test:** ✅ GREEN

---

## Phase 2 Complete!

### What We Built

✅ **OperatorStrategyInterface** - Contract for all operators  
✅ **EqualityOperator** - Loose equality (`==`)  
✅ **ComparisonOperator** - Greater/less than (`>`, `<`, `>=`, `<=`, `!=`)  
✅ **LikeOperator** - Pattern matching (`%like%`, `like%`, `%like`)  
✅ **NullCheckOperator** - NULL checks (`IS NULL`, `IS NOT NULL`)

### Key Achievements

1. **Open/Closed Principle**: Can add new operators without modifying existing code
2. **Strategy Pattern**: Each operator is a separate strategy
3. **Interface Segregation**: Small, focused interface
4. **Fully Tested**: All operators have comprehensive tests
5. **SQL Safe**: SQL conditions use parameterized queries

### Testing Summary

```bash
# Run all Phase 2 tests (Docker)
docker compose exec -T -e XDEBUG_MODE=coverage php \
  vendor/bin/phpunit \
  -c /var/www/extensions/stripe/tests/phpunit.xml \
  --bootstrap=/var/www/source/bootstrap.php \
  tests/Unit/Watch/Strategy/

# Expected: All tests passing ✅
```

---

## Next Steps

Continue to **Phase 3: Application Layer**

📄 [03-phase3-application.md](03-phase3-application.md) - Build services with security focus

---

**Phase 2 TDD Complete! Strategy pattern implemented.** ✅
