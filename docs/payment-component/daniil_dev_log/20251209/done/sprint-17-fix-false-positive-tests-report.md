# Sprint 17: Fix False-Positive Tests - COMPLETED

**Date:** 2025-12-09
**Status:** COMPLETED
**Branch:** b-7.4.x-code-review

---

## Summary

Fixed false-positive tests that always passed regardless of implementation. Removed `assertTrue(true)` assertions and moved hidden assertions from mock callbacks to test level.

---

## Issues Fixed

### 1. WebhookEventDispatcherTest::canRegisterMultipleHandlers

**Before:** Test just called `assertTrue(true)` after registering handlers - no verification of behavior.

**After:** Test verifies that both registered handlers are checked during dispatch:
- First handler doesn't support the event and is skipped
- Second handler supports and handles the event
- Explicit assertions verify the result

**File:** `tests/Unit/Component/Webhook/WebhookEventDispatcherTest.php:197-228`

### 2. AddressHashRestorationTest::testAddressHashRestoredBeforePaymentEvent

**Before:** Hidden assertion inside `willReturnCallback` that only ran IF dispatch was called. If dispatch was never called, the test passed without verifying anything (false positive).

**After:** Explicit assertions at test level:
- Captures state when dispatch is called using variables
- After `handle()` completes, verifies the captured state
- Works correctly whether dispatch is called or not

**File:** `tests/Unit/Stripe/EventSystem/Handler/AddressHashRestorationTest.php:306-396`

### 3. HelloWorldTest::it_runs_phpunit_successfully

**Before:** Test with `assertTrue(true)` - a "bootstrap test" that verified nothing meaningful.

**After:** Removed the false-positive test entirely. Kept the meaningful tests:
- `it_has_correct_namespace()` - verifies test namespace
- `it_can_access_oxid_constants()` - verifies OXID bootstrap

**File:** `tests/Unit/Watch/HelloWorldTest.php`

---

## Files Modified

| File | Change |
|------|--------|
| `tests/Unit/Component/Webhook/WebhookEventDispatcherTest.php` | Replaced `assertTrue(true)` with meaningful assertions |
| `tests/Unit/Stripe/EventSystem/Handler/AddressHashRestorationTest.php` | Moved hidden assertion to test level |
| `tests/Unit/Watch/HelloWorldTest.php` | Removed false-positive test, updated docs |

---

## Code Changes

### WebhookEventDispatcherTest - Before vs After

```php
// BEFORE (false positive):
public function canRegisterMultipleHandlers(): void
{
    $handler1 = $this->createMock(WebhookEventHandlerInterface::class);
    $handler2 = $this->createMock(WebhookEventHandlerInterface::class);

    $this->dispatcher->registerHandler($handler1);
    $this->dispatcher->registerHandler($handler2);

    // Should not throw
    $this->assertTrue(true);  // FALSE POSITIVE
}

// AFTER (meaningful test):
public function canRegisterMultipleHandlers(): void
{
    $event = new WebhookEvent('evt_123', 'some.event', [], 0);

    // First handler doesn't support, second does
    $handler1 = $this->createMock(WebhookEventHandlerInterface::class);
    $handler1->expects($this->once())->method('supports')->willReturn(false);
    $handler1->expects($this->never())->method('handle');

    $handler2 = $this->createMock(WebhookEventHandlerInterface::class);
    $handler2->expects($this->once())->method('supports')->willReturn(true);
    $handler2->expects($this->once())->method('handle')
        ->willReturn(WebhookResult::success('handled_by_second'));

    $this->dispatcher->registerHandler($handler1);
    $this->dispatcher->registerHandler($handler2);

    $result = $this->dispatcher->dispatch($event);

    // EXPLICIT ASSERTIONS
    $this->assertTrue($result->isSuccess());
    $this->assertSame('handled_by_second', $result->action);
}
```

### AddressHashRestorationTest - Before vs After

```php
// BEFORE (hidden assertion - may never execute):
$this->eventDispatcher
    ->method('dispatch')
    ->willReturnCallback(function ($event) use (&$hashRestoredBeforeDispatch) {
        $this->assertTrue($hashRestoredBeforeDispatch);  // HIDDEN ASSERTION
        return $event;
    });

// ... later ...
$this->assertTrue(true);  // FALSE POSITIVE IF DISPATCH NOT CALLED

// AFTER (explicit at test level):
$hashStateAtDispatch = null;
$dispatchWasCalled = false;

$this->eventDispatcher
    ->method('dispatch')
    ->willReturnCallback(function ($event) use (&$hashRestoredBeforeDispatch, &$dispatchWasCalled, &$hashStateAtDispatch) {
        $dispatchWasCalled = true;
        $hashStateAtDispatch = $hashRestoredBeforeDispatch;  // CAPTURE STATE
        return $event;
    });

// ... later ...
// EXPLICIT ASSERTIONS AT TEST LEVEL
if ($dispatchWasCalled) {
    $this->assertTrue($hashStateAtDispatch, 'Hash should be restored BEFORE dispatch');
} else {
    $this->assertTrue($hashRestoredBeforeDispatch, 'Hash should be restored even without dispatch');
}
```

---

## Hidden Assertions Analysis

The sprint plan identified assertions inside callbacks as problematic. Analysis revealed:

| Test File | Pattern | Safe? | Reason |
|-----------|---------|-------|--------|
| AddressHashRestorationTest | Hidden + `assertTrue(true)` | NO | Callback might never run |
| StockReleaseHandlerTest | Hidden in `expects($this->exactly(2))` | YES | `expects()` guarantees callback runs |
| StockReservationHandlerTest | Hidden in `expects($this->exactly(2))` | YES | `expects()` guarantees callback runs |
| OrderRefundControllerTest | Hidden in `expects($this->once())` | YES | `expects()` guarantees callback runs |

**Conclusion:** Only `AddressHashRestorationTest` was truly problematic because it used `->method()` (no call count verification) combined with `assertTrue(true)` as fallback.

---

## Verification

```bash
# Verify no more assertTrue(true) in test code:
grep -rn "assertTrue(true)" tests/
# Result: Only found in Sprint 17 removal comment

# Run tests:
./bin/pre-commit-check.sh --full
# Result: ALL CHECKS PASSED
# Tests: 1602, Assertions: 4055
```

---

## Test Results

```
Tests: 1602, Assertions: 4055
PHPStan: OK (No errors)
PHPCS: OK (PSR-12 compliant)
PHPMD: OK

Status: COMMITABLE
```

---

## Test Quality Improvements

| Metric | Before | After |
|--------|--------|-------|
| `assertTrue(true)` occurrences | 3 | 0 |
| Hidden assertions (problematic) | 1 | 0 |
| Tests with explicit assertions | All others | All |

---

## Related Issues

- CODE_REVIEW.md Section 3.1 (CRITICAL: False-Positive Tests) - **RESOLVED**
- CODE_REVIEW.md Section 3.2 (CRITICAL: Hidden Assertions) - **RESOLVED**

---

**Completed:** 2025-12-09
**Author:** Claude Code (Sprint 17)
