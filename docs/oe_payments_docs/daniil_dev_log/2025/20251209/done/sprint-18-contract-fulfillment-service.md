# Sprint 18: Extract ContractFulfillmentService

**Date:** 2025-12-09
**Priority:** HIGH
**Status:** PENDING
**Branch:** TBD (b-7.4.x-STRP-XX)
**Est. Effort:** 3 hours
**Depends On:** Sprint 16 (shared service pattern)

---

## Development Principles Checklist

| Principle | How Applied |
|-----------|-------------|
| **TDD-FIRST** | Write service tests first, then implementation |
| **SOLID-SRP** | Service has single responsibility: contract fulfillment |
| **SOLID-OCP** | Service can be extended for new fulfillment strategies |
| **SOLID-DIP** | Service depends on repository interface |
| **DI** | All dependencies injected via constructor |
| **DRY** | Single location for fulfillment logic |
| **Clean Code** | One method ≤ 25 lines |
| **Containerization** | All tests via `docker compose exec` |

---

## Problem Statement

**Contract fulfillment logic duplicated in 3 locations:**

| File | Lines | Location |
|------|-------|----------|
| `ContractFulfillmentHandler.php` | 78-82 | Component layer |
| `WebhookContractFulfillmentHandler.php` | 38-82, 142-168 | Stripe layer |
| `WebhookProcessingService.php` | 295-366, 485-546 | Stripe layer |

**Repeated Pattern:**
```php
if ($contract->getState()->isFulfilled()) {
    return false;
}
if (!$contract->getState()->isCommitted()) {
    return false;
}
$contract->fulfill();
$this->contractRepository->save($contract);
$this->dispatchContractFulfilledEvent($contract);
```

**DRY Violation Score:** 8/10

---

## Root Cause Analysis

1. **No dedicated service** for fulfillment orchestration
2. **Logic scattered** across handlers and services
3. **Event dispatch duplicated** in multiple files
4. **No single point** for fulfillment validation

---

## Solution Design

### Phase 1: TDD - Write Failing Tests First

**New Test File:** `tests/Unit/Component/Service/ContractFulfillmentServiceTest.php`

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Unit\Component\Service;

use OxidSolutionCatalysts\Payments\Component\Contract\PaymentContract;
use OxidSolutionCatalysts\Payments\Component\Contract\ContractState;
use OxidSolutionCatalysts\Payments\Component\Repository\ContractRepositoryInterface;
use OxidSolutionCatalysts\Payments\Component\Service\ContractFulfillmentService;
use OxidSolutionCatalysts\Payments\Component\Service\ContractFulfillmentServiceInterface;
use OxidSolutionCatalysts\Payments\Component\EventSystem\EventDispatcherInterface;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\ContractFulfilledEvent;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

class ContractFulfillmentServiceTest extends TestCase
{
    private ContractRepositoryInterface&MockObject $contractRepository;
    private EventDispatcherInterface&MockObject $eventDispatcher;
    private ContractFulfillmentService $service;

    protected function setUp(): void
    {
        $this->contractRepository = $this->createMock(ContractRepositoryInterface::class);
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->service = new ContractFulfillmentService(
            $this->contractRepository,
            $this->eventDispatcher
        );
    }

    /**
     * @test
     * LSP: Service implements interface
     */
    public function implementsInterface(): void
    {
        $this->assertInstanceOf(
            ContractFulfillmentServiceInterface::class,
            $this->service
        );
    }

    /**
     * @test
     * SRP: Fulfills committed contract
     */
    public function fulfillsCommittedContract(): void
    {
        // Arrange
        $contract = $this->createContractInState(ContractState::COMMITTED);

        $this->contractRepository
            ->expects($this->once())
            ->method('save')
            ->with($contract);

        $this->eventDispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(ContractFulfilledEvent::class));

        // Act
        $result = $this->service->fulfill($contract);

        // Assert
        $this->assertTrue($result);
        $this->assertTrue($contract->getState()->isFulfilled());
    }

    /**
     * @test
     * Guards: Already fulfilled contract returns false
     */
    public function returnsFalseForAlreadyFulfilledContract(): void
    {
        // Arrange
        $contract = $this->createContractInState(ContractState::FULFILLED);

        $this->contractRepository
            ->expects($this->never())
            ->method('save');

        $this->eventDispatcher
            ->expects($this->never())
            ->method('dispatch');

        // Act
        $result = $this->service->fulfill($contract);

        // Assert
        $this->assertFalse($result);
    }

    /**
     * @test
     * Guards: Non-committed contract returns false
     */
    public function returnsFalseForNonCommittedContract(): void
    {
        // Arrange
        $contract = $this->createContractInState(ContractState::PENDING);

        $this->contractRepository
            ->expects($this->never())
            ->method('save');

        // Act
        $result = $this->service->fulfill($contract);

        // Assert
        $this->assertFalse($result);
    }

    /**
     * @test
     * SRP: Fulfills by provider order ID
     */
    public function fulfillsByProviderOrderId(): void
    {
        // Arrange
        $providerOrderId = 'cs_test_123';
        $contract = $this->createContractInState(ContractState::COMMITTED);

        $this->contractRepository
            ->expects($this->once())
            ->method('findByProviderOrderId')
            ->with($providerOrderId)
            ->willReturn($contract);

        $this->contractRepository
            ->expects($this->once())
            ->method('save');

        // Act
        $result = $this->service->fulfillByProviderOrderId($providerOrderId);

        // Assert
        $this->assertTrue($result);
    }

    /**
     * @test
     * Returns null when contract not found
     */
    public function returnsNullWhenContractNotFound(): void
    {
        // Arrange
        $providerOrderId = 'non_existent';

        $this->contractRepository
            ->expects($this->once())
            ->method('findByProviderOrderId')
            ->willReturn(null);

        // Act
        $result = $this->service->fulfillByProviderOrderId($providerOrderId);

        // Assert
        $this->assertNull($result);
    }

    private function createContractInState(ContractState $state): PaymentContract
    {
        $contract = $this->createMock(PaymentContract::class);
        $contract->method('getState')->willReturn($state);
        $contract->method('getId')->willReturn('contract-123');

        return $contract;
    }
}
```

### Phase 2: Create Interface

**New File:** `src/Component/Service/ContractFulfillmentServiceInterface.php`

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Component\Service;

use OxidSolutionCatalysts\Payments\Component\Contract\PaymentContract;

/**
 * Interface for contract fulfillment operations
 *
 * SOLID Principles:
 * - SRP: Single responsibility - contract fulfillment
 * - ISP: Focused interface with fulfillment methods only
 * - DIP: Handlers depend on this abstraction
 */
interface ContractFulfillmentServiceInterface
{
    /**
     * Fulfill a contract
     *
     * Guards:
     * - Contract must be in COMMITTED state
     * - Contract must not already be fulfilled
     *
     * @param PaymentContract $contract Contract to fulfill
     * @return bool True if fulfilled, false if guards failed
     */
    public function fulfill(PaymentContract $contract): bool;

    /**
     * Fulfill contract by provider order ID
     *
     * @param string $providerOrderId Provider's order/session ID
     * @return bool|null True if fulfilled, false if guards failed, null if not found
     */
    public function fulfillByProviderOrderId(string $providerOrderId): ?bool;

    /**
     * Fulfill contract by contract ID
     *
     * @param string $contractId Contract OXID
     * @return bool|null True if fulfilled, false if guards failed, null if not found
     */
    public function fulfillByContractId(string $contractId): ?bool;
}
```

### Phase 3: Create Implementation

**New File:** `src/Component/Service/ContractFulfillmentService.php`

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Component\Service;

use OxidSolutionCatalysts\Payments\Component\Contract\PaymentContract;
use OxidSolutionCatalysts\Payments\Component\Repository\ContractRepositoryInterface;
use OxidSolutionCatalysts\Payments\Component\EventSystem\EventDispatcherInterface;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\ContractFulfilledEvent;

/**
 * Service for contract fulfillment operations
 *
 * SOLID Principles:
 * - SRP: Only handles contract fulfillment
 * - OCP: Open for extension via interface
 * - DIP: Depends on repository and dispatcher abstractions
 *
 * DRY: Single location for all fulfillment logic
 */
final class ContractFulfillmentService implements ContractFulfillmentServiceInterface
{
    public function __construct(
        private readonly ContractRepositoryInterface $contractRepository,
        private readonly EventDispatcherInterface $eventDispatcher
    ) {
    }

    public function fulfill(PaymentContract $contract): bool
    {
        if (!$this->canFulfill($contract)) {
            return false;
        }

        $contract->fulfill();
        $this->contractRepository->save($contract);
        $this->dispatchFulfilledEvent($contract);

        return true;
    }

    public function fulfillByProviderOrderId(string $providerOrderId): ?bool
    {
        $contract = $this->contractRepository->findByProviderOrderId($providerOrderId);

        if ($contract === null) {
            return null;
        }

        return $this->fulfill($contract);
    }

    public function fulfillByContractId(string $contractId): ?bool
    {
        $contract = $this->contractRepository->findById($contractId);

        if ($contract === null) {
            return null;
        }

        return $this->fulfill($contract);
    }

    private function canFulfill(PaymentContract $contract): bool
    {
        if ($contract->getState()->isFulfilled()) {
            return false;
        }

        if (!$contract->getState()->isCommitted()) {
            return false;
        }

        return true;
    }

    private function dispatchFulfilledEvent(PaymentContract $contract): void
    {
        $event = new ContractFulfilledEvent($contract);
        $this->eventDispatcher->dispatch($event);
    }
}
```

### Phase 4: Register Service in DI Container

**File:** `services.yaml`

```yaml
services:
    OxidSolutionCatalysts\Payments\Component\Service\ContractFulfillmentServiceInterface:
        class: OxidSolutionCatalysts\Payments\Component\Service\ContractFulfillmentService
        arguments:
            - '@OxidSolutionCatalysts\Payments\Component\Repository\ContractRepositoryInterface'
            - '@OxidSolutionCatalysts\Payments\Component\EventSystem\EventDispatcherInterface'
```

### Phase 5: Update Handlers to Use Service

**Files to modify:**

1. `src/Component/EventSystem/Handler/ContractFulfillmentHandler.php`
2. `src/Stripe/Handler/WebhookContractFulfillmentHandler.php`
3. `src/Stripe/Service/WebhookProcessingService.php`

**Pattern for each file:**

```php
// BEFORE (duplicated logic):
if ($contract->getState()->isFulfilled()) {
    return false;
}
if (!$contract->getState()->isCommitted()) {
    return false;
}
$contract->fulfill();
$this->contractRepository->save($contract);
$this->dispatchContractFulfilledEvent($contract);

// AFTER (using service):
// Constructor:
public function __construct(
    // ... existing dependencies ...
    private readonly ContractFulfillmentServiceInterface $fulfillmentService
) {
}

// Usage:
$result = $this->fulfillmentService->fulfill($contract);
// OR
$result = $this->fulfillmentService->fulfillByProviderOrderId($providerOrderId);
```

---

## Implementation Steps

### Step 1: Write Tests (TDD - RED)

```bash
# Create test file
touch tests/Unit/Component/Service/ContractFulfillmentServiceTest.php

# Run tests - should fail
docker compose exec -T php bash -c "cd /var/www/test-module && vendor/bin/phpunit -c tests/phpunit.xml tests/Unit/Component/Service/ContractFulfillmentServiceTest.php"
```

### Step 2: Create Interface and Service (TDD - GREEN)

```bash
# Create files
touch src/Component/Service/ContractFulfillmentServiceInterface.php
touch src/Component/Service/ContractFulfillmentService.php

# Run tests - should pass
docker compose exec -T php bash -c "cd /var/www/test-module && vendor/bin/phpunit -c tests/phpunit.xml tests/Unit/Component/Service/ContractFulfillmentServiceTest.php"
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
# 2. Replace fulfillment logic with service call
# 3. Remove duplicate fulfillment methods
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
```

---

## Files to Create/Modify

### New Files

| File | Purpose |
|------|---------|
| `src/Component/Service/ContractFulfillmentServiceInterface.php` | Service interface |
| `src/Component/Service/ContractFulfillmentService.php` | Service implementation |
| `tests/Unit/Component/Service/ContractFulfillmentServiceTest.php` | Service tests |

### Modified Files

| File | Change |
|------|--------|
| `services.yaml` | Register service |
| `ContractFulfillmentHandler.php` | Use service |
| `WebhookContractFulfillmentHandler.php` | Use service, remove duplicate |
| `WebhookProcessingService.php` | Use service, remove 2 duplicate locations |

---

## Verification Checklist

- [ ] ContractFulfillmentServiceInterface created
- [ ] ContractFulfillmentService implements interface
- [ ] Service registered in services.yaml
- [ ] All handlers use service instead of inline logic
- [ ] No duplicate fulfillment logic remains
- [ ] All unit tests pass
- [ ] E2E checkout flow works

### Verification Commands

```bash
# Verify no duplicate fulfillment logic
grep -rn "contract->fulfill()" src/
# Should return only: ContractFulfillmentService.php

# Verify service usage
grep -rn "ContractFulfillmentService" src/
# Should show injection in all handlers
```

---

## Risk Assessment

| Risk | Impact | Mitigation |
|------|--------|------------|
| Breaking fulfillment flow | High | E2E tests before/after |
| Event dispatch changes | Medium | Verify event listeners still work |
| Race condition handling | Medium | Test concurrent fulfillment attempts |

---

## Success Criteria

1. ✅ Single `ContractFulfillmentService` handles all fulfillment
2. ✅ No duplicate fulfillment logic in handlers
3. ✅ Event dispatch happens in one place
4. ✅ All existing tests pass
5. ✅ E2E checkout flow works

---

## Related Issues

- CODE_REVIEW.md Section 2.2 (HIGH: Contract Fulfillment Logic)
- CODE_REVIEW.md Section 2.3 (HIGH: ContractFulfilledEvent Dispatch)
- CODE_REVIEW.md Section 2.6 (MEDIUM: Contract Lookup Strategies)

---

**Last Updated:** 2025-12-09
