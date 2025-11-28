# Test Fix Plan

**Date:** 2025-11-28
**Author:** Daniil Tkachev (AI-assisted)
**Status:** Action Required

---

## Executive Summary

| Metric | Count |
|--------|-------|
| Total Tests | 1109 |
| Passing | 984 |
| Errors | 47 |
| Failures | 8 |
| Skipped | 68 |
| Deprecations | 253 |

**Pass Rate:** 88.7% (excluding skipped)

---

## Failure Categories

### Category 1: Stripe API Network Errors (47 errors)
**Impact:** Integration tests failing due to Docker network isolation
**Root Cause:** Docker container cannot resolve `api.stripe.com`
**Affected Tests:** All Stripe Integration tests

### Category 2: StripeClientFactory Unit Test Failures (5 failures)
**Impact:** Factory tests failing due to implementation mismatch
**Root Cause:** `getSecretKey()` vs `getToken()` method call
**Affected File:** `tests/Unit/Stripe/Adapter/StripeClientFactoryTest.php`

### Category 3: Module Structure Test Failure (1 failure)
**Impact:** Directory structure test failing
**Root Cause:** Missing `src/Stripe/Handler` directory
**Affected File:** `tests/Integration/Infrastructure/ModuleStructureTest.php`

### Category 4: Metadata Test Failure (1 failure)
**Impact:** Controller registration test failing
**Root Cause:** PaymentController class doesn't exist at expected path
**Affected File:** `tests/Integration/Module/MetadataTest.php`

### Category 5: Event Context Integration Test (1 failure)
**Impact:** Context propagation test failing
**Root Cause:** `paymentIntentId` not being set in context
**Affected File:** `tests/Integration/Component/Controller/ControllerEventSystemIntegrationTest.php`

---

## Detailed Fix Plan

### Sprint 1: Fix Unit Test Failures (5 tests)

**Priority:** HIGH
**Effort:** ~30 minutes
**File:** `src/Stripe/Adapter/StripeClientFactory.php`

#### Issue Analysis

The test mocks `getSecretKey()` but the implementation calls `getToken()`:

**Test Code (StripeClientFactoryTest.php:35-37):**
```php
$this->configurationService
    ->method('getSecretKey')
    ->willReturn('sk_test_...');
```

**Implementation (StripeClientFactory.php:34):**
```php
$this->secretKey = $this->configurationService->getToken();
```

#### Fix Options

**Option A: Update Implementation to Match Tests** (Recommended)
```php
// In StripeClientFactory.php line 34
$this->secretKey = $this->configurationService->getSecretKey();
```

**Option B: Update Tests to Match Implementation**
```php
// In StripeClientFactoryTest.php, change all getSecretKey() to getToken()
$this->configurationService
    ->method('getToken')
    ->willReturn('sk_test_...');
```

#### Affected Tests After Fix
- `testCreateReturnsStripeClientWithTestKey`
- `testCreateReturnsStripeClientWithLiveKey`
- `testIsValidSecretKeyReturnsTrueForTestKey`
- `testIsValidSecretKeyReturnsTrueForLiveKey`
- `testFactoryInitializesWithConfigurationValues`

---

### Sprint 2: Fix Module Structure Test (1 test)

**Priority:** MEDIUM
**Effort:** ~5 minutes

#### Issue
Test expects `src/Stripe/Handler` directory to exist, but handlers are in `src/Stripe/EventSystem/Handler/`.

#### Fix Options

**Option A: Create Missing Directory** (Quick fix)
```bash
mkdir -p src/Stripe/Handler
```

**Option B: Update Test to Match Reality** (Recommended)
```php
// In ModuleStructureTest.php line 78
$requiredDirs = [
    'src/Stripe/EventSystem/Handler',  // Changed from 'src/Stripe/Handler'
    'src/Stripe/Service',
    // ...
];
```

---

### Sprint 3: Fix Metadata Test (1 test)

**Priority:** MEDIUM
**Effort:** ~15 minutes

#### Issue
Test expects `OxidSolutionCatalysts\Payments\Component\Controller\Core\PaymentController` but this class doesn't exist.

**Test Code (MetadataTest.php:170-171):**
```php
$this->assertTrue(
    class_exists($controllers['osc_stripe_payment']),
    'Payment controller class must exist: ' . $controllers['osc_stripe_payment']
);
```

#### Fix Options

**Option A: Update metadata.php to Register Correct Controller**
Check `metadata.php` and ensure `osc_stripe_payment` points to an existing controller class.

**Option B: Create Missing Controller Class**
If the controller should exist at `Component\Controller\Core\PaymentController`, create it.

**Option C: Update Test to Allow Missing Controller**
Skip this assertion if controller is not yet implemented:
```php
if (isset($controllers['osc_stripe_payment'])) {
    $this->assertTrue(
        class_exists($controllers['osc_stripe_payment']),
        'Payment controller class must exist'
    );
}
```

---

### Sprint 4: Fix Event Context Test (1 test)

**Priority:** MEDIUM
**Effort:** ~30 minutes

#### Issue
`testEventContext_CarriesDataThroughHandlerChain` expects `paymentIntentId` in context, but it's not being set.

**Test Code (line 664):**
```php
$this->assertEquals('pi_context_test_' . $this->testRunId, $context->get('paymentIntentId'));
```

#### Analysis
The `CheckoutOrchestrator::processCheckout()` receives `paymentIntentId` as 4th argument but may not be setting it in the event context.

#### Fix
Ensure `CheckoutOrchestrator` sets `paymentIntentId` in the `EventContext` before dispatching `PaymentInitiatedEvent`:

```php
// In CheckoutOrchestrator.php
$context->set('paymentIntentId', $paymentIntentId);
```

---

### Sprint 5: Handle Integration Test Network Issues (47 tests)

**Priority:** LOW (Infrastructure issue)
**Effort:** ~1 hour (configuration)

#### Issue
Docker container cannot reach `api.stripe.com`:
```
Could not resolve host: api.stripe.com
```

#### Fix Options

**Option A: Skip Integration Tests in CI** (Quick fix)
Add `@group stripe-api` to integration tests and exclude in phpunit.xml:
```xml
<groups>
    <exclude>
        <group>stripe-api</group>
    </exclude>
</groups>
```

**Option B: Configure Docker Network**
Ensure Docker container has internet access:
```yaml
# docker-compose.yml
services:
  php:
    network_mode: bridge
    dns:
      - 8.8.8.8
      - 8.8.4.4
```

**Option C: Mark Tests as Requiring Network** (Recommended)
```php
// In StripeIntegrationTestCase.php
protected function setUp(): void
{
    if (!$this->hasNetworkAccess()) {
        $this->markTestSkipped('No network access to Stripe API');
    }
}

private function hasNetworkAccess(): bool
{
    return @fsockopen('api.stripe.com', 443, $errno, $errstr, 5) !== false;
}
```

**Option D: Use Mock Server**
Set up WireMock or similar for integration tests (documented in `10-01-provider-module-testing.md`).

---

## Implementation Order

| Order | Sprint | Tests Fixed | Priority | Effort |
|-------|--------|-------------|----------|--------|
| 1 | Sprint 1 | 5 | HIGH | 30 min |
| 2 | Sprint 2 | 1 | MEDIUM | 5 min |
| 3 | Sprint 3 | 1 | MEDIUM | 15 min |
| 4 | Sprint 4 | 1 | MEDIUM | 30 min |
| 5 | Sprint 5 | 47 | LOW | 1 hour |

**Total Effort:** ~2.5 hours

---

## File Changes Summary

### Files to Modify

| File | Change |
|------|--------|
| `src/Stripe/Adapter/StripeClientFactory.php` | Change `getToken()` to `getSecretKey()` |
| `tests/Integration/Infrastructure/ModuleStructureTest.php` | Update expected directory path |
| `metadata.php` OR create missing controller | Fix controller registration |
| `src/Component/Service/CheckoutOrchestrator.php` | Set `paymentIntentId` in context |
| `tests/Integration/Stripe/StripeIntegrationTestCase.php` | Add network check skip |

### Files to Create (Optional)

| File | Purpose |
|------|---------|
| `src/Stripe/Handler/.gitkeep` | Satisfy directory test (if keeping test as-is) |
| `src/Component/Controller/Core/PaymentController.php` | Missing controller (if needed) |

---

## Verification Commands

After implementing fixes, run:

```bash
# Run all unit tests only
docker compose exec -T php bash -c "cd /var/www/extensions/stripe && vendor/bin/phpunit -c tests/phpunit.xml --testsuite Unit"

# Run specific failing tests
docker compose exec -T php bash -c "cd /var/www/extensions/stripe && vendor/bin/phpunit -c tests/phpunit.xml --filter StripeClientFactoryTest"

# Run module structure test
docker compose exec -T php bash -c "cd /var/www/extensions/stripe && vendor/bin/phpunit -c tests/phpunit.xml --filter ModuleStructureTest"

# Run metadata test
docker compose exec -T php bash -c "cd /var/www/extensions/stripe && vendor/bin/phpunit -c tests/phpunit.xml --filter MetadataTest"

# Run all tests (excluding network-dependent)
docker compose exec -T php bash -c "cd /var/www/extensions/stripe && vendor/bin/phpunit -c tests/phpunit.xml --exclude-group stripe-api"
```

---

## Deprecation Warnings (253 total)

These are PHPUnit deprecation warnings, primarily:
- PHPUnit 11 deprecations for test method naming
- `@test` annotation deprecation
- Configuration warnings

**Action:** Address in future cleanup sprint, not blocking.

---

## Skipped Tests (68 total)

Tests marked as skipped are intentionally skipped due to:
- Missing dependencies
- Environment conditions
- Feature flags

**Action:** Review and enable as features become available.

---

## Appendix: Full Error List

### Errors (47 - All Network Related)

| Test Class | Test Method | Error |
|------------|-------------|-------|
| `Stripe3DSecureIntegrationTest` | All 11 tests | Cannot resolve api.stripe.com |
| `StripeAdapterIntegrationTest` | All 15 tests | Cannot resolve api.stripe.com |
| `StripeAuthorizationFlowIntegrationTest` | All 8 tests | Cannot resolve api.stripe.com |
| `StripePaymentMethodIntegrationTest` | All 13 tests | Cannot resolve api.stripe.com |

### Failures (8)

| Test Class | Test Method | Assertion |
|------------|-------------|-----------|
| `StripeClientFactoryTest` | `testCreateReturnsStripeClientWithTestKey` | Expected StripeClient, got null |
| `StripeClientFactoryTest` | `testCreateReturnsStripeClientWithLiveKey` | Expected StripeClient, got null |
| `StripeClientFactoryTest` | `testIsValidSecretKeyReturnsTrueForTestKey` | Expected true, got false |
| `StripeClientFactoryTest` | `testIsValidSecretKeyReturnsTrueForLiveKey` | Expected true, got false |
| `StripeClientFactoryTest` | `testFactoryInitializesWithConfigurationValues` | Expected true, got false |
| `ControllerEventSystemIntegrationTest` | `testEventContext_CarriesDataThroughHandlerChain` | Expected pi_context_test_*, got null |
| `ModuleStructureTest` | `stripe_directories_exist` | Missing src/Stripe/Handler |
| `MetadataTest` | `testControllersRegistered` | PaymentController class not found |

---

**Report Generated:** 2025-11-28
**Next Steps:** Begin Sprint 1 implementation
