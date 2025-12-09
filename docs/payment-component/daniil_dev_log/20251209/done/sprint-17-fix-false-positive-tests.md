# Sprint 17: Fix False-Positive Tests

**Date:** 2025-12-09
**Priority:** CRITICAL
**Status:** PENDING
**Branch:** TBD (b-7.4.x-STRP-XX)
**Est. Effort:** 2 hours

---

## Development Principles Checklist

| Principle | How Applied |
|-----------|-------------|
| **TDD-FIRST** | Tests must have meaningful assertions |
| **SOLID-SRP** | Each test verifies ONE behavior |
| **Clean Code** | AAA pattern (Arrange-Act-Assert) |
| **No Over-Testing** | Remove tests that verify nothing |
| **Containerization** | All tests via `docker compose exec` |

---

## Problem Statement

**False-positive tests that always pass regardless of implementation:**

| File | Line | Issue |
|------|------|-------|
| `WebhookEventDispatcherTest.php` | 206 | `$this->assertTrue(true)` |
| `AddressHashRestorationTest.php` | 383 | `$this->assertTrue(true)` |
| `HelloWorldTest.php` | 20-23 | Bootstrap test with no real assertion |

**Additional Issues:**

| Category | Description | Impact |
|----------|-------------|--------|
| Hidden Assertions | Assertions inside mock callbacks | Unclear failure messages |
| Implementation Coupling | Tests verify SQL structure, not behavior | Break on refactoring |
| Loose Mock Expectations | 295 instances of `->method()` without `->expects()` | No interaction verification |

---

## Root Cause Analysis

1. **Placeholder tests** - Tests written as TODOs, never completed
2. **Complex mock setup** - Assertions hidden in callbacks
3. **Implementation testing** - Testing HOW vs WHAT
4. **Missing test discipline** - No TDD enforcement

---

## Solution Design

### Issue 1: `assertTrue(true)` Tests

**File:** `tests/Unit/Component/Webhook/WebhookEventDispatcherTest.php:206`

```php
// BEFORE (false positive):
public function testDispatchHandlesNullHandler(): void
{
    $this->assertTrue(true);
}

// AFTER (meaningful test):
public function testDispatchHandlesNullHandler(): void
{
    // Arrange
    $dispatcher = new WebhookEventDispatcher($this->handlerRegistry);
    $event = new WebhookEvent('test_event', []);

    // Register null handler
    $this->handlerRegistry
        ->method('getHandler')
        ->willReturn(null);

    // Act
    $result = $dispatcher->dispatch($event);

    // Assert
    $this->assertNull($result);
}

// OR delete if behavior is not needed
```

**File:** `tests/Unit/Stripe/EventSystem/Handler/AddressHashRestorationTest.php:383`

```php
// BEFORE (false positive):
public function testRestorationCompletesSuccessfully(): void
{
    // Complex mock setup...
    $this->assertTrue(true);
}

// AFTER (meaningful test):
public function testRestorationCompletesSuccessfully(): void
{
    // Arrange
    $checkoutSession = $this->createCheckoutSessionMock([
        'metadata' => ['delivery_address_md5' => 'abc123']
    ]);

    // Act
    $result = $this->handler->restoreAddressHash($checkoutSession);

    // Assert
    $this->assertTrue($result);
    $this->assertSame('abc123', $this->session->getVariable('sDeliveryAddressMD5'));
}
```

**File:** `tests/Unit/Watch/HelloWorldTest.php`

```php
// BEFORE (bootstrap test):
public function testHelloWorld(): void
{
    $this->assertTrue(true);
}

// AFTER: DELETE entire test file
// OR make it meaningful:
public function testWatchModuleCanBeInstantiated(): void
{
    // Arrange & Act
    $watch = new PaymentWatch();

    // Assert
    $this->assertInstanceOf(PaymentWatch::class, $watch);
}
```

### Issue 2: Hidden Assertions in Mock Callbacks

**File:** `tests/Unit/Stripe/EventSystem/Handler/AddressHashRestorationTest.php:361-364`

```php
// BEFORE (hidden assertion):
$this->eventDispatcher
    ->method('dispatch')
    ->willReturnCallback(function ($event) use (&$hashRestoredBeforeDispatch) {
        $this->assertTrue($this->session->hasVariable('sDeliveryAddressMD5'));
        return $event;
    });

// AFTER (explicit assertion):
public function testHashRestoredBeforeEventDispatch(): void
{
    // Arrange
    $hashRestored = false;
    $this->eventDispatcher
        ->expects($this->once())
        ->method('dispatch')
        ->willReturnCallback(function ($event) use (&$hashRestored) {
            $hashRestored = $this->session->hasVariable('sDeliveryAddressMD5');
            return $event;
        });

    // Act
    $this->handler->handle($this->checkoutReturnEvent);

    // Assert - explicit, at test level
    $this->assertTrue($hashRestored, 'Hash should be restored before event dispatch');
}
```

### Issue 3: Implementation Coupling

**File:** `tests/Unit/Stripe/Service/OxpaidReconciliationServiceTest.php:72-92`

```php
// BEFORE (tests SQL structure):
$this->connection
    ->expects($this->once())
    ->method('executeStatement')
    ->with($this->stringContains("OXPAID = '0000-00-00 00:00:00'"));

// AFTER (tests behavior):
public function testReconcilesFindOrdersWithEmptyPaidDate(): void
{
    // Arrange
    $ordersToReconcile = [
        ['OXID' => 'order-1', 'OXTRANSID' => 'pi_123'],
    ];
    $this->connection
        ->expects($this->once())
        ->method('fetchAllAssociative')
        ->willReturn($ordersToReconcile);

    // Act
    $result = $this->service->findOrdersNeedingReconciliation();

    // Assert - behavior, not implementation
    $this->assertCount(1, $result);
    $this->assertSame('order-1', $result[0]['OXID']);
}
```

### Issue 4: Loose Mock Expectations

**Pattern to fix:**

```php
// BEFORE (loose - any number of calls):
$this->repository
    ->method('save')
    ->willReturn(true);

// AFTER (strict - exact call count):
$this->repository
    ->expects($this->once())
    ->method('save')
    ->with($this->isInstanceOf(Contract::class))
    ->willReturn(true);
```

---

## Implementation Steps

### Step 1: Audit All `assertTrue(true)` Occurrences

```bash
# Find all false positives
grep -rn "assertTrue(true)" tests/
```

### Step 2: Fix or Delete Each Test

For each occurrence:
1. **Understand intent** - Read surrounding code/comments
2. **Decide action**:
   - Add meaningful assertion
   - OR delete test if no behavior to verify
3. **Run tests** - Verify no regressions

### Step 3: Fix Hidden Assertions

```bash
# Find assertions in callbacks
grep -rn "willReturnCallback.*assert" tests/
```

For each occurrence:
1. Move assertion to test method level
2. Use clear variable capture pattern
3. Add descriptive failure message

### Step 4: Add Missing `expects()` Calls

Priority files (highest mock usage):
1. `AddressHashRestorationTest.php` (435 lines)
2. `ModuleConfigurationServiceTest.php` (797 lines)
3. `StripeCheckoutReturnHandlerTest.php` (711 lines)

For each file:
1. Add `expects($this->once())` or `expects($this->never())`
2. Verify call counts match actual behavior

### Step 5: Quality Checks

```bash
# Run all unit tests
docker compose exec -T php bash -c "cd /var/www/test-module && vendor/bin/phpunit -c tests/phpunit.xml --testsuite Unit"

# Check for remaining false positives
grep -rn "assertTrue(true)" tests/
# Should return: nothing
```

---

## Files to Modify

| File | Action | Issue |
|------|--------|-------|
| `WebhookEventDispatcherTest.php` | Fix or delete test | `assertTrue(true)` |
| `AddressHashRestorationTest.php` | Fix test, move assertions | `assertTrue(true)`, hidden assertions |
| `HelloWorldTest.php` | Delete file | No real assertion |
| `OxpaidReconciliationServiceTest.php` | Test behavior, not SQL | Implementation coupling |
| `ModuleConfigurationServiceTest.php` | Add `expects()` calls | Loose mocks |
| `StripeCheckoutReturnHandlerTest.php` | Add `expects()` calls | Loose mocks |

---

## Test Quality Checklist

For each test file:

- [ ] No `assertTrue(true)` or `assertFalse(false)`
- [ ] No assertions inside mock callbacks
- [ ] All mocks have `expects()` with count
- [ ] Tests verify behavior, not implementation
- [ ] AAA pattern (Arrange-Act-Assert)
- [ ] One logical assertion per test
- [ ] Descriptive test method names

---

## Verification Commands

```bash
# Check for false positives
grep -rn "assertTrue(true)" tests/
grep -rn "assertFalse(false)" tests/
# Should return: nothing

# Check for hidden assertions
grep -rn "willReturnCallback.*assert" tests/
# Should return: nothing

# Check for loose mocks (sample)
grep -rn "->method(" tests/ | grep -v "expects" | head -20
# Review and fix high-impact ones

# Run all tests
docker compose exec -T php bash -c "cd /var/www/test-module && vendor/bin/phpunit -c tests/phpunit.xml --testsuite Unit"
```

---

## Risk Assessment

| Risk | Impact | Mitigation |
|------|--------|------------|
| Breaking valid tests | Medium | Run tests after each change |
| Missing coverage | Low | False positives provided no coverage anyway |
| Time investment | Low | Prioritize CRITICAL files |

---

## Success Criteria

1. ✅ No `assertTrue(true)` in test suite
2. ✅ No assertions hidden in mock callbacks
3. ✅ High-priority tests have `expects()` calls
4. ✅ All unit tests pass
5. ✅ Test failures provide clear messages

---

## Related Issues

- CODE_REVIEW.md Section 3.1 (CRITICAL: False-Positive Tests)
- CODE_REVIEW.md Section 3.2 (CRITICAL: Hidden Assertions)
- CODE_REVIEW.md Section 3.3 (CRITICAL: Implementation Coupling)
- CODE_REVIEW.md Section 3.8 (MEDIUM: Loose Mock Expectations)

---

**Last Updated:** 2025-12-09
