# Sprint 11: Fix Circular Dependency in DI Container - Completion Report

**Status:** COMPLETED
**Date:** 2025-12-16
**Duration:** ~15 minutes

---

## Summary

Fixed a circular dependency in the Symfony DI container that was causing HTTP 500 errors and maintenance mode on the shop frontend.

---

## Problem Description

The shop was entering maintenance mode (HTTP 500) due to a circular dependency in the DI container:

```
EventDispatcher → EventListenerProvider → StripeCheckoutReturnHandler → EventDispatcher
```

**Root Cause:** `EventListenerProvider` was iterating over tagged handlers in its constructor, forcing immediate instantiation. Some handlers (like `StripeCheckoutReturnHandler`) depend on `EventDispatcherInterface`, which depends on `EventListenerProvider` - creating infinite recursion.

**Error Message:**
```
Maximum call stack size of 8339456 bytes reached. Infinite recursion?
```

---

## Solution

Implemented **lazy initialization** in `EventListenerProvider`:

1. Store the handlers iterable without iterating in the constructor
2. Delay handler instantiation until `getListenersForEvent()` is called
3. Use an `$initialized` flag to prevent multiple iterations

This breaks the circular dependency because the container can now:
1. Build `EventDispatcher` (needs `EventListenerProvider`)
2. Build `EventListenerProvider` (stores handlers without iterating)
3. Build handlers only when events are actually dispatched

---

## Files Modified

### 1. EventListenerProvider.php (MODIFIED)

**File:** `src/Component/EventSystem/EventListenerProvider.php`

**Changes:**
- Added `$handlers` property to store iterable
- Added `$initialized` flag for lazy initialization
- Moved handler iteration from constructor to new `initialize()` method
- `initialize()` called on first `getListenersForEvent()` access

**Before:**
```php
public function __construct(iterable $handlers = [])
{
    foreach ($handlers as $handler) {
        $this->registerHandler($handler);
    }
}
```

**After:**
```php
public function __construct(iterable $handlers = [])
{
    // Store handlers without iterating - lazy initialization
    $this->handlers = $handlers;
}

private function initialize(): void
{
    if ($this->initialized) {
        return;
    }
    $this->initialized = true;
    foreach ($this->handlers as $handler) {
        $this->registerHandler($handler);
    }
}
```

### 2. services.yaml (MODIFIED)

**File:** `services.yaml`

**Changes:**
- Made `EventListenerProviderInterface` public for verification
- Removed unnecessary `lazy: true` from EventDispatcher (no longer needed)

---

## Test Results

```
PHPUnit Unit Tests: 1426 tests - PASS
EventListenerProvider Tests: 16 tests - PASS
```

---

## Verification

1. **CLI Container Test:**
   ```
   Container loaded
   ContractFulfillmentService: OK
   EventDispatcher: OK
   WebhookProcessingService: OK
   ```

2. **Web Frontend Test:**
   ```
   https://daniil.oxiddev.de/?lang=0  HTTP/2 200 (German)
   https://daniil.oxiddev.de/?lang=1  HTTP/2 200 (English)
   ```

3. **Admin Panel:**
   ```
   https://daniil.oxiddev.de/admin/index.php  HTTP/2 200
   ```

---

## Technical Details

### Circular Dependency Chain (Before Fix)

```
1. ContractFulfillmentService needs EventDispatcher
2. EventDispatcher needs EventListenerProvider
3. EventListenerProvider needs all payment.event_handler tagged services
4. StripeCheckoutReturnHandler (tagged handler) needs EventDispatcher
5. → Back to step 1 = Infinite recursion
```

### After Fix

```
1. ContractFulfillmentService needs EventDispatcher
2. EventDispatcher needs EventListenerProvider
3. EventListenerProvider stores handlers (doesn't iterate yet)
4. Container build completes successfully
5. When event dispatched, handlers are initialized
6. StripeCheckoutReturnHandler gets EventDispatcher (already built)
7. → No circular dependency
```

---

## Code Quality

| Check | Status | Notes |
|-------|--------|-------|
| PHPUnit Unit Tests | PASS | 1426 tests |
| EventListenerProvider Tests | PASS | 16 tests |
| Web Frontend | PASS | HTTP 200 |
| Admin Panel | PASS | HTTP 200 |

---

## Related Sprints

This fix was necessary due to changes made in Sprint 22 which moved EventDispatcher injection from ContainerFactory (runtime) to constructor injection (compile-time).

---

## SOLID Principles Applied

- **SRP:** EventListenerProvider's single responsibility is managing listeners
- **OCP:** Lazy initialization added without changing interface
- **DIP:** Handlers depend on EventDispatcherInterface abstraction
