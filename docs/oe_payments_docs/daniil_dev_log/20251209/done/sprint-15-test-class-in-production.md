# Sprint 15: Remove Test Class Import from Production Code

**Date:** 2025-12-09
**Priority:** CRITICAL
**Status:** PENDING
**Branch:** TBD (b-7.4.x-STRP-XX)
**Est. Effort:** 2 hours

---

## Development Principles Checklist

| Principle | How Applied |
|-----------|-------------|
| **TDD-FIRST** | Write OrderInterface tests first, then implementation |
| **SOLID-SRP** | OrderInterface has single responsibility: order data contract |
| **SOLID-LSP** | Any Order implementation must be substitutable |
| **SOLID-DIP** | Handler depends on OrderInterface, not concrete Order |
| **DI** | OrderFactory injected via constructor |
| **Clean Code** | Interface with clear method signatures |
| **Containerization** | All tests via `docker compose exec` |

---

## Problem Statement

**File:** `src/Component/EventSystem/Handler/OrderCreationHandler.php`
**Line:** 13

```php
use OxidSolutionCatalysts\Payments\Tests\Unit\Component\EventSystem\Handler\Support\Order;
```

**Impact:**
1. Production code depends on test infrastructure
2. Test changes can break production
3. Deployment may fail without test dependencies
4. Violates testing pyramid principle

---

## Root Cause Analysis

The test support class `Order` was created for unit testing but accidentally imported into production code. The handler uses this test class to represent order data.

**Current Usage (lines 57-67):**
```php
$order = new Order(
    $orderId,
    $orderNumber,
    $userId,
    $totalAmount,
    $currency,
    ...
);
```

---

## Solution Design

### Phase 1: TDD - Write Failing Tests First

**New Test File:** `tests/Unit/Component/Order/OrderInterfaceTest.php`

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Unit\Component\Order;

use OxidSolutionCatalysts\Payments\Component\Order\OrderInterface;
use OxidSolutionCatalysts\Payments\Component\Order\Order;
use PHPUnit\Framework\TestCase;

class OrderInterfaceTest extends TestCase
{
    /**
     * @test
     * LSP: Order implements OrderInterface correctly
     */
    public function orderImplementsInterface(): void
    {
        // Arrange
        $order = new Order(
            'order-123',
            '12345',
            'user-456',
            99.99,
            'EUR'
        );

        // Assert - LSP compliance
        $this->assertInstanceOf(OrderInterface::class, $order);
    }

    /**
     * @test
     * SRP: Interface exposes only order data
     */
    public function orderExposesRequiredData(): void
    {
        // Arrange
        $order = new Order(
            'order-123',
            '12345',
            'user-456',
            99.99,
            'EUR'
        );

        // Assert
        $this->assertSame('order-123', $order->getId());
        $this->assertSame('12345', $order->getOrderNumber());
        $this->assertSame('user-456', $order->getUserId());
        $this->assertSame(99.99, $order->getTotalAmount());
        $this->assertSame('EUR', $order->getCurrency());
    }

    /**
     * @test
     * Immutability: Order data cannot be modified
     */
    public function orderIsImmutable(): void
    {
        // Arrange
        $order = new Order(
            'order-123',
            '12345',
            'user-456',
            99.99,
            'EUR'
        );

        // Assert - no setters exist
        $this->assertFalse(method_exists($order, 'setId'));
        $this->assertFalse(method_exists($order, 'setOrderNumber'));
    }
}
```

### Phase 2: Create Interface in Component Layer

**New File:** `src/Component/Order/OrderInterface.php`

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Component\Order;

/**
 * Interface for order data representation
 *
 * SOLID Principles:
 * - SRP: Single responsibility - order data contract
 * - ISP: Minimal interface with essential methods only
 * - LSP: Any implementation must satisfy this contract
 */
interface OrderInterface
{
    public function getId(): string;

    public function getOrderNumber(): string;

    public function getUserId(): string;

    public function getTotalAmount(): float;

    public function getCurrency(): string;

    public function getPaymentId(): ?string;

    public function getTransactionId(): ?string;
}
```

### Phase 3: Create Production Implementation

**New File:** `src/Component/Order/Order.php`

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Component\Order;

/**
 * Immutable order data transfer object
 *
 * SOLID Principles:
 * - SRP: Contains only order data, no behavior
 * - OCP: Closed for modification (immutable)
 * - LSP: Fully substitutable for OrderInterface
 * - DIP: Depends on abstraction (implements interface)
 */
final class Order implements OrderInterface
{
    public function __construct(
        private readonly string $id,
        private readonly string $orderNumber,
        private readonly string $userId,
        private readonly float $totalAmount,
        private readonly string $currency,
        private readonly ?string $paymentId = null,
        private readonly ?string $transactionId = null
    ) {
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getOrderNumber(): string
    {
        return $this->orderNumber;
    }

    public function getUserId(): string
    {
        return $this->userId;
    }

    public function getTotalAmount(): float
    {
        return $this->totalAmount;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getPaymentId(): ?string
    {
        return $this->paymentId;
    }

    public function getTransactionId(): ?string
    {
        return $this->transactionId;
    }
}
```

### Phase 4: Update Handler

**File:** `src/Component/EventSystem/Handler/OrderCreationHandler.php`

```php
// REMOVE this line:
- use OxidSolutionCatalysts\Payments\Tests\Unit\Component\EventSystem\Handler\Support\Order;

// ADD these lines:
+ use OxidSolutionCatalysts\Payments\Component\Order\OrderInterface;
+ use OxidSolutionCatalysts\Payments\Component\Order\Order;
```

### Phase 5: Update Test Support Class

**File:** `tests/Unit/Component/EventSystem/Handler/Support/Order.php`

Option A: Make test class extend production class
```php
namespace OxidSolutionCatalysts\Payments\Tests\Unit\Component\EventSystem\Handler\Support;

use OxidSolutionCatalysts\Payments\Component\Order\Order as ProductionOrder;

class Order extends ProductionOrder
{
    // Add test-specific helpers if needed
}
```

Option B: Delete test class and use production class directly in tests

**Recommendation:** Option B - delete test class, use production Order

---

## Implementation Steps

### Step 1: Write Tests (TDD - RED)

```bash
# Create test file
touch tests/Unit/Component/Order/OrderInterfaceTest.php

# Run tests - should fail (interface doesn't exist)
docker compose exec -T php bash -c "cd /var/www/test-module && vendor/bin/phpunit -c tests/phpunit.xml tests/Unit/Component/Order/OrderInterfaceTest.php"
```

### Step 2: Create Interface (TDD - GREEN)

```bash
# Create interface
mkdir -p src/Component/Order
touch src/Component/Order/OrderInterface.php

# Create implementation
touch src/Component/Order/Order.php

# Run tests - should pass
docker compose exec -T php bash -c "cd /var/www/test-module && vendor/bin/phpunit -c tests/phpunit.xml tests/Unit/Component/Order/OrderInterfaceTest.php"
```

### Step 3: Update Handler

```bash
# Edit handler to use new classes
# Remove test import, add production imports

# Run all unit tests
docker compose exec -T php bash -c "cd /var/www/test-module && vendor/bin/phpunit -c tests/phpunit.xml --testsuite Unit"
```

### Step 4: Clean Up Test Support

```bash
# Delete or update test support class
rm tests/Unit/Component/EventSystem/Handler/Support/Order.php

# Update tests that used the support class
# Run all tests
docker compose exec -T php bash -c "cd /var/www/test-module && vendor/bin/phpunit -c tests/phpunit.xml --testsuite Unit"
```

### Step 5: Quality Checks

```bash
# PHPStan
composer phpstan

# PHPCS
composer phpcs

# Pre-commit check
./bin/pre-commit-check.sh
```

---

## Files to Create/Modify

### New Files

| File | Purpose |
|------|---------|
| `src/Component/Order/OrderInterface.php` | Order data contract |
| `src/Component/Order/Order.php` | Production implementation |
| `tests/Unit/Component/Order/OrderInterfaceTest.php` | Interface tests |

### Modified Files

| File | Change |
|------|--------|
| `src/Component/EventSystem/Handler/OrderCreationHandler.php` | Update imports |

### Deleted Files

| File | Reason |
|------|--------|
| `tests/Unit/Component/EventSystem/Handler/Support/Order.php` | Replaced by production class |

---

## Verification Checklist

- [ ] OrderInterface created in `src/Component/Order/`
- [ ] Order class implements OrderInterface
- [ ] OrderCreationHandler uses production Order class
- [ ] No test class imports in `src/` directory
- [ ] All unit tests pass
- [ ] PHPStan level 6 passes
- [ ] PHPCS (PSR-12) passes

### Verification Command

```bash
# Verify no test imports in production code
grep -rn "Tests\\\\Unit" src/
# Should return: nothing
```

---

## Risk Assessment

| Risk | Impact | Mitigation |
|------|--------|------------|
| Breaking existing tests | Medium | Update tests to use production Order |
| Handler behavior change | Low | Order class has same interface |
| Missing Order methods | Medium | Compare test class with new interface |

---

## Success Criteria

1. ✅ No `Tests\Unit` imports in `src/` directory
2. ✅ `OrderInterface` exists in Component layer
3. ✅ Production `Order` class implements interface
4. ✅ All existing tests pass
5. ✅ PHPStan and PHPCS pass

---

## Related Issues

- CODE_REVIEW.md Section 1.2
- CODE_REVIEW.md Section 4.1
- Architecture principle: Clean separation of production and test code

---

**Last Updated:** 2025-12-09
