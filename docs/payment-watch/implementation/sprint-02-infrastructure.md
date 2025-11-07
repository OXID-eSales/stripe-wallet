# Sprint 2: Infrastructure Layer - Operator Strategies

**Duration:** 1 week
**Team:** 2 developers
**Prerequisites:** Sprint 1 complete (Value Objects with 100% coverage)

---

## Sprint Overview

### Goal
Implement the Infrastructure Layer using the **Strategy Pattern** to handle different SQL comparison operators (==, !=, >, <, >=, <=, LIKE, IS NULL, IS NOT NULL).

### Why Strategy Pattern?
- **Open/Closed Principle:** New operators can be added without modifying existing code
- **Single Responsibility:** Each operator strategy handles one comparison type
- **Testability:** Each strategy can be tested in isolation
- **Flexibility:** Easy to extend with new operators (IN, BETWEEN, etc.)

### Key Deliverables
1. `OperatorStrategyInterface` - Contract for all operators
2. 5 Concrete Strategy Classes:
   - `EqualityOperator` (==, !=)
   - `ComparisonOperator` (>, <, >=, <=)
   - `LikeOperator` (%like%, like%, %like)
   - `NullCheckOperator` (IS NULL, IS NOT NULL)
   - `InOperator` (IN, NOT IN) - Bonus
3. `OperatorStrategyFactory` - Creates appropriate strategy
4. **100% test coverage** for all strategies

---

## Task 2.1: OperatorStrategyInterface

**Time Estimate:** 1 hour
**TDD Cycle:** RED → GREEN → REFACTOR

### RED Phase: Define the Interface Contract

First, write a test that defines what the interface should guarantee.

#### Create Test File
```bash
docker compose exec php bash -c "cat > /var/www/extensions/stripe/tests/Unit/Watch/Infrastructure/OperatorStrategyTest.php << 'EOF'
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Unit\Watch\Infrastructure;

use OxidSolutionCatalysts\Payments\Watch\Infrastructure\OperatorStrategyInterface;
use OxidSolutionCatalysts\Payments\Watch\Infrastructure\EqualityOperator;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OxidSolutionCatalysts\Payments\Watch\Infrastructure\EqualityOperator
 */
class OperatorStrategyTest extends TestCase
{
    /**
     * @test
     * Test that strategies implement the interface
     */
    public function it_implements_operator_strategy_interface(): void
    {
        $strategy = new EqualityOperator();

        $this->assertInstanceOf(OperatorStrategyInterface::class, $strategy);
    }

    /**
     * @test
     * Test that interface defines required methods
     */
    public function it_defines_supports_method(): void
    {
        $strategy = new EqualityOperator();

        $this->assertTrue(method_exists($strategy, 'supports'));
        $this->assertIsBool($strategy->supports('=='));
    }

    /**
     * @test
     * Test that interface defines buildCondition method
     */
    public function it_defines_build_condition_method(): void
    {
        $strategy = new EqualityOperator();

        $this->assertTrue(method_exists($strategy, 'buildCondition'));
    }
}
EOF"
```

#### Run Test (Should Fail)
```bash
docker compose exec -T -e XDEBUG_MODE=coverage php vendor/bin/phpunit \
  -c /var/www/extensions/stripe/tests/phpunit.xml \
  --bootstrap=/var/www/source/bootstrap.php \
  --filter OperatorStrategyTest
```

**Expected:** ❌ Fatal error - Interface not found

### GREEN Phase: Create the Interface

```bash
docker compose exec php bash -c "mkdir -p /var/www/extensions/stripe/src/Watch/Infrastructure"

docker compose exec php bash -c "cat > /var/www/extensions/stripe/src/Watch/Infrastructure/OperatorStrategyInterface.php << 'EOF'
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Watch\Infrastructure;

use Doctrine\DBAL\Query\QueryBuilder;

/**
 * Strategy Pattern for SQL operator implementations
 *
 * Each operator (==, !=, >, <, LIKE, IS NULL, etc.) implements this interface
 * to provide secure SQL condition building using DBAL QueryBuilder.
 */
interface OperatorStrategyInterface
{
    /**
     * Check if this strategy supports the given operator
     *
     * @param string \$operator The operator to check (e.g., '==', 'like%', 'IS NULL')
     * @return bool True if this strategy handles the operator
     */
    public function supports(string \$operator): bool;

    /**
     * Build a secure SQL condition using DBAL QueryBuilder
     *
     * @param QueryBuilder \$qb The query builder instance
     * @param string \$fieldName The database field name (e.g., 'oxordernr')
     * @param mixed \$expectedValue The value to compare against
     * @param string \$paramName Unique parameter name for prepared statement
     * @return string The SQL condition (e.g., 'oxordernr = :param1')
     */
    public function buildCondition(
        QueryBuilder \$qb,
        string \$fieldName,
        mixed \$expectedValue,
        string \$paramName
    ): string;
}
EOF"
```

#### Run Test (Should Pass)
```bash
docker compose exec -T -e XDEBUG_MODE=coverage php vendor/bin/phpunit \
  -c /var/www/extensions/stripe/tests/phpunit.xml \
  --bootstrap=/var/www/source/bootstrap.php \
  --filter OperatorStrategyTest
```

**Expected:** ✅ 3 tests passing

### REFACTOR Phase
- Add PHPDoc comments ✅ (already included)
- Ensure type hints are strict ✅ (already included)
- Verify interface follows SOLID principles ✅

---

## Task 2.2: EqualityOperator Strategy

**Time Estimate:** 2 hours
**TDD Cycle:** RED → GREEN → REFACTOR

### RED Phase: Write Failing Tests

```bash
docker compose exec php bash -c "cat > /var/www/extensions/stripe/tests/Unit/Watch/Infrastructure/EqualityOperatorTest.php << 'EOF'
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Unit\Watch\Infrastructure;

use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Connection;
use OxidSolutionCatalysts\Payments\Watch\Infrastructure\EqualityOperator;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OxidSolutionCatalysts\Payments\Watch\Infrastructure\EqualityOperator
 */
class EqualityOperatorTest extends TestCase
{
    private EqualityOperator \$operator;
    private QueryBuilder \$queryBuilder;

    protected function setUp(): void
    {
        \$connection = \$this->createMock(Connection::class);
        \$this->queryBuilder = new QueryBuilder(\$connection);
        \$this->operator = new EqualityOperator();
    }

    /**
     * @test
     * @dataProvider equalityOperatorProvider
     */
    public function it_supports_equality_operators(string \$operator, bool \$expected): void
    {
        \$this->assertSame(\$expected, \$this->operator->supports(\$operator));
    }

    public function equalityOperatorProvider(): array
    {
        return [
            'equals' => ['==', true],
            'not equals' => ['!=', true],
            'greater than' => ['>', false],
            'less than' => ['<', false],
            'like' => ['%like%', false],
            'is null' => ['IS NULL', false],
        ];
    }

    /**
     * @test
     */
    public function it_builds_equality_condition(): void
    {
        \$condition = \$this->operator->buildCondition(
            \$this->queryBuilder,
            'oxordernr',
            '12345',
            'param1'
        );

        \$this->assertSame('oxordernr = :param1', \$condition);
        \$this->assertSame('12345', \$this->queryBuilder->getParameter('param1'));
    }

    /**
     * @test
     */
    public function it_builds_not_equals_condition(): void
    {
        \$this->operator = new EqualityOperator('!=');

        \$condition = \$this->operator->buildCondition(
            \$this->queryBuilder,
            'oxpaymenttype',
            'oxidpaypal',
            'param2'
        );

        \$this->assertSame('oxpaymenttype != :param2', \$condition);
        \$this->assertSame('oxidpaypal', \$this->queryBuilder->getParameter('param2'));
    }

    /**
     * @test
     */
    public function it_handles_integer_values(): void
    {
        \$condition = \$this->operator->buildCondition(
            \$this->queryBuilder,
            'oxstorno',
            0,
            'param3'
        );

        \$this->assertSame('oxstorno = :param3', \$condition);
        \$this->assertSame(0, \$this->queryBuilder->getParameter('param3'));
    }

    /**
     * @test
     */
    public function it_handles_null_values(): void
    {
        \$condition = \$this->operator->buildCondition(
            \$this->queryBuilder,
            'oxdeltype',
            null,
            'param4'
        );

        \$this->assertSame('oxdeltype = :param4', \$condition);
        \$this->assertNull(\$this->queryBuilder->getParameter('param4'));
    }
}
EOF"
```

#### Run Tests (Should Fail)
```bash
docker compose exec -T -e XDEBUG_MODE=coverage php vendor/bin/phpunit \
  -c /var/www/extensions/stripe/tests/phpunit.xml \
  --bootstrap=/var/www/source/bootstrap.php \
  --filter EqualityOperatorTest
```

**Expected:** ❌ Class not found

### GREEN Phase: Implement EqualityOperator

```bash
docker compose exec php bash -c "cat > /var/www/extensions/stripe/src/Watch/Infrastructure/EqualityOperator.php << 'EOF'
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Watch\Infrastructure;

use Doctrine\DBAL\Query\QueryBuilder;

/**
 * Handles equality operators: == and !=
 *
 * Converts to SQL operators: = and !=
 * Uses parameterized queries to prevent SQL injection
 */
final readonly class EqualityOperator implements OperatorStrategyInterface
{
    public function __construct(
        private string \$operator = '=='
    ) {
    }

    public function supports(string \$operator): bool
    {
        return in_array(\$operator, ['==', '!='], true);
    }

    public function buildCondition(
        QueryBuilder \$qb,
        string \$fieldName,
        mixed \$expectedValue,
        string \$paramName
    ): string {
        // Set parameter value using QueryBuilder
        \$qb->setParameter(\$paramName, \$expectedValue);

        // Convert == to SQL =, keep != as is
        \$sqlOperator = \$this->operator === '==' ? '=' : '!=';

        return sprintf('%s %s :%s', \$fieldName, \$sqlOperator, \$paramName);
    }
}
EOF"
```

#### Run Tests (Should Pass)
```bash
docker compose exec -T -e XDEBUG_MODE=coverage php vendor/bin/phpunit \
  -c /var/www/extensions/stripe/tests/phpunit.xml \
  --bootstrap=/var/www/source/bootstrap.php \
  --filter EqualityOperatorTest
```

**Expected:** ✅ 8 tests passing

### REFACTOR Phase
- Make class `readonly` for immutability ✅
- Use `sprintf()` for SQL formatting ✅
- Add type hints ✅

---

## Task 2.3: ComparisonOperator Strategy

**Time Estimate:** 2 hours
**TDD Cycle:** RED → GREEN → REFACTOR

### RED Phase: Write Failing Tests

```bash
docker compose exec php bash -c "cat > /var/www/extensions/stripe/tests/Unit/Watch/Infrastructure/ComparisonOperatorTest.php << 'EOF'
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Unit\Watch\Infrastructure;

use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Connection;
use OxidSolutionCatalysts\Payments\Watch\Infrastructure\ComparisonOperator;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OxidSolutionCatalysts\Payments\Watch\Infrastructure\ComparisonOperator
 */
class ComparisonOperatorTest extends TestCase
{
    private ComparisonOperator \$operator;
    private QueryBuilder \$queryBuilder;

    protected function setUp(): void
    {
        \$connection = \$this->createMock(Connection::class);
        \$this->queryBuilder = new QueryBuilder(\$connection);
        \$this->operator = new ComparisonOperator();
    }

    /**
     * @test
     * @dataProvider comparisonOperatorProvider
     */
    public function it_supports_comparison_operators(string \$operator, bool \$expected): void
    {
        \$this->assertSame(\$expected, \$this->operator->supports(\$operator));
    }

    public function comparisonOperatorProvider(): array
    {
        return [
            'greater than' => ['>', true],
            'less than' => ['<', true],
            'greater or equal' => ['>=', true],
            'less or equal' => ['<=', true],
            'equals' => ['==', false],
            'not equals' => ['!=', false],
            'like' => ['%like%', false],
        ];
    }

    /**
     * @test
     */
    public function it_builds_greater_than_condition(): void
    {
        \$this->operator = new ComparisonOperator('>');

        \$condition = \$this->operator->buildCondition(
            \$this->queryBuilder,
            'oxtotalordersum',
            100.50,
            'param1'
        );

        \$this->assertSame('oxtotalordersum > :param1', \$condition);
        \$this->assertSame(100.50, \$this->queryBuilder->getParameter('param1'));
    }

    /**
     * @test
     */
    public function it_builds_less_than_condition(): void
    {
        \$this->operator = new ComparisonOperator('<');

        \$condition = \$this->operator->buildCondition(
            \$this->queryBuilder,
            'oxdelcost',
            5.00,
            'param2'
        );

        \$this->assertSame('oxdelcost < :param2', \$condition);
    }

    /**
     * @test
     */
    public function it_builds_greater_or_equal_condition(): void
    {
        \$this->operator = new ComparisonOperator('>=');

        \$condition = \$this->operator->buildCondition(
            \$this->queryBuilder,
            'oxartnum',
            1000,
            'param3'
        );

        \$this->assertSame('oxartnum >= :param3', \$condition);
        \$this->assertSame(1000, \$this->queryBuilder->getParameter('param3'));
    }

    /**
     * @test
     */
    public function it_builds_less_or_equal_condition(): void
    {
        \$this->operator = new ComparisonOperator('<=');

        \$condition = \$this->operator->buildCondition(
            \$this->queryBuilder,
            'oxstock',
            50,
            'param4'
        );

        \$this->assertSame('oxstock <= :param4', \$condition);
    }
}
EOF"
```

#### Run Tests
```bash
docker compose exec -T -e XDEBUG_MODE=coverage php vendor/bin/phpunit \
  -c /var/www/extensions/stripe/tests/phpunit.xml \
  --bootstrap=/var/www/source/bootstrap.php \
  --filter ComparisonOperatorTest
```

**Expected:** ❌ Class not found

### GREEN Phase: Implement ComparisonOperator

```bash
docker compose exec php bash -c "cat > /var/www/extensions/stripe/src/Watch/Infrastructure/ComparisonOperator.php << 'EOF'
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Watch\Infrastructure;

use Doctrine\DBAL\Query\QueryBuilder;

/**
 * Handles comparison operators: >, <, >=, <=
 *
 * Used for numeric comparisons (prices, quantities, dates)
 * Uses parameterized queries to prevent SQL injection
 */
final readonly class ComparisonOperator implements OperatorStrategyInterface
{
    public function __construct(
        private string \$operator = '>'
    ) {
    }

    public function supports(string \$operator): bool
    {
        return in_array(\$operator, ['>', '<', '>=', '<='], true);
    }

    public function buildCondition(
        QueryBuilder \$qb,
        string \$fieldName,
        mixed \$expectedValue,
        string \$paramName
    ): string {
        \$qb->setParameter(\$paramName, \$expectedValue);

        return sprintf('%s %s :%s', \$fieldName, \$this->operator, \$paramName);
    }
}
EOF"
```

#### Run Tests
```bash
docker compose exec -T -e XDEBUG_MODE=coverage php vendor/bin/phpunit \
  -c /var/www/extensions/stripe/tests/phpunit.xml \
  --bootstrap=/var/www/source/bootstrap.php \
  --filter ComparisonOperatorTest
```

**Expected:** ✅ 8 tests passing

---

## Task 2.4: LikeOperator Strategy

**Time Estimate:** 3 hours
**TDD Cycle:** RED → GREEN → REFACTOR

### RED Phase: Write Failing Tests

```bash
docker compose exec php bash -c "cat > /var/www/extensions/stripe/tests/Unit/Watch/Infrastructure/LikeOperatorTest.php << 'EOF'
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Unit\Watch\Infrastructure;

use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Connection;
use OxidSolutionCatalysts\Payments\Watch\Infrastructure\LikeOperator;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OxidSolutionCatalysts\Payments\Watch\Infrastructure\LikeOperator
 */
class LikeOperatorTest extends TestCase
{
    private LikeOperator \$operator;
    private QueryBuilder \$queryBuilder;

    protected function setUp(): void
    {
        \$connection = \$this->createMock(Connection::class);
        \$this->queryBuilder = new QueryBuilder(\$connection);
        \$this->operator = new LikeOperator();
    }

    /**
     * @test
     * @dataProvider likeOperatorProvider
     */
    public function it_supports_like_operators(string \$operator, bool \$expected): void
    {
        \$this->assertSame(\$expected, \$this->operator->supports(\$operator));
    }

    public function likeOperatorProvider(): array
    {
        return [
            'contains' => ['%like%', true],
            'starts with' => ['like%', true],
            'ends with' => ['%like', true],
            'equals' => ['==', false],
            'greater than' => ['>', false],
        ];
    }

    /**
     * @test
     * LIKE with wildcards on both sides: %value%
     */
    public function it_builds_contains_condition(): void
    {
        \$this->operator = new LikeOperator('%like%');

        \$condition = \$this->operator->buildCondition(
            \$this->queryBuilder,
            'oxbilllname',
            'Smith',
            'param1'
        );

        \$this->assertSame('oxbilllname LIKE :param1', \$condition);
        \$this->assertSame('%Smith%', \$this->queryBuilder->getParameter('param1'));
    }

    /**
     * @test
     * LIKE with wildcard at end: value%
     */
    public function it_builds_starts_with_condition(): void
    {
        \$this->operator = new LikeOperator('like%');

        \$condition = \$this->operator->buildCondition(
            \$this->queryBuilder,
            'oxordernr',
            '2024',
            'param2'
        );

        \$this->assertSame('oxordernr LIKE :param2', \$condition);
        \$this->assertSame('2024%', \$this->queryBuilder->getParameter('param2'));
    }

    /**
     * @test
     * LIKE with wildcard at start: %value
     */
    public function it_builds_ends_with_condition(): void
    {
        \$this->operator = new LikeOperator('%like');

        \$condition = \$this->operator->buildCondition(
            \$this->queryBuilder,
            'oxbillemail',
            '@example.com',
            'param3'
        );

        \$this->assertSame('oxbillemail LIKE :param3', \$condition);
        \$this->assertSame('%@example.com', \$this->queryBuilder->getParameter('param3'));
    }

    /**
     * @test
     * Security: Escape SQL wildcards in user input
     */
    public function it_escapes_wildcards_in_values(): void
    {
        \$this->operator = new LikeOperator('%like%');

        \$condition = \$this->operator->buildCondition(
            \$this->queryBuilder,
            'oxremark',
            '50% discount',
            'param4'
        );

        // % should be escaped to \%
        \$this->assertSame('%50\\% discount%', \$this->queryBuilder->getParameter('param4'));
    }

    /**
     * @test
     * Security: Escape underscore wildcards
     */
    public function it_escapes_underscores_in_values(): void
    {
        \$this->operator = new LikeOperator('%like%');

        \$condition = \$this->operator->buildCondition(
            \$this->queryBuilder,
            'oxartnum',
            'SKU_123',
            'param5'
        );

        // _ should be escaped to \_
        \$this->assertSame('%SKU\\_123%', \$this->queryBuilder->getParameter('param5'));
    }
}
EOF"
```

#### Run Tests
```bash
docker compose exec -T -e XDEBUG_MODE=coverage php vendor/bin/phpunit \
  -c /var/www/extensions/stripe/tests/phpunit.xml \
  --bootstrap=/var/www/source/bootstrap.php \
  --filter LikeOperatorTest
```

**Expected:** ❌ Class not found

### GREEN Phase: Implement LikeOperator

```bash
docker compose exec php bash -c "cat > /var/www/extensions/stripe/src/Watch/Infrastructure/LikeOperator.php << 'EOF'
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Watch\Infrastructure;

use Doctrine\DBAL\Query\QueryBuilder;

/**
 * Handles LIKE operators: %like%, like%, %like
 *
 * Pattern matching for partial string matches
 * SECURITY: Escapes SQL wildcards (%, _) in user input to prevent injection
 *
 * Examples:
 * - %like% → LIKE '%value%' (contains)
 * - like%  → LIKE 'value%'  (starts with)
 * - %like  → LIKE '%value'  (ends with)
 */
final readonly class LikeOperator implements OperatorStrategyInterface
{
    public function __construct(
        private string \$operator = '%like%'
    ) {
    }

    public function supports(string \$operator): bool
    {
        return in_array(\$operator, ['%like%', 'like%', '%like'], true);
    }

    public function buildCondition(
        QueryBuilder \$qb,
        string \$fieldName,
        mixed \$expectedValue,
        string \$paramName
    ): string {
        // Escape SQL wildcards in user input to prevent LIKE injection
        \$escapedValue = $this->escapeLikeWildcards((string) \$expectedValue);

        // Add wildcards based on operator pattern
        \$likeValue = match (\$this->operator) {
            '%like%' => '%' . \$escapedValue . '%',  // Contains
            'like%'  => \$escapedValue . '%',         // Starts with
            '%like'  => '%' . \$escapedValue,         // Ends with
            default  => \$escapedValue,
        };

        \$qb->setParameter(\$paramName, \$likeValue);

        return sprintf('%s LIKE :%s', \$fieldName, \$paramName);
    }

    /**
     * Escape SQL LIKE wildcards to prevent injection
     *
     * User input "50% discount" should match literal "50% discount"
     * not "50[any char] discount"
     */
    private function escapeLikeWildcards(string \$value): string
    {
        return str_replace(
            ['\\\\', '%', '_'],
            ['\\\\\\\\', '\\\\%', '\\\\_'],
            \$value
        );
    }
}
EOF"
```

#### Run Tests
```bash
docker compose exec -T -e XDEBUG_MODE=coverage php vendor/bin/phpunit \
  -c /var/www/extensions/stripe/tests/phpunit.xml \
  --bootstrap=/var/www/source/bootstrap.php \
  --filter LikeOperatorTest
```

**Expected:** ✅ 9 tests passing

---

## Task 2.5: NullCheckOperator Strategy

**Time Estimate:** 1.5 hours
**TDD Cycle:** RED → GREEN → REFACTOR

### RED Phase: Write Failing Tests

```bash
docker compose exec php bash -c "cat > /var/www/extensions/stripe/tests/Unit/Watch/Infrastructure/NullCheckOperatorTest.php << 'EOF'
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Unit\Watch\Infrastructure;

use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Connection;
use OxidSolutionCatalysts\Payments\Watch\Infrastructure\NullCheckOperator;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OxidSolutionCatalysts\Payments\Watch\Infrastructure\NullCheckOperator
 */
class NullCheckOperatorTest extends TestCase
{
    private NullCheckOperator \$operator;
    private QueryBuilder \$queryBuilder;

    protected function setUp(): void
    {
        \$connection = \$this->createMock(Connection::class);
        \$this->queryBuilder = new QueryBuilder(\$connection);
        \$this->operator = new NullCheckOperator();
    }

    /**
     * @test
     * @dataProvider nullOperatorProvider
     */
    public function it_supports_null_operators(string \$operator, bool \$expected): void
    {
        \$this->assertSame(\$expected, \$this->operator->supports(\$operator));
    }

    public function nullOperatorProvider(): array
    {
        return [
            'is null uppercase' => ['IS NULL', true],
            'is not null uppercase' => ['IS NOT NULL', true],
            'is null lowercase' => ['is null', true],
            'is not null lowercase' => ['is not null', true],
            'equals' => ['==', false],
            'like' => ['%like%', false],
        ];
    }

    /**
     * @test
     * IS NULL does not require parameters
     */
    public function it_builds_is_null_condition(): void
    {
        \$this->operator = new NullCheckOperator('IS NULL');

        \$condition = \$this->operator->buildCondition(
            \$this->queryBuilder,
            'oxdeltype',
            null,
            'param1'
        );

        \$this->assertSame('oxdeltype IS NULL', \$condition);
        \$this->assertEmpty(\$this->queryBuilder->getParameters());
    }

    /**
     * @test
     * IS NOT NULL does not require parameters
     */
    public function it_builds_is_not_null_condition(): void
    {
        \$this->operator = new NullCheckOperator('IS NOT NULL');

        \$condition = \$this->operator->buildCondition(
            \$this->queryBuilder,
            'oxtrackcode',
            null,
            'param2'
        );

        \$this->assertSame('oxtrackcode IS NOT NULL', \$condition);
        \$this->assertEmpty(\$this->queryBuilder->getParameters());
    }

    /**
     * @test
     * Case insensitive operator support
     */
    public function it_handles_lowercase_operators(): void
    {
        \$this->operator = new NullCheckOperator('is null');

        \$condition = \$this->operator->buildCondition(
            \$this->queryBuilder,
            'oxremark',
            null,
            'param3'
        );

        \$this->assertSame('oxremark IS NULL', \$condition);
    }
}
EOF"
```

#### Run Tests
```bash
docker compose exec -T -e XDEBUG_MODE=coverage php vendor/bin/phpunit \
  -c /var/www/extensions/stripe/tests/phpunit.xml \
  --bootstrap=/var/www/source/bootstrap.php \
  --filter NullCheckOperatorTest
```

**Expected:** ❌ Class not found

### GREEN Phase: Implement NullCheckOperator

```bash
docker compose exec php bash -c "cat > /var/www/extensions/stripe/src/Watch/Infrastructure/NullCheckOperator.php << 'EOF'
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Watch\Infrastructure;

use Doctrine\DBAL\Query\QueryBuilder;

/**
 * Handles NULL check operators: IS NULL, IS NOT NULL
 *
 * These operators do not require parameters (no placeholders needed)
 * Case-insensitive operator matching
 */
final readonly class NullCheckOperator implements OperatorStrategyInterface
{
    public function __construct(
        private string \$operator = 'IS NULL'
    ) {
    }

    public function supports(string \$operator): bool
    {
        \$normalizedOperator = strtoupper(\$operator);
        return in_array(\$normalizedOperator, ['IS NULL', 'IS NOT NULL'], true);
    }

    public function buildCondition(
        QueryBuilder \$qb,
        string \$fieldName,
        mixed \$expectedValue,
        string \$paramName
    ): string {
        // No parameters needed for NULL checks
        // Just return the field name and operator
        \$normalizedOperator = strtoupper(\$this->operator);

        return sprintf('%s %s', \$fieldName, \$normalizedOperator);
    }
}
EOF"
```

#### Run Tests
```bash
docker compose exec -T -e XDEBUG_MODE=coverage php vendor/bin/phpunit \
  -c /var/www/extensions/stripe/tests/phpunit.xml \
  --bootstrap=/var/www/source/bootstrap.php \
  --filter NullCheckOperatorTest
```

**Expected:** ✅ 6 tests passing

---

## Task 2.6: InOperator Strategy (Bonus)

**Time Estimate:** 2 hours
**TDD Cycle:** RED → GREEN → REFACTOR

### RED Phase: Write Failing Tests

```bash
docker compose exec php bash -c "cat > /var/www/extensions/stripe/tests/Unit/Watch/Infrastructure/InOperatorTest.php << 'EOF'
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Unit\Watch\Infrastructure;

use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Connection;
use OxidSolutionCatalysts\Payments\Watch\Infrastructure\InOperator;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OxidSolutionCatalysts\Payments\Watch\Infrastructure\InOperator
 */
class InOperatorTest extends TestCase
{
    private InOperator \$operator;
    private QueryBuilder \$queryBuilder;

    protected function setUp(): void
    {
        \$connection = \$this->createMock(Connection::class);
        \$this->queryBuilder = new QueryBuilder(\$connection);
        \$this->operator = new InOperator();
    }

    /**
     * @test
     * @dataProvider inOperatorProvider
     */
    public function it_supports_in_operators(string \$operator, bool \$expected): void
    {
        \$this->assertSame(\$expected, \$this->operator->supports(\$operator));
    }

    public function inOperatorProvider(): array
    {
        return [
            'in uppercase' => ['IN', true],
            'not in uppercase' => ['NOT IN', true],
            'in lowercase' => ['in', true],
            'not in lowercase' => ['not in', true],
            'equals' => ['==', false],
        ];
    }

    /**
     * @test
     */
    public function it_builds_in_condition_with_array(): void
    {
        \$this->operator = new InOperator('IN');

        \$condition = \$this->operator->buildCondition(
            \$this->queryBuilder,
            'oxpaymenttype',
            ['oxidpaypal', 'oxidstripe', 'oxidcreditcard'],
            'param1'
        );

        \$this->assertSame('oxpaymenttype IN (:param1)', \$condition);
        \$this->assertSame(
            ['oxidpaypal', 'oxidstripe', 'oxidcreditcard'],
            \$this->queryBuilder->getParameter('param1')
        );
    }

    /**
     * @test
     */
    public function it_builds_not_in_condition(): void
    {
        \$this->operator = new InOperator('NOT IN');

        \$condition = \$this->operator->buildCondition(
            \$this->queryBuilder,
            'oxstorno',
            [1, -1],
            'param2'
        );

        \$this->assertSame('oxstorno NOT IN (:param2)', \$condition);
        \$this->assertSame([1, -1], \$this->queryBuilder->getParameter('param2'));
    }

    /**
     * @test
     */
    public function it_handles_single_value_as_array(): void
    {
        \$this->operator = new InOperator('IN');

        \$condition = \$this->operator->buildCondition(
            \$this->queryBuilder,
            'oxcountryid',
            'oxcountry:8f241f11096877ac0.98748826', // Germany
            'param3'
        );

        \$this->assertSame('oxcountryid IN (:param3)', \$condition);
        \$this->assertSame(
            ['oxcountry:8f241f11096877ac0.98748826'],
            \$this->queryBuilder->getParameter('param3')
        );
    }
}
EOF"
```

#### Run Tests
```bash
docker compose exec -T -e XDEBUG_MODE=coverage php vendor/bin/phpunit \
  -c /var/www/extensions/stripe/tests/phpunit.xml \
  --bootstrap=/var/www/source/bootstrap.php \
  --filter InOperatorTest
```

**Expected:** ❌ Class not found

### GREEN Phase: Implement InOperator

```bash
docker compose exec php bash -c "cat > /var/www/extensions/stripe/src/Watch/Infrastructure/InOperator.php << 'EOF'
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Watch\Infrastructure;

use Doctrine\DBAL\Query\QueryBuilder;

/**
 * Handles IN operators: IN, NOT IN
 *
 * Checks if field value is in a list of values
 * Automatically converts single values to arrays
 * Case-insensitive operator matching
 */
final readonly class InOperator implements OperatorStrategyInterface
{
    public function __construct(
        private string \$operator = 'IN'
    ) {
    }

    public function supports(string \$operator): bool
    {
        \$normalizedOperator = strtoupper(\$operator);
        return in_array(\$normalizedOperator, ['IN', 'NOT IN'], true);
    }

    public function buildCondition(
        QueryBuilder \$qb,
        string \$fieldName,
        mixed \$expectedValue,
        string \$paramName
    ): string {
        // Convert single value to array for consistency
        \$values = is_array(\$expectedValue) ? \$expectedValue : [\$expectedValue];

        \$qb->setParameter(\$paramName, \$values);

        \$normalizedOperator = strtoupper(\$this->operator);

        return sprintf('%s %s (:%s)', \$fieldName, \$normalizedOperator, \$paramName);
    }
}
EOF"
```

#### Run Tests
```bash
docker compose exec -T -e XDEBUG_MODE=coverage php vendor/bin/phpunit \
  -c /var/www/extensions/stripe/tests/phpunit.xml \
  --bootstrap=/var/www/source/bootstrap.php \
  --filter InOperatorTest
```

**Expected:** ✅ 6 tests passing

---

## Task 2.7: OperatorStrategyFactory

**Time Estimate:** 2 hours
**TDD Cycle:** RED → GREEN → REFACTOR

### RED Phase: Write Failing Tests

```bash
docker compose exec php bash -c "cat > /var/www/extensions/stripe/tests/Unit/Watch/Infrastructure/OperatorStrategyFactoryTest.php << 'EOF'
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Unit\Watch\Infrastructure;

use OxidSolutionCatalysts\Payments\Watch\Infrastructure\OperatorStrategyFactory;
use OxidSolutionCatalysts\Payments\Watch\Infrastructure\EqualityOperator;
use OxidSolutionCatalysts\Payments\Watch\Infrastructure\ComparisonOperator;
use OxidSolutionCatalysts\Payments\Watch\Infrastructure\LikeOperator;
use OxidSolutionCatalysts\Payments\Watch\Infrastructure\NullCheckOperator;
use OxidSolutionCatalysts\Payments\Watch\Infrastructure\InOperator;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OxidSolutionCatalysts\Payments\Watch\Infrastructure\OperatorStrategyFactory
 */
class OperatorStrategyFactoryTest extends TestCase
{
    private OperatorStrategyFactory \$factory;

    protected function setUp(): void
    {
        \$this->factory = new OperatorStrategyFactory();
    }

    /**
     * @test
     * @dataProvider operatorStrategyProvider
     */
    public function it_creates_correct_strategy_for_operator(
        string \$operator,
        string \$expectedClass
    ): void {
        \$strategy = \$this->factory->createStrategy(\$operator);

        \$this->assertInstanceOf(\$expectedClass, \$strategy);
    }

    public function operatorStrategyProvider(): array
    {
        return [
            'equality ==' => ['==', EqualityOperator::class],
            'not equals' => ['!=', EqualityOperator::class],
            'greater than' => ['>', ComparisonOperator::class],
            'less than' => ['<', ComparisonOperator::class],
            'greater or equal' => ['>=', ComparisonOperator::class],
            'less or equal' => ['<=', ComparisonOperator::class],
            'like contains' => ['%like%', LikeOperator::class],
            'like starts' => ['like%', LikeOperator::class],
            'like ends' => ['%like', LikeOperator::class],
            'is null' => ['IS NULL', NullCheckOperator::class],
            'is not null' => ['IS NOT NULL', NullCheckOperator::class],
            'in' => ['IN', InOperator::class],
            'not in' => ['NOT IN', InOperator::class],
        ];
    }

    /**
     * @test
     */
    public function it_throws_exception_for_unsupported_operator(): void
    {
        \$this->expectException(\InvalidArgumentException::class);
        \$this->expectExceptionMessage('Unsupported operator: INVALID');

        \$this->factory->createStrategy('INVALID');
    }

    /**
     * @test
     */
    public function it_returns_list_of_supported_operators(): void
    {
        \$operators = \$this->factory->getSupportedOperators();

        \$this->assertIsArray(\$operators);
        \$this->assertContains('==', \$operators);
        \$this->assertContains('!=', \$operators);
        \$this->assertContains('>', \$operators);
        \$this->assertContains('%like%', \$operators);
        \$this->assertContains('IS NULL', \$operators);
        \$this->assertContains('IN', \$operators);
    }
}
EOF"
```

#### Run Tests
```bash
docker compose exec -T -e XDEBUG_MODE=coverage php vendor/bin/phpunit \
  -c /var/www/extensions/stripe/tests/phpunit.xml \
  --bootstrap=/var/www/source/bootstrap.php \
  --filter OperatorStrategyFactoryTest
```

**Expected:** ❌ Class not found

### GREEN Phase: Implement OperatorStrategyFactory

```bash
docker compose exec php bash -c "cat > /var/www/extensions/stripe/src/Watch/Infrastructure/OperatorStrategyFactory.php << 'EOF'
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Watch\Infrastructure;

use InvalidArgumentException;

/**
 * Factory for creating operator strategies
 *
 * Uses Strategy Pattern + Factory Pattern to instantiate
 * the correct operator handler based on the operator string.
 *
 * Open/Closed Principle: Add new strategies by registering them here
 * without modifying existing strategy classes.
 */
final class OperatorStrategyFactory
{
    /**
     * @var array<string, class-string<OperatorStrategyInterface>>
     */
    private const STRATEGY_MAP = [
        '==' => EqualityOperator::class,
        '!=' => EqualityOperator::class,
        '>' => ComparisonOperator::class,
        '<' => ComparisonOperator::class,
        '>=' => ComparisonOperator::class,
        '<=' => ComparisonOperator::class,
        '%like%' => LikeOperator::class,
        'like%' => LikeOperator::class,
        '%like' => LikeOperator::class,
        'IS NULL' => NullCheckOperator::class,
        'IS NOT NULL' => NullCheckOperator::class,
        'IN' => InOperator::class,
        'NOT IN' => InOperator::class,
    ];

    /**
     * Create the appropriate strategy for the given operator
     *
     * @throws InvalidArgumentException If operator is not supported
     */
    public function createStrategy(string \$operator): OperatorStrategyInterface
    {
        // Normalize case for NULL and IN operators
        \$normalizedOperator = \$this->normalizeOperator(\$operator);

        if (!isset(self::STRATEGY_MAP[\$normalizedOperator])) {
            throw new InvalidArgumentException(
                sprintf('Unsupported operator: %s', \$operator)
            );
        }

        \$strategyClass = self::STRATEGY_MAP[\$normalizedOperator];

        return new \$strategyClass(\$operator);
    }

    /**
     * Get list of all supported operators
     *
     * @return string[]
     */
    public function getSupportedOperators(): array
    {
        return array_keys(self::STRATEGY_MAP);
    }

    /**
     * Normalize operator for case-insensitive matching
     */
    private function normalizeOperator(string \$operator): string
    {
        // Convert NULL and IN operators to uppercase for consistency
        \$upper = strtoupper(\$operator);

        if (in_array(\$upper, ['IS NULL', 'IS NOT NULL', 'IN', 'NOT IN'], true)) {
            return \$upper;
        }

        return \$operator;
    }
}
EOF"
```

#### Run Tests
```bash
docker compose exec -T -e XDEBUG_MODE=coverage php vendor/bin/phpunit \
  -c /var/www/extensions/stripe/tests/phpunit.xml \
  --bootstrap=/var/www/source/bootstrap.php \
  --filter OperatorStrategyFactoryTest
```

**Expected:** ✅ 15 tests passing

### REFACTOR Phase
- Extract `STRATEGY_MAP` as const for Open/Closed Principle ✅
- Add `getSupportedOperators()` for documentation ✅
- Add operator normalization for case-insensitive matching ✅

---

## Sprint 2 Deliverables

### Code Files Created
```
src/Watch/Infrastructure/
├── OperatorStrategyInterface.php
├── EqualityOperator.php
├── ComparisonOperator.php
├── LikeOperator.php
├── NullCheckOperator.php
├── InOperator.php
└── OperatorStrategyFactory.php
```

### Test Files Created
```
tests/Unit/Watch/Infrastructure/
├── OperatorStrategyTest.php (3 tests)
├── EqualityOperatorTest.php (8 tests)
├── ComparisonOperatorTest.php (8 tests)
├── LikeOperatorTest.php (9 tests)
├── NullCheckOperatorTest.php (6 tests)
├── InOperatorTest.php (6 tests)
└── OperatorStrategyFactoryTest.php (15 tests)
```

**Total:** 55 tests

---

## Acceptance Criteria

### Functionality
- ✅ All 5 operator strategies implemented
- ✅ Factory creates correct strategy for each operator
- ✅ Strategies use QueryBuilder for parameterized queries
- ✅ LIKE wildcards properly escaped

### Test Coverage
- ✅ >= 95% coverage for Infrastructure layer
- ✅ All operators tested with multiple data types
- ✅ Security tests for LIKE wildcard escaping

### Code Quality
- ✅ All classes are `readonly` (immutable)
- ✅ Strategy Pattern correctly implemented
- ✅ Factory Pattern with type-safe map
- ✅ PHPDoc comments on all public methods

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

**Expected:** ✅ 73 tests passing (18 from Sprint 1 + 55 from Sprint 2)

### Check Coverage
```bash
docker compose exec -T -e XDEBUG_MODE=coverage php vendor/bin/phpunit \
  -c /var/www/extensions/stripe/tests/phpunit.xml \
  --bootstrap=/var/www/source/bootstrap.php \
  --testsuite Unit \
  --filter Watch \
  --coverage-text
```

**Expected:** >= 95% coverage for Infrastructure

---

## Sprint Review

### Demo Checklist
- [ ] Show strategy interface and implementations
- [ ] Demonstrate factory creating strategies
- [ ] Run test suite with 73 passing tests
- [ ] Show coverage report >= 95%
- [ ] Explain Strategy Pattern benefits

### Retrospective Questions
1. Did the Strategy Pattern make operators easy to test?
2. Is the factory map clear and extensible?
3. Are LIKE wildcards properly secured?
4. Any operators missing that we should add?

---

## Common Issues

### Issue: LIKE wildcard escaping not working
**Solution:** Check that backslashes are properly escaped in PHP strings. Use `str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value)`.

### Issue: Factory not finding strategy
**Solution:** Ensure operator is properly normalized (uppercase for NULL/IN operators).

### Issue: QueryBuilder parameter collision
**Solution:** Use unique parameter names (`param1`, `param2`, etc.) from caller.

---

## Next Sprint

**Ready for [Sprint 3: Security Services](sprint-03-security.md)**

Sprint 3 will implement:
- RequestValidator (SQL injection prevention) 🔒
- ApiKeyValidator (timing attack prevention) 🔒
- IpValidator (CIDR range support)
- AssumptionParser
- AuthenticationService

---

**Sprint 2 Complete! 🎉**
**Tests:** 55 new tests (73 total)
**Coverage:** >= 95%
**Next:** Security Services (Week 4)
