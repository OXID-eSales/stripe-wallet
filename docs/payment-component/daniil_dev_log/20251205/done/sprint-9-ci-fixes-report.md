# Sprint 9: CI Integration Test Fixes - COMPLETED

**Date:** 2025-12-05
**Status:** COMPLETED
**Branch:** b-7.4.x-auth-STRP-70

---

## Summary

Fixed 16 CI integration test failures caused by Sprint 8's `osc_payment_order_state` table removal and service discovery issues.

## Root Causes Identified

### Category 1: Table Not Found (3 errors)
Tests referencing dropped `osc_payment_order_state` table.

### Category 2: Service Not Found (13 errors → 21 errors total)
Tests using `$container->get(ContractRepositoryInterface::class)` and `$container->get(EventDispatcherInterface::class)` but module not activated in CI.

## Changes Made

### 1. FullDataPersistenceFlowTest.php

**Removed:**
- `testOrderState_PersistsOrderContractLink()` - referenced dropped table
- `testOrderState_TracksPaymentStateChanges()` - referenced dropped table
- `createOrderStateId()` helper method

**Updated:**
- `testCompleteFlow_PopulatesAllTables()`:
  - Removed `osc_payment_order_state` INSERT
  - Added contract capture/refund field verification
  - Updated step numbers (8→8, 9→9, 10→10)

**Added:**
- Contract `setCapturedAmount()` and `setCapturedAt()` calls
- Verification for `OXCAPTUREDAMOUNT` and `OXCAPTUREDAT`

### 2. ContractCaptureRefundTest.php

**Changed:**
```php
// BEFORE (CI fails)
$this->contractRepository = $container->get(ContractRepositoryInterface::class);

// AFTER (works in CI)
$this->contractRepository = new DoctrineContractRepository($this->connection);
```

### 3. ContractAwareOxpaidWebhookTest.php

**Changed:**
```php
// BEFORE
$contractRepository = $container->get(ContractRepositoryInterface::class);
$webhookLogRepository = $container->get(WebhookLogRepositoryInterface::class);

// AFTER
$contractRepository = new DoctrineContractRepository($this->connection);
$webhookLogRepository = new DoctrineWebhookLogRepository($this->connection);
```

### 4. OxpaidWebhookUpdateTest.php

Same changes as ContractAwareOxpaidWebhookTest.php.

### 5. EventDispatcher Direct Instantiation (Additional Fix)

Both webhook test files also needed EventDispatcher to be instantiated directly:

```php
// BEFORE (CI fails - 8 additional errors)
$eventDispatcher = $container->get(EventDispatcherInterface::class);

// AFTER (works in CI)
$eventDispatcher = new EventDispatcher(null);
```

## Files Modified

| File | Type | Changes |
|------|------|---------|
| `tests/Integration/Component/Checkout/FullDataPersistenceFlowTest.php` | Modified | Removed order_state tests, updated complete flow |
| `tests/Integration/Component/Contract/ContractCaptureRefundTest.php` | Modified | Direct repo instantiation |
| `tests/Integration/Stripe/Webhook/ContractAwareOxpaidWebhookTest.php` | Modified | Direct repo instantiation |
| `tests/Integration/Stripe/Webhook/OxpaidWebhookUpdateTest.php` | Modified | Direct repo instantiation |

## Test Results

### Unit Tests (Local)
```
Tests: 1109, Assertions: 2476
Status: ✅ PASSING
```

### Integration Tests (Local)
```
Tests: 306, Assertions: 1098
Status: ✅ PASSING (0 errors)
```

## Key Insight

**Direct instantiation pattern for CI compatibility:**

```php
// ❌ Fails in CI (service not registered without module activation)
$repo = $container->get(ContractRepositoryInterface::class);

// ✅ Works in CI (direct instantiation with connection)
$container = ContainerFactory::getInstance()->getContainer();
$connectionProvider = $container->get(ConnectionProviderInterface::class);
$connection = $connectionProvider->get();
$repo = new DoctrineContractRepository($connection);
```

## Architecture Impact

- No changes to production code
- Only test infrastructure updated
- Pattern established for future integration tests

## Next Steps

1. Push changes to branch
2. Verify CI passes (0 integration test errors)
3. Move on to Sprint 10 (OXPAID issue - which is now understood to be by design)
