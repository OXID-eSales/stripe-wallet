# Sprint 16: Extract OrderPaymentStateService (OXPAID Consolidation)

**Date:** 2025-12-09
**Priority:** CRITICAL
**Status:** PENDING
**Branch:** TBD (b-7.4.x-STRP-XX)
**Est. Effort:** 3 hours

---

## Development Principles Checklist

| Principle | How Applied |
|-----------|-------------|
| **TDD-FIRST** | Write service tests first, then implementation |
| **SOLID-SRP** | Service has single responsibility: OXPAID updates |
| **SOLID-OCP** | Service open for extension (new date sources) |
| **SOLID-DIP** | Service depends on Connection interface |
| **DI** | Connection injected via constructor |
| **DRY** | Single location for OXPAID update logic |
| **Clean Code** | One method ≤ 25 lines |
| **Containerization** | All tests via `docker compose exec` |

---

## Problem Statement

**OXPAID is updated in 4 different locations with 3 different date handling approaches:**

| Location | Method | Date Source | Issue |
|----------|--------|-------------|-------|
| `StripeOrderCreationHandler.php:163` | PHP `date()` | Current time | Correct |
| `OrderPaymentCompletedHandler.php:79` | MySQL `NOW()` | Database time | Timezone mismatch |
| `PaymentIntentSucceededHandler.php:130` | Stripe timestamp | Provider time | Different source |
| `OxpaidReconciliationService.php` | Stripe timestamp | Provider time | Different source |

**Code Pattern (repeated 4x with variations):**
```php
$sql = "UPDATE oxorder SET OXPAID = :paid WHERE OXID = :orderId";
$this->connection->executeStatement($sql, [...]);
```

**DRY Violation Score:** 9/10

---

## Root Cause Analysis

1. **No single source of truth** for OXPAID updates
2. **Different date handling** leads to timezone inconsistencies
3. **Multiple lookup strategies** (OXID vs OXTRANSID)
4. **No documented strategy** for which handler updates OXPAID

---

## Solution Design

### Phase 1: TDD - Write Failing Tests First

**New Test File:** `tests/Unit/Component/Service/OrderPaymentStateServiceTest.php`

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Unit\Component\Service;

use Doctrine\DBAL\Connection;
use OxidSolutionCatalysts\Payments\Component\Service\OrderPaymentStateService;
use OxidSolutionCatalysts\Payments\Component\Service\OrderPaymentStateServiceInterface;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use DateTimeImmutable;

class OrderPaymentStateServiceTest extends TestCase
{
    private Connection&MockObject $connection;
    private OrderPaymentStateService $service;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(Connection::class);
        $this->service = new OrderPaymentStateService($this->connection);
    }

    /**
     * @test
     * LSP: Service implements interface
     */
    public function implementsInterface(): void
    {
        $this->assertInstanceOf(
            OrderPaymentStateServiceInterface::class,
            $this->service
        );
    }

    /**
     * @test
     * SRP: Updates OXPAID with provided timestamp
     */
    public function updatesPaidTimestampWithProvidedTime(): void
    {
        // Arrange
        $orderId = 'order-123';
        $paidAt = new DateTimeImmutable('2025-12-09 14:30:00');

        $this->connection
            ->expects($this->once())
            ->method('executeStatement')
            ->with(
                $this->stringContains('UPDATE oxorder SET OXPAID'),
                $this->callback(function ($params) use ($orderId) {
                    return $params['orderId'] === $orderId
                        && $params['paid'] === '2025-12-09 14:30:00';
                })
            )
            ->willReturn(1);

        // Act
        $result = $this->service->updatePaidTimestamp($orderId, $paidAt);

        // Assert
        $this->assertTrue($result);
    }

    /**
     * @test
     * SRP: Updates OXPAID with current time when not provided
     */
    public function updatesPaidTimestampWithCurrentTimeWhenNotProvided(): void
    {
        // Arrange
        $orderId = 'order-123';

        $this->connection
            ->expects($this->once())
            ->method('executeStatement')
            ->with(
                $this->stringContains('UPDATE oxorder SET OXPAID'),
                $this->callback(function ($params) use ($orderId) {
                    return $params['orderId'] === $orderId
                        && preg_match('/\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', $params['paid']);
                })
            )
            ->willReturn(1);

        // Act
        $result = $this->service->updatePaidTimestamp($orderId);

        // Assert
        $this->assertTrue($result);
    }

    /**
     * @test
     * Returns false when order not found
     */
    public function returnsFalseWhenOrderNotFound(): void
    {
        // Arrange
        $orderId = 'non-existent';

        $this->connection
            ->expects($this->once())
            ->method('executeStatement')
            ->willReturn(0);

        // Act
        $result = $this->service->updatePaidTimestamp($orderId);

        // Assert
        $this->assertFalse($result);
    }

    /**
     * @test
     * SRP: Updates OXTRANSSTATUS to OK
     */
    public function updatesTransactionStatusToOk(): void
    {
        // Arrange
        $orderId = 'order-123';

        $this->connection
            ->expects($this->once())
            ->method('executeStatement')
            ->with(
                $this->stringContains('UPDATE oxorder SET OXTRANSSTATUS'),
                $this->callback(function ($params) {
                    return $params['status'] === 'OK';
                })
            )
            ->willReturn(1);

        // Act
        $result = $this->service->updateTransactionStatus($orderId, 'OK');

        // Assert
        $this->assertTrue($result);
    }

    /**
     * @test
     * SRP: Updates OXTRANSID
     */
    public function updatesTransactionId(): void
    {
        // Arrange
        $orderId = 'order-123';
        $transactionId = 'pi_abc123';

        $this->connection
            ->expects($this->once())
            ->method('executeStatement')
            ->with(
                $this->stringContains('UPDATE oxorder SET OXTRANSID'),
                $this->callback(function ($params) use ($transactionId) {
                    return $params['transId'] === $transactionId;
                })
            )
            ->willReturn(1);

        // Act
        $result = $this->service->updateTransactionId($orderId, $transactionId);

        // Assert
        $this->assertTrue($result);
    }

    /**
     * @test
     * Convenience method updates all payment fields
     */
    public function markOrderAsPaidUpdatesAllFields(): void
    {
        // Arrange
        $orderId = 'order-123';
        $transactionId = 'pi_abc123';
        $paidAt = new DateTimeImmutable('2025-12-09 14:30:00');

        $this->connection
            ->expects($this->once())
            ->method('executeStatement')
            ->with(
                $this->logicalAnd(
                    $this->stringContains('OXPAID'),
                    $this->stringContains('OXTRANSSTATUS'),
                    $this->stringContains('OXTRANSID')
                ),
                $this->anything()
            )
            ->willReturn(1);

        // Act
        $result = $this->service->markOrderAsPaid($orderId, $transactionId, $paidAt);

        // Assert
        $this->assertTrue($result);
    }
}
```

### Phase 2: Create Interface

**New File:** `src/Component/Service/OrderPaymentStateServiceInterface.php`

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Component\Service;

use DateTimeImmutable;

/**
 * Interface for order payment state operations
 *
 * SOLID Principles:
 * - SRP: Single responsibility - order payment state updates
 * - ISP: Focused interface with essential methods only
 * - DIP: Handlers depend on this abstraction
 */
interface OrderPaymentStateServiceInterface
{
    /**
     * Update OXPAID timestamp
     *
     * @param string $orderId Order OXID
     * @param DateTimeImmutable|null $paidAt Timestamp (current time if null)
     * @return bool True if order was updated
     */
    public function updatePaidTimestamp(
        string $orderId,
        ?DateTimeImmutable $paidAt = null
    ): bool;

    /**
     * Update OXTRANSSTATUS
     *
     * @param string $orderId Order OXID
     * @param string $status Status value ('OK', 'ERROR', etc.)
     * @return bool True if order was updated
     */
    public function updateTransactionStatus(string $orderId, string $status): bool;

    /**
     * Update OXTRANSID (transaction/payment intent ID)
     *
     * @param string $orderId Order OXID
     * @param string $transactionId Provider transaction ID
     * @return bool True if order was updated
     */
    public function updateTransactionId(string $orderId, string $transactionId): bool;

    /**
     * Mark order as paid with all relevant fields
     *
     * Convenience method that updates:
     * - OXPAID
     * - OXTRANSSTATUS = 'OK'
     * - OXTRANSID
     *
     * @param string $orderId Order OXID
     * @param string $transactionId Provider transaction ID
     * @param DateTimeImmutable|null $paidAt Timestamp (current time if null)
     * @return bool True if order was updated
     */
    public function markOrderAsPaid(
        string $orderId,
        string $transactionId,
        ?DateTimeImmutable $paidAt = null
    ): bool;
}
```

### Phase 3: Create Implementation

**New File:** `src/Component/Service/OrderPaymentStateService.php`

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Component\Service;

use Doctrine\DBAL\Connection;
use DateTimeImmutable;

/**
 * Service for order payment state operations
 *
 * SOLID Principles:
 * - SRP: Only handles order payment state updates
 * - OCP: Open for extension via interface
 * - DIP: Depends on Connection abstraction
 *
 * DRY: Single location for all OXPAID/OXTRANSSTATUS/OXTRANSID updates
 */
final class OrderPaymentStateService implements OrderPaymentStateServiceInterface
{
    public function __construct(
        private readonly Connection $connection
    ) {
    }

    public function updatePaidTimestamp(
        string $orderId,
        ?DateTimeImmutable $paidAt = null
    ): bool {
        $timestamp = $paidAt ?? new DateTimeImmutable();

        $sql = 'UPDATE oxorder SET OXPAID = :paid WHERE OXID = :orderId';
        $affected = $this->connection->executeStatement($sql, [
            'paid' => $timestamp->format('Y-m-d H:i:s'),
            'orderId' => $orderId,
        ]);

        return $affected > 0;
    }

    public function updateTransactionStatus(string $orderId, string $status): bool
    {
        $sql = 'UPDATE oxorder SET OXTRANSSTATUS = :status WHERE OXID = :orderId';
        $affected = $this->connection->executeStatement($sql, [
            'status' => $status,
            'orderId' => $orderId,
        ]);

        return $affected > 0;
    }

    public function updateTransactionId(string $orderId, string $transactionId): bool
    {
        $sql = 'UPDATE oxorder SET OXTRANSID = :transId WHERE OXID = :orderId';
        $affected = $this->connection->executeStatement($sql, [
            'transId' => $transactionId,
            'orderId' => $orderId,
        ]);

        return $affected > 0;
    }

    public function markOrderAsPaid(
        string $orderId,
        string $transactionId,
        ?DateTimeImmutable $paidAt = null
    ): bool {
        $timestamp = $paidAt ?? new DateTimeImmutable();

        $sql = 'UPDATE oxorder
                SET OXPAID = :paid,
                    OXTRANSSTATUS = :status,
                    OXTRANSID = :transId
                WHERE OXID = :orderId';

        $affected = $this->connection->executeStatement($sql, [
            'paid' => $timestamp->format('Y-m-d H:i:s'),
            'status' => 'OK',
            'transId' => $transactionId,
            'orderId' => $orderId,
        ]);

        return $affected > 0;
    }
}
```

### Phase 4: Register Service in DI Container

**File:** `services.yaml`

```yaml
services:
    OxidSolutionCatalysts\Payments\Component\Service\OrderPaymentStateServiceInterface:
        class: OxidSolutionCatalysts\Payments\Component\Service\OrderPaymentStateService
        arguments:
            - '@doctrine.dbal.default_connection'
```

### Phase 5: Update Handlers to Use Service

**Files to modify:**

1. `src/Stripe/EventSystem/Handler/StripeOrderCreationHandler.php`
2. `src/Stripe/EventSystem/Handler/OrderPaymentCompletedHandler.php`
3. `src/Stripe/Webhook/Handler/PaymentIntentSucceededHandler.php`
4. `src/Stripe/Service/OxpaidReconciliationService.php`

**Pattern for each file:**

```php
// BEFORE (duplicated in each file):
private function updateOrderPaidTimestamp(string $orderId): void
{
    $sql = "UPDATE oxorder SET OXPAID = :paid WHERE OXID = :orderId";
    $this->connection->executeStatement($sql, [
        'paid' => date('Y-m-d H:i:s'),
        'orderId' => $orderId,
    ]);
}

// AFTER (using service):
// Constructor:
public function __construct(
    // ... existing dependencies ...
    private readonly OrderPaymentStateServiceInterface $orderPaymentStateService
) {
}

// Usage:
$this->orderPaymentStateService->markOrderAsPaid(
    $orderId,
    $paymentIntentId,
    $paidAt
);
```

---

## Implementation Steps

### Step 1: Write Tests (TDD - RED)

```bash
# Create test file
mkdir -p tests/Unit/Component/Service
touch tests/Unit/Component/Service/OrderPaymentStateServiceTest.php

# Run tests - should fail
docker compose exec -T php bash -c "cd /var/www/test-module && vendor/bin/phpunit -c tests/phpunit.xml tests/Unit/Component/Service/OrderPaymentStateServiceTest.php"
```

### Step 2: Create Interface and Service (TDD - GREEN)

```bash
# Create files
touch src/Component/Service/OrderPaymentStateServiceInterface.php
touch src/Component/Service/OrderPaymentStateService.php

# Run tests - should pass
docker compose exec -T php bash -c "cd /var/www/test-module && vendor/bin/phpunit -c tests/phpunit.xml tests/Unit/Component/Service/OrderPaymentStateServiceTest.php"
```

### Step 3: Register Service

```bash
# Update services.yaml
# Run all tests to verify DI
docker compose exec -T php bash -c "cd /var/www/test-module && vendor/bin/phpunit -c tests/phpunit.xml --testsuite Unit"
```

### Step 4: Update Handlers One by One

```bash
# For each handler:
# 1. Add service dependency
# 2. Replace direct SQL with service call
# 3. Remove private updateOrderPaidTimestamp method
# 4. Run tests

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

# E2E test
cd tests/e2e/playwright && npx playwright test tests/checkout/
```

---

## Files to Create/Modify

### New Files

| File | Purpose |
|------|---------|
| `src/Component/Service/OrderPaymentStateServiceInterface.php` | Service interface |
| `src/Component/Service/OrderPaymentStateService.php` | Service implementation |
| `tests/Unit/Component/Service/OrderPaymentStateServiceTest.php` | Service tests |

### Modified Files

| File | Change |
|------|--------|
| `services.yaml` | Register service |
| `StripeOrderCreationHandler.php` | Use service, remove duplicate method |
| `OrderPaymentCompletedHandler.php` | Use service, remove duplicate method |
| `PaymentIntentSucceededHandler.php` | Use service, remove duplicate method |
| `OxpaidReconciliationService.php` | Use service, remove duplicate method |
| `WebhookProcessingService.php` | Use service, remove 3 duplicate methods |

---

## Verification Checklist

- [ ] OrderPaymentStateServiceInterface created
- [ ] OrderPaymentStateService implements interface
- [ ] Service registered in services.yaml
- [ ] All handlers use service instead of direct SQL
- [ ] No duplicate OXPAID update methods remain
- [ ] All unit tests pass
- [ ] E2E checkout flow works
- [ ] OXPAID correctly populated

### Verification Commands

```bash
# Verify no duplicate OXPAID updates
grep -rn "UPDATE oxorder SET OXPAID" src/
# Should return only: OrderPaymentStateService.php

# Verify service usage
grep -rn "OrderPaymentStateService" src/
# Should show injection in all handlers
```

---

## Risk Assessment

| Risk | Impact | Mitigation |
|------|--------|------------|
| Breaking payment flow | High | E2E tests before/after |
| Timezone changes | Medium | Use DateTimeImmutable consistently |
| Missing edge cases | Medium | Test all 4 original paths |

---

## Success Criteria

1. ✅ Single `OrderPaymentStateService` handles all OXPAID updates
2. ✅ No duplicate `updateOrderPaidTimestamp` methods
3. ✅ Consistent date formatting (PHP DateTimeImmutable)
4. ✅ All existing tests pass
5. ✅ E2E checkout flow works
6. ✅ OXPAID correctly shows payment timestamp

---

## Related Issues

- CODE_REVIEW.md Section 1.4 (OXPAID Update Strategy)
- CODE_REVIEW.md Section 2.1 (CRITICAL: OXPAID Update Logic)
- CODE_REVIEW.md Section 2.5 (Order Field Update Sequences)

---

**Last Updated:** 2025-12-09
