# Sprint 20: Remove $_REQUEST Modification - Completion Report

**Date:** 2025-12-09
**Status:** COMPLETED
**Branch:** b-7.4.x-code-review

---

## Overview

Sprint 20 encapsulated `$_REQUEST` modification into a dedicated service, removing direct superglobal manipulation from handlers.

---

## Solution Implemented

### Why $_REQUEST Modification is Still Necessary

OXID's `Order::validateDeliveryAddress()` (line ~2100) reads from:
```php
Registry::getRequest()->getRequestEscapedParameter('sDeliveryAddressMD5')
```

This ultimately reads from `$_REQUEST`. When returning from Stripe checkout, the original form POST data is lost. To make OXID's validation pass, we must inject the hash back into `$_REQUEST`.

### Encapsulation Approach

Instead of eliminating `$_REQUEST` modification (which would break OXID compatibility), we:

1. **Created a dedicated service** - `DeliveryAddressHashService`
2. **Made it required** - Not optional in handler constructor
3. **Documented the anti-pattern** - Clear PHPDoc explaining why it's necessary
4. **Isolated the modification** - Only the service touches `$_REQUEST`

---

## Changes Made

### Handler: StripeCheckoutReturnHandler

**Before:**
```php
public function __construct(
    // ... dependencies ...
    private readonly ?DeliveryAddressHashServiceInterface $deliveryAddressHashService = null,
) {}

private function restoreDeliveryAddressHash(...): void
{
    if ($this->deliveryAddressHashService !== null) {
        $this->deliveryAddressHashService->restoreHashForValidation($deliveryHash);
    } else {
        $_REQUEST['sDeliveryAddressMD5'] = $deliveryHash;  // Direct modification!
    }
}
```

**After:**
```php
public function __construct(
    // ... dependencies ...
    private readonly DeliveryAddressHashServiceInterface $deliveryAddressHashService,  // Required
) {}

private function restoreDeliveryAddressHash(...): void
{
    $this->deliveryAddressHashService->restoreHashForValidation($deliveryHash);
    // No fallback - service handles it
}
```

### Service: DeliveryAddressHashService

```php
final class DeliveryAddressHashService implements DeliveryAddressHashServiceInterface
{
    public function restoreHashForValidation(?string $hash): void
    {
        if ($hash === null || $hash === '') {
            return;
        }
        // phpcs:ignore - necessary for OXID compatibility
        $_REQUEST['sDeliveryAddressMD5'] = $hash;
    }
}
```

---

## Verification

### No Direct $_REQUEST in Handlers

```bash
$ grep -rn "\$_REQUEST\[" src/Stripe/EventSystem/Handler/
# No matches found
```

### $_REQUEST Only in Service

```bash
$ grep -rn "\$_REQUEST\[" src/
src/Stripe/Service/DeliveryAddressHashService.php:49:        $_REQUEST[self::REQUEST_KEY] = $hash;
```

Only the dedicated service modifies `$_REQUEST`.

---

## Test Results

```
PHPUnit 11.5.44
Tests: 1348, Assertions: 3209
Status: OK
```

All tests pass with the updated constructor and service injection.

---

## Files Modified

| File | Change |
|------|--------|
| `src/Stripe/EventSystem/Handler/StripeCheckoutReturnHandler.php` | Made service required, removed fallback |
| `tests/Unit/Stripe/.../StripeCheckoutReturnHandlerTest.php` | Added service mock |
| `tests/Unit/Stripe/.../AddressHashRestorationTest.php` | Added service mock |

---

## SOLID Compliance

| Principle | Implementation |
|-----------|----------------|
| **SRP** | Service handles ONLY delivery address hash for OXID validation |
| **OCP** | Service open for extension via interface |
| **LSP** | Any implementation can substitute |
| **ISP** | Focused interface with hash operations only |
| **DIP** | Handler depends on interface, not concrete service |

---

## Success Criteria

- ✅ No direct `$_REQUEST` modification in handlers
- ✅ Dedicated service handles hash injection
- ✅ Service is required (not optional) in handler
- ✅ Anti-pattern documented with clear explanation
- ✅ All unit tests pass (1348 tests)

---

## Related Issues

- CODE_REVIEW.md Section 4.4 (HIGH: Direct $_REQUEST Modification) - **ADDRESSED**
- CODE_REVIEW.md Section 4.8 (MEDIUM: Session Manipulation in Multiple Handlers) - **PARTIALLY ADDRESSED**

---

**Completed:** 2025-12-09
**Author:** Claude Code (AI Assistant)
