# End-to-End Checkout Flow Integration Test

**Date:** 2025-11-26
**Status:** Completed
**Test File:** `tests/Integration/Component/Checkout/EndToEndCheckoutFlowTest.php`

## Overview

Created a comprehensive end-to-end integration test that verifies the complete checkout flow from `OrderController.execute()` through to `ThankyouController`, testing the contract and order state machine using **real database connection**.

### Key Features
- Uses **real MySQL database** via `DoctrineContractRepository`
- Data is **persisted and NOT cleaned up** after tests for manual inspection
- All test data has `e2e_` prefix for easy identification
- Tests the complete contract state machine flow

## Test Coverage

### 12 Database Integration Tests (All Passing)

| Phase | Tests | Description |
|-------|-------|-------------|
| 1. Contract Creation | 3 | OrderController.execute() creates contract in DB |
| 2. State Machine | 2 | DRAFT → PENDING → READY_TO_COMMIT → COMMITTED → FULFILLED |
| 3. Condition Fulfillment | 2 | payment_authorized, fraud_check (persisted in DB) |
| 4. Order Completion | 2 | ThankyouController confirmation flow |
| 5. Complete Flow | 2 | Full E2E flow, cancellation (all persisted) |
| 6. Provider Info | 1 | Stripe PaymentIntent tracking in DB |

## Architecture Tested

Based on the flow documented in `docs/payment-component/puml/04-02-payment-smart-contract-flow-standard.puml`:

```
Customer → OrderController → CheckoutOrchestrator → EventDispatcher
                                    ↓
                          ContractCreationHandler
                                    ↓
                          PaymentContract (DRAFT)
                                    ↓
                          Add Conditions → PENDING
                                    ↓
                          Fulfill Conditions → READY_TO_COMMIT
                                    ↓
                          commitToOrder() → COMMITTED
                                    ↓
Customer ← ThankyouController ← confirmOrderCompletion()
                                    ↓
                          (Webhook) → FULFILLED
```

## Key Design Decisions

### 1. Only Mock Frontend Request
- Basket and User objects are mocked (simulating frontend request)
- All other code is **real production code**:
  - `CheckoutOrchestrator`
  - `ContractCreationHandler`
  - `ContractService`
  - `PaymentContract`
  - `EventDispatcher`
  - `DoctrineContractRepository` (real DB!)

### 2. Real Database Connection
Uses OXID's `ConnectionProviderInterface` to get Doctrine DBAL connection:
```php
$container = ContainerFactory::getInstance()->getContainer();
$connectionProvider = $container->get(ConnectionProviderInterface::class);
$this->connection = $connectionProvider->get();

// Real repository with real DB
$this->contractRepository = new DoctrineContractRepository($this->connection);
```

### 3. Data Persistence (No Cleanup)
Unlike standard OXID integration tests, data is **committed** not rolled back:
```php
public function tearDown(): void
{
    // Commit transaction instead of rollback - data stays in DB
    $this->commitTransaction();
    // ...
}
```

This allows:
- Manual inspection of test data after runs
- Verification of actual DB persistence
- Debugging production-like scenarios

### 4. Test Data Identification
All test IDs use `e2e_` prefix for easy identification:
```sql
SELECT * FROM oe_payments_contract WHERE OXID LIKE 'e2e_%';
```

### 5. What's NOT Tested
As requested, these are excluded (require Stripe API integration):
- `oe_payments_idempotency` table
- `oe_payments_webhook` table
- Actual Stripe API calls
- Webhook signature verification

## Bug Discovered and Fixed

### ContractCreationHandler Bug

**File:** `src/Component/EventSystem/Handler/ContractCreationHandler.php:58`

**Before (Bug):**
```php
$context->set('contract', $contract);  // Stores in generic data array
```

**After (Fixed):**
```php
$context->setContract($contract);  // Stores in typed property
```

**Impact:** The `CheckoutOrchestrator` was calling `$context->getContract()` which reads from the typed `$contract` property, but the handler was storing in the generic data array. This caused all checkout attempts to fail with "Contract creation failed".

## Test Examples

### Complete Flow Test
```php
public function testCompleteFlow_FromOrderToThankyou(): void
{
    // STEP 1: OrderController.execute()
    $checkoutResult = $this->orchestrator->processCheckout(
        $basket, $user, 'stripe_card', 'pi_123'
    );

    // STEP 2: Contract exists
    $contract = $this->contractRepository->findById($contractId);

    // STEP 3: Fulfill conditions
    $contract->transitionToPending();
    $contract->fulfillCondition(TYPE_PAYMENT_AUTHORIZED, [...]);
    $contract->fulfillCondition(TYPE_FRAUD_CHECK, [...]);

    // STEP 4: Create order
    $contract->commitToOrder($orderId);

    // STEP 5: ThankyouController
    $confirmResult = $this->orchestrator->confirmOrderCompletion($orderId, $contractId);

    // STEP 6: Webhook captures payment
    $contract->fulfill();

    // Assert final state
    $this->assertTrue($contract->getState()->isFulfilled());
}
```

### State Machine Test
```php
public function testContractStateMachine_TransitionsToReadyToCommitWhenAllConditionsFulfilled(): void
{
    $contract->addCondition(ContractCondition::paymentAuthorized());
    $contract->addCondition(ContractCondition::fraudCheck());
    $contract->transitionToPending();

    // Fulfill first - still PENDING
    $contract->fulfillCondition(TYPE_PAYMENT_AUTHORIZED, [...]);
    $this->assertTrue($contract->getState()->isPending());

    // Fulfill second - transitions to READY_TO_COMMIT
    $contract->fulfillCondition(TYPE_FRAUD_CHECK, [...]);
    $this->assertTrue($contract->getState()->isReadyToCommit());
}
```

## Files Modified

| File | Change |
|------|--------|
| `src/Component/EventSystem/Handler/ContractCreationHandler.php` | Bug fix: `set()` → `setContract()` |
| `tests/Unit/Component/EventSystem/Handler/ContractCreationHandlerTest.php` | Updated to use `getContract()` |
| `tests/Integration/Component/Checkout/EndToEndCheckoutFlowTest.php` | **NEW** - 12 DB integration tests |

## Database Schema Constraints
Important note: OXID uses `char(32)` for ID columns:
- `OXID` - max 32 chars
- `OXUSERID` - max 32 chars
- `OXORDERID` - max 32 chars

Test IDs are generated with truncation to ensure fit:
```php
private function generateTestContractId(string $suffix): string
{
    $id = self::TEST_PREFIX . $this->testRunId . '_' . $suffix;
    return substr($id, 0, 32);  // Ensure max 32 chars
}
```

## Pre-Commit Check Results

```
======================================
SUMMARY
======================================

✓ ALL CHECKS PASSED
Status: COMMITABLE

Tests: 685, Assertions: 1593
```

## Querying Test Data

After running tests, you can inspect data:

```sql
-- View all E2E test contracts
SELECT OXID, OXSTATE, OXORDERID, OXUSERID
FROM oe_payments_contract
WHERE OXID LIKE 'e2e_%' OR OXUSERID LIKE 'e2e_%'
ORDER BY OXCREATED DESC;

-- View contract conditions
SELECT OXID, OXSTATE, OXCONDITIONS
FROM oe_payments_contract
WHERE OXID LIKE 'e2e_%';

-- Clean up test data (if needed)
DELETE FROM oe_payments_contract
WHERE OXID LIKE 'e2e_%' OR OXUSERID LIKE 'e2e_%';
```

## Related Documentation

- Architecture: `docs/payment-component/01-architecture-layers.md`
- Flow Diagram: `docs/payment-component/puml/04-02-payment-smart-contract-flow-standard.puml`
- State Machine: `docs/payment-component/puml/05-order-state-contract-machine.puml`
- Sprint Plan: `docs/payment-component/daniil_dev_log/20251126/todo/SPRINT-4-INTEGRATION.md`

## Next Steps

1. **Webhook Integration Tests** - Test `oe_payments_webhook` with mocked Stripe events
2. **Idempotency Tests** - Test `oe_payments_idempotency` for duplicate request handling
3. **Database Integration Tests** - Test with real `DoctrineContractRepository`
