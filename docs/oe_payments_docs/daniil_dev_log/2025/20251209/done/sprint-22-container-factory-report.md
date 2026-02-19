# Sprint 22: Resolve ContainerFactory Usage - Completion Report

**Date:** 2025-12-09
**Status:** COMPLETED
**Branch:** b-7.4.x-code-review

---

## Overview

Sprint 22 removed the ContainerFactory anti-pattern from all event handlers by injecting `EventDispatcherInterface` via constructor injection.

---

## Problem Solved

**Before:** Handlers used ContainerFactory to lazily fetch EventDispatcher to avoid circular dependencies during container initialization.

```php
// BEFORE (anti-pattern):
private function getEventDispatcher(): EventDispatcherInterface
{
    return ContainerFactory::getInstance()
        ->getContainer()
        ->get(EventDispatcherInterface::class);
}
```

**After:** EventDispatcher is injected via constructor, proper DI.

```php
// AFTER (proper DI):
public function __construct(
    // ... other dependencies ...
    private readonly EventDispatcherInterface $eventDispatcher
) {}
```

---

## Handlers Updated

| Handler | Location | Change |
|---------|----------|--------|
| `PaymentAuthorizedEventHandler` | `src/Component/EventSystem/Handler/` | EventDispatcher injected |
| `StripeOrderCreationHandler` | `src/Stripe/EventSystem/Handler/` | EventDispatcher injected |
| `StripeCheckoutReturnHandler` | `src/Stripe/EventSystem/Handler/` | EventDispatcher injected |

---

## Files Modified

### Handlers
- `src/Component/EventSystem/Handler/PaymentAuthorizedEventHandler.php`
  - Removed ContainerFactory import
  - Added EventDispatcherInterface to constructor
  - Changed `$this->getEventDispatcher()->dispatch()` to `$this->eventDispatcher->dispatch()`

- `src/Stripe/EventSystem/Handler/StripeOrderCreationHandler.php`
  - Same changes as above

- `src/Stripe/EventSystem/Handler/StripeCheckoutReturnHandler.php`
  - Same changes as above

### Configuration
- `services.yaml`
  - Added `$eventDispatcher` argument to all three handlers

### Interface
- `src/Component/Contract/PaymentContractInterface.php`
  - Added missing `transitionToPending()` method
  - Added missing `fulfillCondition()` method
  - (Fixed pre-existing PHPStan errors)

### Tests
- `tests/Unit/Stripe/EventSystem/Handler/StripeCheckoutReturnHandlerTest.php`
  - Removed TestableStripeCheckoutReturnHandler (no longer needed)
  - Updated createHandler() to use new constructor

- `tests/Unit/Stripe/EventSystem/Handler/AddressHashRestorationTest.php`
  - Removed TestableAddressHashHandler (no longer needed)
  - Updated createHandler() to use new constructor

---

## Verification

### No ContainerFactory in Handlers

```bash
$ grep -rn "ContainerFactory" src/*/EventSystem/Handler/
# Only comments mentioning the change remain
```

### All Tests Pass

```
PHPUnit 11.5.44
Tests: 1348, Assertions: 3209
Status: OK
```

### Quality Checks

```
✓ PHP Code Sniffer passed
✓ PHPStan passed
✓ PHPMD passed
Status: COMMITABLE
```

---

## Why No Circular Dependency Now?

The DI container (Symfony) handles the dependency resolution order correctly:

1. Handlers are tagged with `payment.event_handler`
2. EventListenerProvider collects handlers via `!tagged_iterator`
3. EventDispatcher depends on EventListenerProvider
4. When a handler needs EventDispatcher, it's already fully initialized

The key insight is that the container builds the dependency graph lazily - handlers are instantiated AFTER the EventDispatcher is built, so there's no actual circular dependency.

---

## SOLID Compliance

| Principle | Implementation |
|-----------|----------------|
| **SRP** | Handlers only handle events |
| **OCP** | EventDispatcher can be swapped via interface |
| **LSP** | Any EventDispatcherInterface impl works |
| **ISP** | Handlers depend only on needed interfaces |
| **DIP** | All dependencies via constructor injection |

---

## Related Issues

- CODE_REVIEW.md Section 1.5 (HIGH: ContainerFactory Anti-Pattern) - **RESOLVED**
- CODE_REVIEW.md Section 4.7 (MEDIUM: ContainerFactory Access Pattern) - **RESOLVED**

---

## Success Criteria

- ✅ No ContainerFactory in handlers
- ✅ All dependencies via constructor
- ✅ services.yaml updated with EventDispatcher
- ✅ No circular dependency errors
- ✅ All unit tests pass (1348 tests)
- ✅ PHPStan level 6 passed
- ✅ PHPCS (PSR-12) passed
- ✅ PHPMD passed

---

**Completed:** 2025-12-09
**Author:** Claude Code (AI Assistant)
