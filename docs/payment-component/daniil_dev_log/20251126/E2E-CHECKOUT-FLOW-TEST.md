# End-to-End Checkout Flow Integration Test

**Date:** 2025-11-26
**Status:** Completed
**Test File:** `tests/Integration/Component/Checkout/EndToEndCheckoutFlowTest.php`

## Overview

Created a comprehensive end-to-end integration test that verifies the complete checkout flow from `OrderController.execute()` through to `ThankyouController`, testing the contract and order state machine without requiring Stripe API integration.

## Test Coverage

### 19 Tests Total (All Passing)

| Phase | Tests | Description |
|-------|-------|-------------|
| 1. Contract Creation | 3 | OrderController.execute() creates contract |
| 2. State Machine | 4 | DRAFT → PENDING → READY_TO_COMMIT → COMMITTED → FULFILLED |
| 3. Condition Fulfillment | 4 | payment_authorized, fraud_check, stock_reserved |
| 4. Order Completion | 3 | ThankyouController confirmation flow |
| 5. Complete Flow | 3 | Full E2E flow, cancellation, expiration |
| 6. Provider Info | 2 | Stripe PaymentIntent tracking |

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
  - `EventContext`

### 2. InMemoryContractRepository
Instead of database, uses in-memory repository:
```php
class InMemoryContractRepository implements ContractRepositoryInterface
{
    private array $contracts = [];
    // ... implements all interface methods
}
```

This allows tests to run:
- Without OXID shop activation
- Without database connection
- In CI environments (GitHub Actions)
- Fast (no I/O overhead)

### 3. What's NOT Tested
As requested, these are excluded (require Stripe API integration):
- `osc_payment_idempotency` table
- `osc_payment_webhook` table
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
| `tests/Integration/Component/Checkout/EndToEndCheckoutFlowTest.php` | **NEW** - 19 E2E tests |

## Pre-Commit Check Results

```
======================================
SUMMARY
======================================

✓ ALL CHECKS PASSED
Status: COMMITABLE

Tests: 685, Assertions: 1593
```

## Related Documentation

- Architecture: `docs/payment-component/01-architecture-layers.md`
- Flow Diagram: `docs/payment-component/puml/04-02-payment-smart-contract-flow-standard.puml`
- State Machine: `docs/payment-component/puml/05-order-state-contract-machine.puml`
- Sprint Plan: `docs/payment-component/daniil_dev_log/20251126/todo/SPRINT-4-INTEGRATION.md`

## Next Steps

1. **Webhook Integration Tests** - Test `osc_payment_webhook` with mocked Stripe events
2. **Idempotency Tests** - Test `osc_payment_idempotency` for duplicate request handling
3. **Database Integration Tests** - Test with real `DoctrineContractRepository`
