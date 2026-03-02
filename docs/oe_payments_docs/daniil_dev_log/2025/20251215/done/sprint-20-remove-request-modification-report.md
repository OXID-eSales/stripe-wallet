# Sprint 20: Remove $_REQUEST Modification - Completion Report

**Date:** 2025-12-15
**Status:** ALREADY COMPLETED (verified)
**Branch:** b-7.4.x-code-review-STRP-75

---

## Overview

Upon investigation, Sprint 20 was found to be **already implemented** in a previous session. The `$_REQUEST` modification has been encapsulated in a dedicated service.

---

## Verification Results

### 1. No $_REQUEST in Handlers

```bash
$ grep -rn '\$_REQUEST' src/Stripe/EventSystem/Handler/
# No matches found
```

Handlers no longer directly modify `$_REQUEST`.

### 2. DeliveryAddressHashService

**File:** `src/Stripe/Service/DeliveryAddressHashService.php`

The service encapsulates `$_REQUEST` modification with clear documentation:

```php
/**
 * WHY $_REQUEST MODIFICATION IS NECESSARY:
 * OXID's Order::validateDeliveryAddress() reads the hash from:
 *   Registry::getRequest()->getRequestEscapedParameter('sDeliveryAddressMD5')
 *
 * This ultimately reads from $_REQUEST. When returning from Stripe checkout,
 * the original form POST data is lost. To make OXID's validation pass,
 * we must inject the hash back into $_REQUEST.
 */
final class DeliveryAddressHashService implements DeliveryAddressHashServiceInterface
{
    public function restoreHashForValidation(?string $hash): void
    {
        // phpcs:ignore
        $_REQUEST[self::REQUEST_KEY] = $hash;
    }
}
```

### 3. DeliveryAddressHashServiceInterface

**File:** `src/Stripe/Service/DeliveryAddressHashServiceInterface.php`

Clean interface for dependency injection:

```php
interface DeliveryAddressHashServiceInterface
{
    public function restoreHashForValidation(?string $hash): void;
    public function getHash(): ?string;
    public function hasHash(): bool;
    public function clearHash(): void;
}
```

### 4. Handler Uses Service

**File:** `src/Stripe/EventSystem/Handler/StripeCheckoutReturnHandler.php` (line 181)

```php
$this->deliveryAddressHashService->restoreHashForValidation($deliveryHash);
```

---

## Architecture Compliance

The solution follows SOLID principles:

| Principle | Implementation |
|-----------|----------------|
| **SRP** | Service only handles delivery address hash |
| **OCP** | Interface allows alternative implementations |
| **DIP** | Handler depends on interface abstraction |
| **ISP** | Focused interface with 4 methods |

---

## Why $_REQUEST Is Still Necessary

OXID's core `Order::validateDeliveryAddress()` reads from:
```php
Registry::getRequest()->getRequestEscapedParameter('sDeliveryAddressMD5')
```

This reads from `$_REQUEST`. When returning from Stripe checkout:
1. Original form POST data is lost
2. Order validation would fail without the hash
3. Service restores the hash so validation passes

**Trade-off:** Modifying `$_REQUEST` is an anti-pattern, but it's necessary for OXID compatibility. The service:
- Documents why it's necessary
- Isolates the modification
- Makes handlers testable (mock the service)

---

## CODE_REVIEW.md Status

The finding from CODE_REVIEW.md (2025-12-09) is now **RESOLVED**:

| Finding | Original Status | Current Status |
|---------|-----------------|----------------|
| `StripeCheckoutReturnHandler.php:302` `$_REQUEST` modification | HIGH | RESOLVED (via service) |

---

## Locations of $_REQUEST Usage

After Sprint 20, `$_REQUEST` is only touched in:

| File | Line | Purpose |
|------|------|---------|
| `DeliveryAddressHashService.php` | 49 | `restoreHashForValidation()` |
| `DeliveryAddressHashService.php` | 55 | `getHash()` |
| `DeliveryAddressHashService.php` | 63 | `hasHash()` |
| `DeliveryAddressHashService.php` | 69 | `clearHash()` |

All encapsulated in one service with `// phpcs:ignore` comments.

---

## Conclusion

**Sprint 20 was already completed.** The `$_REQUEST` modification is now:
- Encapsulated in `DeliveryAddressHashService`
- Documented with clear explanation
- Testable via interface mocking

The sprint document in `todo/sprint-20-remove-request-modification.md` can be moved to `done/`.

---

**Verified:** 2025-12-15
**Author:** Claude Code (AI Assistant)
