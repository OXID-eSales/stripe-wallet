# Sprint 1: Fix StripeClientFactoryTest

**Sprint Goal:** Fix 5 failing unit tests in StripeClientFactoryTest
**Estimated Time:** 0.5 hours
**Priority:** HIGH (blocks CI)

---

## Problem Description

### Root Cause

The `StripeClientFactory` constructor uses `getToken()`:
```php
// src/Stripe/Adapter/StripeClientFactory.php:34
$this->secretKey = $this->configurationService->getToken();
```

But `StripeClientFactoryTest` mocks `getSecretKey()`:
```php
// tests/Unit/Stripe/Adapter/StripeClientFactoryTest.php
$this->configurationService
    ->method('getSecretKey')  // WRONG METHOD!
    ->willReturn('sk_test_...');
```

Since `getToken()` is never mocked, it returns `null` (default mock behavior), resulting in:
- `secretKey` is empty string
- `create()` returns `null`
- `isValidSecretKey()` returns `false`

### Failing Tests (5)

1. `testCreateReturnsStripeClientWithTestKey` - Line 45
2. `testCreateReturnsStripeClientWithLiveKey` - Line 60
3. `testIsValidSecretKeyReturnsTrueForTestKey` - Line 117
4. `testIsValidSecretKeyReturnsTrueForLiveKey` - Line 131
5. `testFactoryInitializesWithConfigurationValues` - Line 205

---

## TDD Solution

### Step 1: RED - Verify Current Failure

```bash
docker compose exec -T php vendor/bin/phpunit \
    /var/www/extensions/stripe/tests/Unit/Stripe/Adapter/StripeClientFactoryTest.php
```

Expected: 5 failures

### Step 2: GREEN - Fix Mock Method Name

**File:** `tests/Unit/Stripe/Adapter/StripeClientFactoryTest.php`

**Changes Required:**

Replace all occurrences of:
```php
$this->configurationService
    ->method('getSecretKey')
```

With:
```php
$this->configurationService
    ->method('getToken')
```

### Detailed Changes

#### Test 1: testCreateReturnsStripeClientWithTestKey (lines 33-46)
```php
public function testCreateReturnsStripeClientWithTestKey(): void
{
    $this->configurationService
        ->method('getToken')  // Changed from getSecretKey
        ->willReturn('sk_test_4242424242424242424242424242424242424242424242424242424242424242');
    $this->configurationService
        ->method('isTestMode')
        ->willReturn(true);

    $this->factory = new StripeClientFactory($this->configurationService);
    $client = $this->factory->create();

    $this->assertInstanceOf(StripeClient::class, $client);
}
```

#### Test 2: testCreateReturnsStripeClientWithLiveKey (lines 48-61)
```php
public function testCreateReturnsStripeClientWithLiveKey(): void
{
    $this->configurationService
        ->method('getToken')  // Changed from getSecretKey
        ->willReturn('sk_live_4242424242424242424242424242424242424242424242424242424242424242');
    $this->configurationService
        ->method('isTestMode')
        ->willReturn(false);

    $this->factory = new StripeClientFactory($this->configurationService);
    $client = $this->factory->create();

    $this->assertInstanceOf(StripeClient::class, $client);
}
```

#### Test 3: testCreateReturnsNullWhenSecretKeyIsEmpty (lines 63-76)
```php
public function testCreateReturnsNullWhenSecretKeyIsEmpty(): void
{
    $this->configurationService
        ->method('getToken')  // Changed from getSecretKey
        ->willReturn('');
    $this->configurationService
        ->method('isTestMode')
        ->willReturn(true);

    $this->factory = new StripeClientFactory($this->configurationService);
    $client = $this->factory->create();

    $this->assertNull($client);
}
```

#### Test 4: testIsTestModeReturnsTrueWhenConfiguredForTestMode (lines 78-90)
```php
public function testIsTestModeReturnsTrueWhenConfiguredForTestMode(): void
{
    $this->configurationService
        ->method('getToken')  // Changed from getSecretKey
        ->willReturn('sk_test_4242424242424242424242424242424242424242424242424242424242424242');
    $this->configurationService
        ->method('isTestMode')
        ->willReturn(true);

    $this->factory = new StripeClientFactory($this->configurationService);

    $this->assertTrue($this->factory->isTestMode());
}
```

#### Test 5: testIsTestModeReturnsFalseWhenConfiguredForLiveMode (lines 92-104)
```php
public function testIsTestModeReturnsFalseWhenConfiguredForLiveMode(): void
{
    $this->configurationService
        ->method('getToken')  // Changed from getSecretKey
        ->willReturn('sk_live_4242424242424242424242424242424242424242424242424242424242424242');
    $this->configurationService
        ->method('isTestMode')
        ->willReturn(false);

    $this->factory = new StripeClientFactory($this->configurationService);

    $this->assertFalse($this->factory->isTestMode());
}
```

#### Test 6: testIsValidSecretKeyReturnsTrueForTestKey (lines 106-118)
```php
public function testIsValidSecretKeyReturnsTrueForTestKey(): void
{
    $this->configurationService
        ->method('getToken')  // Changed from getSecretKey
        ->willReturn('sk_test_4242424242424242424242424242424242424242424242424242424242424242');
    $this->configurationService
        ->method('isTestMode')
        ->willReturn(true);

    $this->factory = new StripeClientFactory($this->configurationService);

    $this->assertTrue($this->factory->isValidSecretKey());
}
```

#### Test 7: testIsValidSecretKeyReturnsTrueForLiveKey (lines 120-132)
```php
public function testIsValidSecretKeyReturnsTrueForLiveKey(): void
{
    $this->configurationService
        ->method('getToken')  // Changed from getSecretKey
        ->willReturn('sk_live_4242424242424242424242424242424242424242424242424242424242424242');
    $this->configurationService
        ->method('isTestMode')
        ->willReturn(false);

    $this->factory = new StripeClientFactory($this->configurationService);

    $this->assertTrue($this->factory->isValidSecretKey());
}
```

#### Tests 8-10: Mixed scenarios (lines 134-188)
```php
// testIsValidSecretKeyReturnsFalseForTestKeyInLiveMode
$this->configurationService
    ->method('getToken')  // Changed from getSecretKey
    ->willReturn('sk_test_...');

// testIsValidSecretKeyReturnsFalseForLiveKeyInTestMode
$this->configurationService
    ->method('getToken')  // Changed from getSecretKey
    ->willReturn('sk_live_...');

// testIsValidSecretKeyReturnsFalseForInvalidKeyFormat
$this->configurationService
    ->method('getToken')  // Changed from getSecretKey
    ->willReturn('invalid_key_format');

// testIsValidSecretKeyReturnsFalseForEmptyKey
$this->configurationService
    ->method('getToken')  // Changed from getSecretKey
    ->willReturn('');
```

#### Test 11: testFactoryInitializesWithConfigurationValues (lines 190-206)
```php
public function testFactoryInitializesWithConfigurationValues(): void
{
    $testKey = 'sk_test_4242424242424242424242424242424242424242424242424242424242424242';

    $this->configurationService
        ->method('getToken')  // Changed from getSecretKey
        ->willReturn($testKey);
    $this->configurationService
        ->method('isTestMode')
        ->willReturn(true);

    $this->factory = new StripeClientFactory($this->configurationService);

    $this->assertTrue($this->factory->isTestMode());
    $this->assertTrue($this->factory->isValidSecretKey());
}
```

### Step 3: REFACTOR - Verify All Tests Pass

```bash
docker compose exec -T php vendor/bin/phpunit \
    /var/www/extensions/stripe/tests/Unit/Stripe/Adapter/StripeClientFactoryTest.php
```

Expected: 11 tests, 11 assertions, 0 failures

---

## Implementation Notes

### Alternative: Consolidate getToken() and getSecretKey()

The `ModuleConfigurationService` has both methods doing the same thing:
- `getToken()` - Used by StripeClientFactory
- `getSecretKey()` - Legacy method?

Consider future refactoring:
```php
// Option 1: Make getSecretKey() an alias
public function getSecretKey(): string
{
    return $this->getToken();
}

// Option 2: Deprecate one method
/** @deprecated Use getToken() instead */
public function getSecretKey(): string
{
    return $this->getToken();
}
```

This is OUT OF SCOPE for this sprint but noted for future cleanup.

---

## Verification Checklist

- [ ] Run `StripeClientFactoryTest` - all 11 tests pass
- [ ] Run full Unit test suite - 852 tests, 852 pass
- [ ] Run `pre-commit-check.sh` - passes
- [ ] No regression in other tests

---

## Files Modified

| File | Change |
|------|--------|
| `tests/Unit/Stripe/Adapter/StripeClientFactoryTest.php` | Replace `getSecretKey` → `getToken` |

---

## SOLID Compliance

- **SRP**: Test file tests only StripeClientFactory
- **OCP**: No changes to production code needed
- **LSP**: Mock correctly implements interface contract
- **ISP**: Only required methods mocked
- **DIP**: Test depends on interface abstraction (MockObject)

---

## Definition of Done

1. All 11 tests in `StripeClientFactoryTest` pass
2. No regression in unit test suite (852 tests pass)
3. Code style checks pass (PHPStan, PHPCS)
4. Update `../status.md` with progress
