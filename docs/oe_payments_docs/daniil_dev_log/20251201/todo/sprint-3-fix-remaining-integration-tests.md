# Sprint 3: Fix Remaining Integration Test Failures

**Sprint Goal:** Fix 4 non-DNS integration test failures
**Estimated Time:** 1.5 hours
**Priority:** LOW (non-blocking)

---

## Problem Description

There are 4 integration test failures unrelated to network connectivity:

| # | Test | Error | Root Cause |
|---|------|-------|------------|
| 1 | `ControllerEventSystemIntegrationTest::testEventContext_CarriesDataThroughHandlerChain` | `paymentIntentId` is null | Test uses wrong context key (should be `providerTransactionId`) |
| 2 | `DoctrineContractRepositoryTest::testFindExpired` | ID mismatch | Test cleanup doesn't clear all contracts |
| 3 | `ModuleStructureTest::stripe_directories_exist` | Missing directory | `src/Stripe/Handler` doesn't exist (should be `src/Stripe/EventSystem/Handler`) |
| 4 | `MetadataTest::testControllersRegistered` | Class not found | `Component\Controller\Core\PaymentController` doesn't exist |

---

## Issue 1: EventContext Wrong Key Name

### Error
```
Failed asserting that null matches expected 'pi_context_test_093428_bcc0'.
```

### Location
`tests/Integration/Component/Controller/ControllerEventSystemIntegrationTest.php:664`

### Analysis

The test passes `providerTransactionId` to `processCheckout()`:
```php
$this->orchestrator->processCheckout(
    $basket,
    $user,
    'stripe_card',
    'pi_context_test_' . $this->testRunId  // 4th param = providerTransactionId
);
```

But then asserts using the wrong key:
```php
$this->assertEquals('pi_context_test_' . $this->testRunId, $context->get('paymentIntentId'));
//                                                                        ^^^^^^^^^^^^^^^ WRONG KEY!
```

The `CheckoutOrchestrator` sets it as `'providerTransactionId'` (line 61), not `'paymentIntentId'`:
```php
$context = new EventContext([
    'providerTransactionId' => $providerTransactionId,  // This is the correct key
]);
```

### TDD Solution

#### Step 1: RED - Verify Failure
```bash
docker compose exec -T php vendor/bin/phpunit \
    /var/www/extensions/stripe/tests/Integration/Component/Controller/ControllerEventSystemIntegrationTest.php \
    --filter testEventContext_CarriesDataThroughHandlerChain
```

#### Step 2: GREEN - Fix Test Key Name

**File:** `tests/Integration/Component/Controller/ControllerEventSystemIntegrationTest.php`

**Change line 664:**
```php
// Before:
$this->assertEquals('pi_context_test_' . $this->testRunId, $context->get('paymentIntentId'));

// After:
$this->assertEquals('pi_context_test_' . $this->testRunId, $context->get('providerTransactionId'));
```

#### Step 3: REFACTOR - Verify

```bash
docker compose exec -T php vendor/bin/phpunit \
    /var/www/extensions/stripe/tests/Integration/Component/Controller/ControllerEventSystemIntegrationTest.php
```

---

## Issue 2: Contract Repository ID Mismatch

### Error
```
Failed asserting that two strings are equal.
Expected: 'test_contract_expired'
Actual:   'contract_692722b91338a4.27144636'
```

### Location
`tests/Integration/Component/Repository/DoctrineContractRepositoryTest.php:269`

### Analysis

The `findExpired()` method returns a contract with auto-generated ID (`contract_*`) instead of the expected test ID (`test_contract_expired`).

**Root Cause:** The test cleanup only deletes contracts with IDs starting with `test_`:
```php
// Line 50 in test file:
$this->connection->executeStatement('DELETE FROM oe_payments_contract WHERE OXID LIKE "test_%"');
```

But other tests or previous runs may have created contracts with auto-generated IDs like `contract_692722b91338a4.27144636` that are also expired. These are not cleaned up and interfere with the test.

### TDD Solution

#### Step 1: RED - Verify Failure
```bash
docker compose exec -T php vendor/bin/phpunit \
    /var/www/extensions/stripe/tests/Integration/Component/Repository/DoctrineContractRepositoryTest.php \
    --filter testFindExpired
```

#### Step 2: GREEN - Fix Test Cleanup

**Option A:** Clean up ALL contracts before test (RECOMMENDED)

**File:** `tests/Integration/Component/Repository/DoctrineContractRepositoryTest.php`

**Change line 50:**
```php
// Before:
$this->connection->executeStatement('DELETE FROM oe_payments_contract WHERE OXID LIKE "test_%"');

// After (clean ALL contracts for isolation):
$this->connection->executeStatement('DELETE FROM oe_payments_contract');
```

**Option B:** Also clean up contracts with `contract_` prefix
```php
$this->connection->executeStatement(
    'DELETE FROM oe_payments_contract WHERE OXID LIKE "test_%" OR OXID LIKE "contract_%"'
);
```

#### Step 3: REFACTOR - Verify

```bash
docker compose exec -T php vendor/bin/phpunit \
    /var/www/extensions/stripe/tests/Integration/Component/Repository/DoctrineContractRepositoryTest.php
```

---

## Issue 3: Missing Directory Structure

### Error
```
Missing directory: src/Stripe/Handler
Failed asserting that directory exists.
```

### Location
`tests/Integration/Infrastructure/ModuleStructureTest.php:86`

### Analysis

The test expects a `src/Stripe/Handler` directory to exist, but it doesn't.

**Actual directory structure (`src/Stripe/`):**
```
src/Stripe/
├── Adapter/
├── Application/
├── Controller/
├── Core/
├── EventSystem/       # Handlers are HERE: EventSystem/Handler/
├── Model/
├── Module.php
├── Repository/
├── Service/
├── Twig/
└── WebhookSignatureVerifier.php
```

From the architecture review (20251128), handlers are located in:
- `src/Stripe/EventSystem/Handler/` (actual location)
- NOT `src/Stripe/Handler/` (expected by test)

### TDD Solution

#### Step 1: RED - Verify Current Failure
```bash
docker compose exec -T php vendor/bin/phpunit \
    /var/www/extensions/stripe/tests/Integration/Infrastructure/ModuleStructureTest.php \
    --filter stripe_directories_exist
```

#### Step 2: GREEN - Update Test to Match Actual Structure

**Solution:** Update test to use correct path (test-only change)

**File:** `tests/Integration/Infrastructure/ModuleStructureTest.php`

**Change line 78:**
```php
// Before:
$requiredDirs = [
    'src/Stripe/Handler',  // WRONG - doesn't exist
    ...
];

// After:
$requiredDirs = [
    'src/Stripe/EventSystem/Handler',  // CORRECT - actual location
    ...
];
```

#### Step 3: REFACTOR - Verify

```bash
docker compose exec -T php vendor/bin/phpunit \
    /var/www/extensions/stripe/tests/Integration/Infrastructure/ModuleStructureTest.php
```

---

## Issue 4: Controller Class Registration

### Error
```
Payment controller class must exist: OxidSolutionCatalysts\Payments\Component\Controller\Core\PaymentController
Failed asserting that false is true.
```

### Location
`tests/Integration/Module/MetadataTest.php:171`

### Analysis

The `metadata.php` registers `osc_stripe_payment` with a class that doesn't exist:

```php
// metadata.php line 16 & 63:
use OxidSolutionCatalysts\Payments\Component\Controller\Core\PaymentController as PaymentComponentPaymentController;
// ...
'osc_stripe_payment' => PaymentComponentPaymentController::class,
```

But this class doesn't exist. Only these controllers exist in `Component/Controller/Core/`:
- `OrderController.php`
- `ThankyouController.php`

The actual `PaymentController` is in `Stripe/Controller/`:
- `src/Stripe/Controller/PaymentController.php` (EXISTS)

### TDD Solution

#### Step 1: RED - Verify Failure
```bash
docker compose exec -T php vendor/bin/phpunit \
    /var/www/extensions/stripe/tests/Integration/Module/MetadataTest.php \
    --filter testControllersRegistered
```

#### Step 2: GREEN - Fix metadata.php Registration

**File:** `metadata.php`

**Option A:** Update to use existing Stripe PaymentController (RECOMMENDED)

```php
// Change line 16 from:
use OxidSolutionCatalysts\Payments\Component\Controller\Core\PaymentController as PaymentComponentPaymentController;

// To:
use OxidSolutionCatalysts\Payments\Stripe\Controller\PaymentController as StripePaymentController;

// And change line 63 from:
'osc_stripe_payment' => PaymentComponentPaymentController::class,

// To:
'osc_stripe_payment' => StripePaymentController::class,
```

**Note:** Line 22 already imports `StripePaymentController`, so we may just need to fix line 63.

#### Step 3: REFACTOR - Verify

```bash
docker compose exec -T php vendor/bin/phpunit \
    /var/www/extensions/stripe/tests/Integration/Module/MetadataTest.php
```

---

## Implementation Order

1. **Issue 3 (Directory)** - Quick test fix: `src/Stripe/Handler` → `src/Stripe/EventSystem/Handler`
2. **Issue 4 (Controller)** - Fix metadata.php: remove invalid import, use existing class
3. **Issue 2 (Repository)** - Fix test cleanup to delete ALL contracts
4. **Issue 1 (EventContext)** - Fix test assertion: `paymentIntentId` → `providerTransactionId`

**All fixes are test-only or metadata config changes. No production code changes required.**

---

## Verification Checklist

- [ ] `ModuleStructureTest::stripe_directories_exist` passes
- [ ] `MetadataTest::testControllersRegistered` passes
- [ ] `DoctrineContractRepositoryTest::testFindExpired` passes
- [ ] `ControllerEventSystemIntegrationTest::testEventContext_CarriesDataThroughHandlerChain` passes
- [ ] No regression in other tests

---

## Files to Investigate

| File | Issue |
|------|-------|
| `tests/Integration/Infrastructure/ModuleStructureTest.php` | Issue 3 |
| `metadata.php` | Issue 4 |
| `tests/Integration/Module/MetadataTest.php` | Issue 4 |
| `src/Component/Repository/DoctrineContractRepository.php` | Issue 2 |
| `tests/Integration/Component/Repository/DoctrineContractRepositoryTest.php` | Issue 2 |
| `src/Stripe/EventSystem/Handler/*.php` | Issue 1 |
| `tests/Integration/Component/Controller/ControllerEventSystemIntegrationTest.php` | Issue 1 |

---

## SOLID Compliance

- **SRP**: Each fix addresses one specific issue
- **OCP**: Prefer updating tests over changing production code
- **LSP**: Controllers must implement expected interfaces
- **ISP**: Test only what's needed
- **DIP**: Tests depend on abstractions, not implementations

---

## Definition of Done

1. All 4 failing integration tests pass
2. No regression in other tests
3. Code style checks pass
4. Update `../status.md` with progress
