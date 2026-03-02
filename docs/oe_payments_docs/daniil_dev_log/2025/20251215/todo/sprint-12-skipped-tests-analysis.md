# Sprint 12: Skipped Tests Analysis & Resolution

**Date:** 2025-12-15
**Priority:** MEDIUM
**Status:** TODO
**Branch:** b-7.4.x-code-review-STRP-75
**Est. Effort:** 3 hours
**Original Sprint:** 2025-12-05

---

## Development Principles Checklist

| Principle | How Applied |
|-----------|-------------|
| **Test Coverage** | All tests should either pass or be properly documented |
| **CI/CD** | Tests should be categorized for appropriate CI environments |
| **Clean Code** | Clear skip reasons with `@group` annotations |
| **Documentation** | Test requirements documented |

---

## Problem Statement

Integration test suite shows **67 skipped** and **1 incomplete** tests:

```
Tests: 306, Assertions: 1098, Skipped: 67, Incomplete: 1
```

These tests need to be analyzed to determine:
1. Which tests should run in CI
2. Which tests require special configuration
3. Which tests should be removed or fixed

---

## Test Categories

### Category 1: Incomplete Test (1)

| Test | File | Action |
|------|------|--------|
| `testRefundsPaymentPartialAmount` | `StripeAdapterIntegrationTest.php` | INVESTIGATE |

**Analysis needed:** Why is partial refund test incomplete?

---

### Category 2: Contract Repository (1 skipped)

| Test | File | Reason |
|------|------|--------|
| `testTransactionRollback` | `DoctrineContractRepositoryTest.php` | DB transaction support |

**Proposed Action:** Add `@group requires-transaction-support` and document requirements.

---

### Category 3: Migration Structure (11 skipped)

All tests verify PaymentWatch indexes:

| Test | Purpose |
|------|---------|
| `testWebhookLogsTableExists` | Table existence |
| `testContractTableHasPaymentWatchStateIndex` | OXSTATE index |
| `testContractTableHasPaymentWatchProviderOrderIndex` | OXPROVIDERORDERID index |
| `testContractTableHasPaymentWatchOrderIndex` | OXORDERID index |
| `testContractTableHasPaymentWatchUserIndex` | OXUSERID index |
| `testContractTableHasPaymentWatchCompositeIndex` | Composite index |
| `testTransactionTableHasPaymentWatchStatusIndex` | Status index |
| `testTransactionTableHasPaymentWatchContractIndex` | Contract FK index |
| `testTransactionTableHasPaymentWatchProviderOrderIndex` | Provider order index |
| `testTransactionTableHasPaymentWatchTypeIndex` | Type index |
| `testTransactionTableHasPaymentWatchCompositeIndex` | Composite index |

**File:** `tests/Integration/Database/MigrationStructureTest.php`

**Proposed Action:**
1. Add `@group paymentwatch` annotation
2. Skip when PaymentWatch migrations not run
3. Document: "Run PaymentWatch migrations before these tests"

---

### Category 4: Module Lifecycle (6 skipped)

| Test | Dependency |
|------|------------|
| `testModuleCanBeActivated` | Base test |
| `testModuleCanBeDeactivated` | Activation |
| `testModuleCanBeReactivatedAfterDeactivation` | Deactivation |
| `testModuleIdIsCorrect` | Module metadata |
| `testServicesAvailableAfterActivation` | Activation |
| `testMultipleActivationDeactivationCycles` | Full cycle |

**File:** `tests/Integration/Module/ModuleLifecycleTest.php`

**Proposed Action:**
1. Add `@group module-lifecycle` annotation
2. Create separate CI job for module lifecycle tests
3. Document: "These tests require module to be installed but not activated"

---

### Category 5: PaymentWatch Feature (49 skipped)

#### 5.1 AssumptionControllerIntegrationTest (13 tests)

**File:** `tests/Integration/Watch/Controller/AssumptionControllerIntegrationTest.php`

Tests API functionality:
- Valid request handling
- Value matching
- Comparison operators
- Authentication
- Input validation
- SQL injection protection

#### 5.2 CompletePaymentFlowTest (6 tests)

**File:** `tests/Integration/Watch/EndToEnd/CompletePaymentFlowTest.php`

Tests E2E flows:
- Payment flow tracking
- Failed payment handling
- Contract timeout
- Refund flow
- Concurrent payments
- State transitions

#### 5.3 PerformanceBenchmarkTest (7 tests)

**File:** `tests/Integration/Watch/Performance/PerformanceBenchmarkTest.php`

Tests performance:
- Response time
- Concurrent requests
- Complex queries
- Memory footprint
- Scalability

#### 5.4 SecurityValidationTest (23 tests)

**File:** `tests/Integration/Watch/Security/SecurityValidationTest.php`

Tests security:
- SQL injection (14 data sets)
- Timing attacks
- Parameter pollution
- Request size limits
- Security headers

**Proposed Action for all PaymentWatch tests:**
1. Add `@group paymentwatch` annotation to all
2. Add condition to skip when PaymentWatch not configured
3. Create separate CI job: "PaymentWatch Integration Tests"
4. Document requirements: API key, migrations, feature enabled

---

## Implementation Plan

### Step 1: Add Test Group Annotations

```php
/**
 * @group paymentwatch
 * @group requires-api-key
 */
class AssumptionControllerIntegrationTest extends TestCase
```

### Step 2: Add Conditional Skip Logic

```php
protected function setUp(): void
{
    parent::setUp();

    if (!$this->isPaymentWatchEnabled()) {
        $this->markTestSkipped('PaymentWatch feature not enabled');
    }
}

private function isPaymentWatchEnabled(): bool
{
    return (bool) getenv('PAYMENTWATCH_ENABLED');
}
```

### Step 3: Create PHPUnit Configuration for Groups

**File:** `tests/phpunit.xml`

```xml
<testsuites>
    <testsuite name="Unit">
        <directory>Unit/</directory>
    </testsuite>
    <testsuite name="Integration">
        <directory>Integration/</directory>
        <exclude>Integration/Watch/</exclude>
        <exclude>Integration/Module/ModuleLifecycleTest.php</exclude>
    </testsuite>
    <testsuite name="Integration-Full">
        <directory>Integration/</directory>
    </testsuite>
    <testsuite name="PaymentWatch">
        <directory>Integration/Watch/</directory>
    </testsuite>
    <testsuite name="ModuleLifecycle">
        <file>Integration/Module/ModuleLifecycleTest.php</file>
    </testsuite>
</testsuites>
```

### Step 4: Update CI Configuration

```yaml
# .github/workflows/tests.yml
jobs:
  unit-tests:
    runs-on: ubuntu-latest
    steps:
      - run: vendor/bin/phpunit --testsuite Unit

  integration-tests:
    runs-on: ubuntu-latest
    steps:
      - run: vendor/bin/phpunit --testsuite Integration

  paymentwatch-tests:
    runs-on: ubuntu-latest
    if: github.event_name == 'schedule' || contains(github.event.head_commit.message, '[paymentwatch]')
    env:
      PAYMENTWATCH_ENABLED: true
      PAYMENTWATCH_API_KEY: ${{ secrets.PAYMENTWATCH_API_KEY }}
    steps:
      - run: vendor/bin/phpunit --testsuite PaymentWatch
```

### Step 5: Fix Incomplete Test

Investigate `testRefundsPaymentPartialAmount`:

```bash
# Run with verbose output
docker compose exec php php vendor/bin/phpunit -c extensions/stripe/tests/phpunit.xml \
  --filter "testRefundsPaymentPartialAmount" -v
```

---

## Files to Modify

| File | Change |
|------|--------|
| `tests/phpunit.xml` | Add test suites for groups |
| `tests/Integration/Watch/**/*.php` | Add `@group paymentwatch` |
| `tests/Integration/Module/ModuleLifecycleTest.php` | Add `@group module-lifecycle` |
| `tests/Integration/Database/MigrationStructureTest.php` | Add skip conditions |
| `tests/Integration/Component/Repository/DoctrineContractRepositoryTest.php` | Add `@group` |
| `tests/Integration/Stripe/Adapter/StripeAdapterIntegrationTest.php` | Fix incomplete test |

---

## Verification Commands

```bash
# Run only unit tests (fast)
docker compose exec php php vendor/bin/phpunit -c extensions/stripe/tests/phpunit.xml --testsuite Unit

# Run core integration tests (excludes PaymentWatch, ModuleLifecycle)
docker compose exec php php vendor/bin/phpunit -c extensions/stripe/tests/phpunit.xml --testsuite Integration

# Run PaymentWatch tests (when configured)
PAYMENTWATCH_ENABLED=true docker compose exec php php vendor/bin/phpunit \
  -c extensions/stripe/tests/phpunit.xml --testsuite PaymentWatch

# List all groups
docker compose exec php php vendor/bin/phpunit -c extensions/stripe/tests/phpunit.xml --list-groups
```

---

## Success Criteria

- [ ] All tests have appropriate `@group` annotations
- [ ] PHPUnit config has separate test suites
- [ ] Skipped tests have clear skip messages
- [ ] CI runs appropriate test suites
- [ ] `testRefundsPaymentPartialAmount` is either fixed or documented
- [ ] Documentation updated with test requirements
- [ ] No unexpected skipped tests in default run

---

## Expected Outcome

After this sprint:

| Test Suite | Count | Expected Skipped |
|------------|-------|------------------|
| Unit | 1348 | 0 |
| Integration (core) | ~240 | 0 |
| PaymentWatch | 49 | 0 (when configured) |
| ModuleLifecycle | 6 | 0 (when module installed) |
| MigrationStructure | 11 | 0 (when migrations run) |

---

## Related Issues

- 2025-12-05 status.md: Sprint 12 TODO
- CODE_REVIEW.md Section 3.4 (HIGH: Skipped Tests Indicating Feature Gaps)

---

**Last Updated:** 2025-12-15
