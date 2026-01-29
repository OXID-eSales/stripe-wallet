# Sprint 23: Remove Stock Management Completely

**Date:** 2026-01-28
**Priority:** HIGH
**Status:** COMPLETED

---

## Objective

Remove all stock management code from both `payment-component` and `stripe` module. Stock management adds complexity without value - OXID already handles stock on order creation.

---

## Rationale

**Why Remove:**
1. OXID eShop already manages stock automatically on order finalization
2. Stock reservation in contract creates race conditions and complexity
3. The "early reservation" concept doesn't align with OXID's order flow
4. Configuration toggle adds maintenance burden without clear benefit
5. Simplifies the codebase significantly

---

## TDD Approach

**Order of Operations:**
1. **Phase 1:** Remove production code (payment-component)
2. **Phase 2:** Remove production code (stripe services.yaml)
3. **Phase 3:** Remove tests
4. **Phase 4:** Update ContractCondition (remove TYPE_STOCK_RESERVED)
5. **Phase 5:** Run `./bin/pre-commit-check.sh --full`

---

## Phase 1: Remove Production Code (payment-component)

### Task 1.1: Delete Stock Service Files

**Files to DELETE:**
```
extensions/payment-component/src/Service/StockServiceInterface.php
extensions/payment-component/src/Service/OxidStockService.php
```

### Task 1.2: Delete Stock Exception Files

**Files to DELETE:**
```
extensions/payment-component/src/Service/Exception/InsufficientStockException.php
extensions/payment-component/src/Service/Exception/StockReleaseException.php
```

### Task 1.3: Delete Stock Handler Files

**Files to DELETE:**
```
extensions/payment-component/src/EventSystem/Handler/StockReservationHandler.php
extensions/payment-component/src/EventSystem/Handler/StockReleaseHandler.php
```

### Task 1.4: Update ContractCondition.php

**File:** `extensions/payment-component/src/Contract/ContractCondition.php`

**Remove:**
- `TYPE_STOCK_RESERVED` constant
- `stockReserved()` factory method
- `'stock_reserved'` from `validateType()` array

---

## Phase 2: Remove Production Code (Stripe services.yaml)

### Task 2.1: Update services.yaml

**File:** `src/Stripe/services.yaml`

**Remove these sections:**
```yaml
# Stock Service registration
OxidEsales\PaymentComponent\Service\StockServiceInterface:
  class: OxidEsales\PaymentComponent\Service\OxidStockService
  ...

# Stock Reservation Handler
OxidEsales\PaymentComponent\EventSystem\Handler\StockReservationHandler:
  ...

# Stock Release Handler
OxidEsales\PaymentComponent\EventSystem\Handler\StockReleaseHandler:
  ...

# Parameter
parameters:
  payment.stock_reservation.enabled: true
```

---

## Phase 3: Remove Tests

### Task 3.1: Delete Stock Service Tests

**Files to DELETE:**
```
extensions/payment-component/tests/Unit/Service/OxidStockServiceTest.php
```

### Task 3.2: Delete Stock Exception Tests

**Files to DELETE:**
```
extensions/payment-component/tests/Unit/Service/Exception/InsufficientStockExceptionTest.php
extensions/payment-component/tests/Unit/Service/Exception/StockReleaseExceptionTest.php
```

### Task 3.3: Delete Stock Handler Tests

**Files to DELETE:**
```
extensions/payment-component/tests/Unit/EventSystem/Handler/StockReservationHandlerTest.php
extensions/payment-component/tests/Unit/EventSystem/Handler/StockReleaseHandlerTest.php
```

### Task 3.4: Update Tests Using stock_reserved

**Files to MODIFY:**
```
extensions/payment-component/tests/Unit/EventSystem/Event/Contract/ContractTransitionedToPendingEventTest.php
extensions/payment-component/tests/Unit/EventSystem/Event/Contract/ContractConditionFulfilledEventTest.php
```

**Changes:**
- Remove references to `stock_reserved` condition
- Remove `ContractCondition::stockReserved()` usage
- Update test data that includes stock conditions

---

## Phase 4: Run Pre-Commit Check

```bash
./bin/pre-commit-check.sh --full
```

**Expected:** All tests pass, no PHPStan/PHPCS/PHPMD errors

---

## Files Summary

### DELETE (11 files)

| File | Type |
|------|------|
| `payment-component/src/Service/StockServiceInterface.php` | Interface |
| `payment-component/src/Service/OxidStockService.php` | Service |
| `payment-component/src/Service/Exception/InsufficientStockException.php` | Exception |
| `payment-component/src/Service/Exception/StockReleaseException.php` | Exception |
| `payment-component/src/EventSystem/Handler/StockReservationHandler.php` | Handler |
| `payment-component/src/EventSystem/Handler/StockReleaseHandler.php` | Handler |
| `payment-component/tests/Unit/Service/OxidStockServiceTest.php` | Test |
| `payment-component/tests/Unit/Service/Exception/InsufficientStockExceptionTest.php` | Test |
| `payment-component/tests/Unit/Service/Exception/StockReleaseExceptionTest.php` | Test |
| `payment-component/tests/Unit/EventSystem/Handler/StockReservationHandlerTest.php` | Test |
| `payment-component/tests/Unit/EventSystem/Handler/StockReleaseHandlerTest.php` | Test |

### MODIFY (3 files)

| File | Change |
|------|--------|
| `payment-component/src/Contract/ContractCondition.php` | Remove TYPE_STOCK_RESERVED, stockReserved() |
| `stripe/services.yaml` | Remove stock service registrations and parameter |
| Event tests | Remove stock_reserved references |

---

## Acceptance Criteria

- [x] No `StockService` references in codebase
- [x] No `StockReservationHandler` references in codebase
- [x] No `StockReleaseHandler` references in codebase
- [x] No `InsufficientStockException` references in codebase
- [x] No `StockReleaseException` references in codebase
- [x] No `TYPE_STOCK_RESERVED` in ContractCondition
- [x] No `payment.stock_reservation.enabled` parameter
- [x] All tests pass (Unit + Integration)
- [x] `./bin/pre-commit-check.sh --full` passes

---

## Definition of Done

1. All files deleted
2. ContractCondition updated
3. services.yaml updated
4. Tests updated
5. Pre-commit check passes
6. Move this file to `done/SPRINT-23-remove-stock-management.md`
7. Update `status.md`
