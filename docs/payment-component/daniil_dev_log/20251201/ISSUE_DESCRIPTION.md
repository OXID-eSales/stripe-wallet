# Issue Description: Stripe Connection Failures After Friday Updates

**Date:** December 1, 2025
**Reporter:** Daniil
**Severity:** HIGH
**Component:** Stripe Payment Module Integration

---

## Executive Summary

After the updates on Friday (November 28-29, 2025), the Stripe module is experiencing connection issues. While the backend admin Stripe onboarding/connection test works successfully, the integration tests and actual payment flows fail due to two distinct issues:

1. **Unit Test Failures**: `StripeClientFactoryTest` - 5 tests failing due to method name mismatch
2. **Integration Test Failures**: 47 tests failing due to DNS resolution issues in Docker container

---

## Symptoms

### Observed Behavior

1. **Backend Admin Connection**: Works correctly
   - Stripe onboarding confirmation: SUCCESS
   - Connection test in admin panel: POSITIVE

2. **Test Suite Failures**:
   ```
   Total Tests: 852 (Unit) + 226 (Integration)
   Unit Failures: 5 (StripeClientFactoryTest)
   Integration Errors: 47 (DNS resolution failures)
   Integration Failures: 4 (Other issues)
   ```

3. **Error Messages**:
   ```
   Could not connect to Stripe (https://api.stripe.com/v1/payment_intents).
   Please check your internet connection and try again.
   (Network error [errno 6]: Could not resolve host: api.stripe.com)
   ```

---

## Root Cause Analysis

### Issue 1: StripeClientFactory Method Name Mismatch

**Location:** `src/Stripe/Adapter/StripeClientFactory.php:34`

**Problem:** The `StripeClientFactory` constructor uses `getToken()` method:
```php
public function __construct(
    private readonly ModuleConfigurationService $configurationService
) {
    $this->secretKey = $this->configurationService->getToken();  // Uses getToken()
    $this->testMode = $this->configurationService->isTestMode();
}
```

**But the test mocks `getSecretKey()`:**
```php
$this->configurationService
    ->method('getSecretKey')  // Mocks wrong method!
    ->willReturn('sk_test_...');
```

**Result:** Tests receive empty string for `secretKey`, causing:
- `create()` returns `null` instead of `StripeClient`
- `isValidSecretKey()` returns `false`

**Impact:** 5 unit test failures

### Issue 2: Docker DNS Resolution

**Location:** Docker container network configuration

**Problem:** The PHP container cannot resolve external hostnames:
```bash
# Inside container:
php -r "echo gethostbyname('api.stripe.com');"
# Returns: api.stripe.com (unchanged = resolution failed)

curl -s https://api.stripe.com
# Returns: (Network error [errno 6]: Could not resolve host)
```

**DNS Configuration (`/etc/resolv.conf`):**
```
nameserver 127.0.0.11
search .
options ndots:0
```

Docker's internal DNS resolver (127.0.0.11) is not forwarding queries to external DNS servers properly.

**Impact:** 47 integration test errors (all Stripe API calls fail)

---

## Affected Components

### Unit Tests (5 failures)
| Test | Status | Root Cause |
|------|--------|------------|
| `testCreateReturnsStripeClientWithTestKey` | FAIL | getToken vs getSecretKey |
| `testCreateReturnsStripeClientWithLiveKey` | FAIL | getToken vs getSecretKey |
| `testIsValidSecretKeyReturnsTrueForTestKey` | FAIL | getToken vs getSecretKey |
| `testIsValidSecretKeyReturnsTrueForLiveKey` | FAIL | getToken vs getSecretKey |
| `testFactoryInitializesWithConfigurationValues` | FAIL | getToken vs getSecretKey |

### Integration Tests (47 errors)
All tests in:
- `Stripe3DSecureIntegrationTest`
- `StripeAdapterIntegrationTest`
- `StripePaymentMethodIntegrationTest`
- `StripeRefundIntegrationTest`
- `StripeVaultingIntegrationTest`
- `StripeWebhookIntegrationTest`

### Integration Tests (4 failures - other issues)
| Test | Status | Root Cause |
|------|--------|------------|
| `ControllerEventSystemIntegrationTest::testEventContext_CarriesDataThroughHandlerChain` | FAIL | providerOrderId null |
| `DoctrineContractRepositoryTest::testFindExpired` | FAIL | ID mismatch |
| `ModuleStructureTest::stripe_directories_exist` | FAIL | Missing src/Stripe/Handler dir |
| `MetadataTest::testControllersRegistered` | FAIL | PaymentController class path |

---

## Environment Details

```yaml
Platform: Linux 6.8.0-88-generic
PHP Version: 8.3.22
PHPUnit: 11.5.44
Docker DNS: 127.0.0.11 (internal resolver)
Stripe SDK: Latest
OXID eShop: 8.0.x branch
```

---

## Reproduction Steps

### Unit Test Failures
```bash
docker compose exec -T php vendor/bin/phpunit \
    -c /var/www/extensions/stripe/tests/phpunit.xml \
    /var/www/extensions/stripe/tests/Unit/Stripe/Adapter/StripeClientFactoryTest.php
```

### Integration Test Failures
```bash
docker compose exec -T -e XDEBUG_MODE=coverage php vendor/bin/phpunit \
    -c /var/www/extensions/stripe/tests/phpunit.xml \
    --testsuite Integration \
    --bootstrap=/var/www/source/bootstrap.php \
    --exclude-group migration
```

---

## Proposed Solutions

### Solution 1: Fix StripeClientFactoryTest (Unit Tests)

Update test to mock `getToken()` instead of `getSecretKey()`:

```php
// Before (WRONG):
$this->configurationService
    ->method('getSecretKey')
    ->willReturn('sk_test_...');

// After (CORRECT):
$this->configurationService
    ->method('getToken')
    ->willReturn('sk_test_...');
```

**Effort:** 0.5 hours
**Risk:** Low

### Solution 2: Fix Docker DNS Resolution (Integration Tests)

**Option A: Update docker-compose.yml with explicit DNS**
```yaml
services:
  php:
    dns:
      - 8.8.8.8
      - 8.8.4.4
```

**Option B: Use network_mode: host (development only)**

**Option C: Skip network-dependent tests in CI**
Add `@group external-api` to Stripe integration tests and exclude in CI:
```bash
--exclude-group external-api
```

**Effort:** 1-2 hours
**Risk:** Medium (affects CI/CD pipeline)

### Solution 3: Fix Remaining Integration Failures

See sprint documents for detailed solutions.

---

## Priority

| Issue | Priority | Impact |
|-------|----------|--------|
| StripeClientFactoryTest | HIGH | Blocks CI, false negatives |
| Docker DNS Resolution | MEDIUM | Integration tests only |
| Other Integration Failures | LOW | Structural/config issues |

---

## Next Steps

1. Create sprint-1: Fix StripeClientFactoryTest (method mock)
2. Create sprint-2: Configure Docker DNS resolution
3. Create sprint-3: Fix remaining integration test failures
4. Update status.md with progress

---

## References

- Previous status: `../20251128/status.md`
- Architecture docs: `../../00-overview.md`
- StripeClientFactory: `src/Stripe/Adapter/StripeClientFactory.php`
- ModuleConfigurationService: `src/Stripe/Service/ModuleConfigurationService.php`
