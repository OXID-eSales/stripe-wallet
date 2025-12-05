# Sprint 9: CI Integration Test Fixes

**Date:** 2025-12-05
**Status:** Planning
**Branch:** b-7.4.x-auth-STRP-70
**Approach:** TDD-First, LSP, Clean Code, DI

---

## Executive Summary

Fix 16 CI integration test failures caused by Sprint 8's `osc_payment_order_state` table removal and service discovery issues in CI environment.

---

## Error Categories

### Category 1: Table Not Found (3 errors)

```
SQLSTATE[42S02]: Table 'example.osc_payment_order_state' doesn't exist
```

**Root Cause:** `FullDataPersistenceFlowTest.php` still references the dropped table.

**Affected Tests:**
| Test Method | Line | Issue |
|-------------|------|-------|
| `testOrderState_PersistsOrderContractLink` | 380 | INSERT into dropped table |
| `testOrderState_TracksPaymentStateChanges` | 418 | INSERT/UPDATE dropped table |
| `testCompleteFlow_PopulatesAllTables` | 669 | INSERT into dropped table |

### Category 2: Service Not Found (13 errors)

```
ServiceNotFoundException: Service "ContractRepositoryInterface" not found
```

**Root Cause:** CI caches shop installation but doesn't re-run migrations between jobs.

**Affected Tests:**
| File | Test Count |
|------|------------|
| `ContractCaptureRefundTest.php` | 5 tests |
| `ContractAwareOxpaidWebhookTest.php` | 2 tests |
| `OxpaidWebhookUpdateTest.php` | 6 tests |

---

## Solution Strategy

### Phase 1: Remove Dropped Table Tests

**Action:** Delete or refactor tests that explicitly test `osc_payment_order_state`.

**Files to Modify:**
- `tests/Integration/Component/Checkout/FullDataPersistenceFlowTest.php`

**Changes:**
1. Remove `testOrderState_PersistsOrderContractLink()` - table dropped
2. Remove `testOrderState_TracksPaymentStateChanges()` - table dropped
3. Update `testCompleteFlow_PopulatesAllTables()`:
   - Remove INSERT into `osc_payment_order_state`
   - Remove assertions on `osc_payment_order_state`
   - Add assertions for contract capture/refund fields instead

### Phase 2: Fix Service Discovery

**Root Cause Analysis:**

CI workflow structure:
```
Job 1: install_shop_with_module
  ├── Clone shop
  ├── Install module via composer
  ├── Run database migrations
  └── Cache installation

Job 2: integration_tests (runs after Job 1)
  ├── Restore from cache
  ├── composer update (dependencies only)
  └── Run tests WITHOUT re-activating module
```

**Problem:** Module services not available because module not activated after cache restore.

**Solution Options:**

| Option | Pros | Cons |
|--------|------|------|
| A: Re-activate module in CI | Simple fix | CI workflow change |
| B: Tests skip if service unavailable | Robust | Tests become incomplete |
| C: Tests instantiate repos directly | Works anywhere | Bypasses DI |

**Recommended:** Option C - Tests should instantiate `DoctrineContractRepository` directly (as `FullDataPersistenceFlowTest` already does).

### Phase 3: Update Affected Tests

**Pattern to Follow:**

```php
// BEFORE (fails in CI):
$repo = $container->get(ContractRepositoryInterface::class);

// AFTER (works everywhere):
$container = ContainerFactory::getInstance()->getContainer();
$connectionProvider = $container->get(ConnectionProviderInterface::class);
$connection = $connectionProvider->get();
$repo = new DoctrineContractRepository($connection);
```

---

## Implementation Plan

### Step 1: Write Failing Test (TDD)

Create a test that verifies the fix works:

```php
// tests/Integration/Component/Contract/ContractRepositoryDirectInstantiationTest.php
public function testContractRepositoryCanBeInstantiatedDirectly(): void
{
    $container = ContainerFactory::getInstance()->getContainer();
    $connectionProvider = $container->get(ConnectionProviderInterface::class);
    $connection = $connectionProvider->get();

    $repo = new DoctrineContractRepository($connection);

    $this->assertInstanceOf(DoctrineContractRepository::class, $repo);
}
```

### Step 2: Fix FullDataPersistenceFlowTest

```php
// Remove these test methods:
// - testOrderState_PersistsOrderContractLink()
// - testOrderState_TracksPaymentStateChanges()

// Update testCompleteFlow_PopulatesAllTables():
// - Remove osc_payment_order_state INSERT
// - Add contract capture/refund field verification
```

### Step 3: Fix ContractCaptureRefundTest

Update `setUp()` to instantiate repo directly:

```php
protected function setUp(): void
{
    parent::setUp();

    $container = ContainerFactory::getInstance()->getContainer();
    $connectionProvider = $container->get(ConnectionProviderInterface::class);
    $this->connection = $connectionProvider->get();

    // Direct instantiation (works in CI without module activation)
    $this->contractRepository = new DoctrineContractRepository($this->connection);
}
```

### Step 4: Fix Webhook Tests

Apply same pattern to:
- `ContractAwareOxpaidWebhookTest.php`
- `OxpaidWebhookUpdateTest.php`

---

## Files to Modify

| File | Changes |
|------|---------|
| `tests/Integration/Component/Checkout/FullDataPersistenceFlowTest.php` | Remove order_state tests, update complete flow test |
| `tests/Integration/Component/Contract/ContractCaptureRefundTest.php` | Direct repo instantiation |
| `tests/Integration/Stripe/Webhook/ContractAwareOxpaidWebhookTest.php` | Direct repo instantiation |
| `tests/Integration/Stripe/Webhook/OxpaidWebhookUpdateTest.php` | Direct repo instantiation |

---

## Verification

### Local Tests
```bash
# Unit tests (should pass)
docker compose exec -T php bash -c "cd /var/www/test-module && vendor/bin/phpunit -c tests/phpunit.xml --testsuite Unit"

# Integration tests (should pass after fix)
docker compose exec -T php vendor/bin/phpunit \
  -c /var/www/test-module/tests/phpunit.xml \
  --testsuite Integration \
  --bootstrap=/var/www/source/bootstrap.php
```

### CI Verification
Push changes and verify GitHub Actions passes.

---

## Success Criteria

1. [ ] `FullDataPersistenceFlowTest` no longer references `osc_payment_order_state`
2. [ ] All tests use direct repo instantiation (no DI for repos)
3. [ ] Local integration tests pass
4. [ ] CI integration tests pass (0 errors)
5. [ ] All existing unit tests still pass

---

## Rollback Plan

If tests still fail after these changes, add `@group requires-module-activation` annotation to skip affected tests in CI:

```php
/**
 * @group requires-module-activation
 */
public function testContractWithPaymentIntentIdUpdatesOxpaid(): void
{
    // ...
}
```

And configure CI to exclude this group.
