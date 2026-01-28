# Report: Sprint 25 Follow-up Fixes and Code Reorganization

**Date:** 2026-01-28
**Type:** Technical Debt / Bug Fixes / Code Organization

---

## Overview

This report documents additional fixes and code reorganization performed after Sprint 25 (DTO Consolidation) was initially completed. These changes were necessary to resolve test failures, PHPStan errors, DI container issues, and code organization improvements.

---

## 1. Test Fixes (payment-component)

### Problem
After Sprint 25 DTO consolidation, the `PaymentCaptureServiceTest.php` in payment-component was accessing private properties directly instead of using getter methods.

### Errors
```
1) PaymentCaptureServiceTest::testCapturesFullAmount
Error: Cannot access private property CaptureResult::$captureId
/var/www/extensions/payment-component/tests/Unit/Service/PaymentCaptureServiceTest.php:88

2) PaymentCaptureServiceTest::testCapturesPartialAmount
Error: Cannot access private property CaptureResult::$amountCaptured
/var/www/extensions/payment-component/tests/Unit/Service/PaymentCaptureServiceTest.php:124
```

### Solution
Updated test assertions to use getter methods:

| File | Line | Before | After |
|------|------|--------|-------|
| `PaymentCaptureServiceTest.php` | 88 | `$result->captureId` | `$result->getCaptureId()` |
| `PaymentCaptureServiceTest.php` | 89 | `$result->amountCaptured` | `$result->getAmountCaptured()` |
| `PaymentCaptureServiceTest.php` | 124 | `$result->amountCaptured` | `$result->getAmountCaptured()` |

---

## 2. PHPStan Fixes (payment-component)

### Problem
The `create()` factory methods in `CaptureResult` and `RefundResult` had array parameters without proper type annotations.

### Errors
```
Line   Service/Result/CaptureResult.php
 55    Method create() has parameter $providerData with no value type specified in iterable type array.
 70    Parameter $providerData expects array<string, mixed>, array given.

Line   Service/Result/RefundResult.php
 61    Method create() has parameter $providerData with no value type specified in iterable type array.
 81    Parameter $providerData expects array<string, mixed>, array given.
```

### Solution
Added PHPDoc annotations to the `create()` methods:

```php
/**
 * @param array<string, mixed> $providerData
 */
public static function create(
    // ... parameters ...
    array $providerData = []
): self {
```

**Files Modified:**
- `payment-component/src/Service/Result/CaptureResult.php`
- `payment-component/src/Service/Result/RefundResult.php`

---

## 3. PHPStan Fixes (stripe)

### Problem
`OxidStockRestorationService.php` used `oxNew(Order::class)` which returns `mixed` according to PHPStan.

### Errors
```
Line   Service/OxidStockRestorationService.php
 55    Cannot call method getOrderArticles() on mixed.
 67    Cannot call method recalculateOrder() on mixed.
```

### Solution
Added type annotation:

```php
/** @var Order $order */
$order = oxNew(Order::class);
```

---

## 4. Dependency Injection Fix (services.yaml)

### Problem
Module activation failed with error:
```
Cannot autowire service "OxidEsales\Payments\Stripe\Service\OxidStockRestorationService":
argument "$connection" of method "__construct()" references class "Doctrine\DBAL\Connection"
but no such service exists. You should maybe alias this class to the existing
"doctrine.dbal.connection" service.
```

### Root Cause
The `OxidStockRestorationService` was being auto-discovered by the resource loader at lines 7-14 of `services.yaml`:

```yaml
OxidEsales\Payments\Stripe\Service\:
  resource: 'src/Stripe/Service/*'
  exclude:
    - 'src/Stripe/Service/Result/*'
    # ... other excludes ...
  public: true
```

This caused Symfony to autowire the service, but autowiring couldn't resolve `Doctrine\DBAL\Connection` directly (it needs the aliased `doctrine.dbal.connection` service).

Even though an explicit service definition existed (lines 584-592), the auto-discovered service took precedence.

### Solution
Added `OxidStockRestorationService.php` to the exclude list:

```yaml
OxidEsales\Payments\Stripe\Service\:
  resource: 'src/Stripe/Service/*'
  exclude:
    - 'src/Stripe/Service/Result/*'
    - 'src/Stripe/Service/ReconciliationResult.php'
    - 'src/Stripe/Service/RequestLogService.php'
    - 'src/Stripe/Service/WebhookLogService.php'
    - 'src/Stripe/Service/OxidStockRestorationService.php'  # Added
  public: true
```

Now only the explicit service definition is used, which correctly references `@doctrine.dbal.connection`.

---

## 5. Code Organization: ModuleConfiguration Move

### Problem
`ModuleConfiguration.php` was located in `src/Stripe/Application/Controller/Admin/` which was inconsistent with the codebase structure. All other admin controllers are in `src/Stripe/Controller/Admin/`.

### Solution
Moved the file and updated all references:

**File Move:**
- From: `src/Stripe/Application/Controller/Admin/ModuleConfiguration.php`
- To: `src/Stripe/Controller/Admin/ModuleConfiguration.php`

**Namespace Change:**
- From: `OxidEsales\Payments\Stripe\Application\Controller\Admin`
- To: `OxidEsales\Payments\Stripe\Controller\Admin`

**Files Updated:**

| File | Change |
|------|--------|
| `metadata.php` | Updated import statement |
| `tests/PhpStan/phpstan-bootstrap.php` | Updated class_alias for parent class |
| `recipe/var/configuration/shops/1/modules/oe_payments_stripe_wallet.yaml` | Updated classExtensions mapping |

**Directories Deleted (empty):**
- `src/Stripe/Application/Controller/Admin/`
- `src/Stripe/Application/Controller/`
- `src/Stripe/Application/`

---

## Summary of All Changes

### payment-component

| File | Action |
|------|--------|
| `tests/Unit/Service/PaymentCaptureServiceTest.php` | Fixed property access → getters |
| `src/Service/Result/CaptureResult.php` | Added PHPDoc for `$providerData` |
| `src/Service/Result/RefundResult.php` | Added PHPDoc for `$providerData` |

### stripe

| File | Action |
|------|--------|
| `services.yaml` | Added OxidStockRestorationService.php to exclude list |
| `src/Stripe/Service/OxidStockRestorationService.php` | Added `@var Order` annotation |
| `src/Stripe/Controller/Admin/ModuleConfiguration.php` | CREATED (moved from Application/) |
| `src/Stripe/Application/Controller/Admin/ModuleConfiguration.php` | DELETED |
| `metadata.php` | Updated import namespace |
| `tests/PhpStan/phpstan-bootstrap.php` | Updated class_alias namespace |
| `recipe/.../oe_payments_stripe_wallet.yaml` | Updated classExtensions namespace |

---

## Final State

```
payment-component: 599 tests, 1372 assertions - COMMITABLE
stripe: 606 tests, 1474 assertions - COMMITABLE

All checks pass:
✓ PHP Code Sniffer
✓ PHPUnit Tests
✓ PHPStan
✓ PHPMD
```

---

## Lessons Learned

1. **Auto-discovery conflicts**: When using Symfony's resource auto-discovery, services with constructor dependencies that require specific service aliases (like `doctrine.dbal.connection`) should be excluded from auto-discovery and defined explicitly.

2. **DTO property visibility**: When refactoring DTOs from public properties to private with getters, all test files must be updated to use the getter methods.

3. **Consistent folder structure**: Keeping related files in consistent locations (all admin controllers in `Controller/Admin/`) reduces confusion and makes the codebase easier to navigate.
