# Stripe Payment Module - Project Status

**Project:** osc/stripe for OXID eShop 7.4+
**Date:** January 12, 2026
**Developer:** Daniil (Claude Code)
**Branch:** b-7.4.x-create-order-STRP-74

---

## Completed Sprints

### Sprint 1: STRP-74 - State Machine Update (DONE)

Updated contract-order state machine to create orders early:
- Added `NOT_FINISHED` state to ContractState
- Added `transitionToNotFinished(orderId)` method to PaymentContract
- Created `EarlyOrderCreationHandler` for early order creation
- Created `ContractDraftCompletedEvent` to trigger the flow

**New Flow:**
```
DRAFT → NOT_FINISHED → PENDING → AUTHORIZED → READY_TO_COMMIT → COMMITTED → FULFILLED
         ↑
    (order created here)
```

### Sprint 2: STRP-75 - Order Number in Payment Intent (DONE)

Updated payment intent initialization to include order info in Stripe metadata:
- Added `orderId` and `orderNumber` parameters to CheckoutSessionService
- Updated StripeCheckoutSessionHandler to pass order info
- Updated EarlyOrderCreationHandler to store order number in contract metadata

**Metadata now includes:**
```php
'metadata' => [
    'contract_id' => $contractId,
    'order_id' => $orderId,
    'order_number' => $orderNumber,
    'shop_id' => $shopId,
],
```

---

## Sprint Status Overview

| Sprint | Ticket  | Description | Status |
|--------|---------|-------------|--------|
| 1 | STRP-74 | State Machine Update (early order creation) | DONE |
| 2 | STRP-75 | Order Number in Payment Intent Metadata | DONE |

---

## Files

| File | Description |
|------|-------------|
| [done/sprint-1-state-machine-update.md](done/sprint-1-state-machine-update.md) | Sprint 1 plan |
| [done/sprint-2-order-number-in-payment-intent.md](done/sprint-2-order-number-in-payment-intent.md) | Sprint 2 plan |
| [report/sprint-1-report.md](report/sprint-1-report.md) | Sprint 1 report |
| [report/sprint-2-report.md](report/sprint-2-report.md) | Sprint 2 report |
| [puml/01-contract-order-state-machine-v2.puml](puml/01-contract-order-state-machine-v2.puml) | Updated state machine |

---

## Test Results

- Unit Tests: 1363 passed
- Integration Tests: Fixed (12 failures resolved)
- PHPStan: No errors
- PHPMD: Passed
- PHP Code Sniffer: Passed

### Integration Test Fixes (STRP-74 follow-up)

The following integration tests were updated to use the new state machine flow:

1. **ContractStateMachineTest** - Updated error message expectations
   - `transitionToNotFinishedRequiresConditions` (new test)
   - `transitionToPendingRequiresNotFinishedState` (renamed)
   - `transitionToPendingOnlyFromNotFinished` (renamed)

2. **ContractLifecycleIntegrationTest** - Added EarlyOrderCreationHandler
   - Registers EarlyOrderCreationHandler for ContractDraftCompletedEvent
   - Added InMemoryShopOrderService for order creation

3. **EventChainIntegrationTest** - Added EarlyOrderCreationHandler
   - Updated handler chain to include EarlyOrderCreation
   - Updated handler order assertions

4. **ControllerEventSystemIntegrationTest** - Refactored to use in-memory repository
   - Changed from IntegrationTestCase to TestCase (no OXID container dependency)
   - Uses ContractRepository (in-memory) instead of DoctrineContractRepository
   - Added EarlyOrderCreationHandler to handler chain
   - Renamed DB tests to repository tests

### EarlyOrderCreationHandler Enhancement

Updated EarlyOrderCreationHandler to also transition to PENDING state after NOT_FINISHED:
- Creates order and transitions to NOT_FINISHED
- Transitions to PENDING and dispatches ContractTransitionedToPendingEvent
- Dispatches OrderCreatedEvent

This ensures the complete flow works: DRAFT → NOT_FINISHED → PENDING → READY_TO_COMMIT

### StripeContractCreationHandler Event Dispatch Fix (Critical)

Fixed a critical issue where checkout would fail with "maintenance mode" error:

**Root Cause:** `StripeContractCreationHandler` was not dispatching `ContractDraftCompletedEvent` after creating the contract. This meant `EarlyOrderCreationHandler` never ran, leaving contracts in DRAFT state.

**Fix Applied:**
- Updated `StripeContractCreationHandler` to dispatch `ContractDraftCompletedEvent`
- Added `EventDispatcherInterface` dependency to the handler
- Updated `services.yaml` to inject the event dispatcher
- Updated unit tests to include the new mock

**Files Modified:**
- `src/Stripe/EventSystem/Handler/StripeContractCreationHandler.php`
- `services.yaml`
- `tests/Unit/Stripe/EventSystem/Handler/StripeContractCreationHandlerTest.php`
- `tests/Unit/Stripe/EventSystem/Handler/AddressHashStorageTest.php`

---

## E2E Test Results

**Playwright Checkout Tests:** 2 passed

The checkout flow now works correctly:
1. User clicks "Place Order"
2. `StripeContractCreationHandler` creates contract (DRAFT)
3. `StripeContractCreationHandler` dispatches `ContractDraftCompletedEvent`
4. `EarlyOrderCreationHandler` creates order and transitions DRAFT → NOT_FINISHED → PENDING
5. `StripeCheckoutSessionHandler` creates Stripe Checkout Session with order metadata
6. User completes payment on Stripe
7. Webhook confirms payment, contract transitions PENDING → AUTHORIZED → READY_TO_COMMIT → ...

### PaymentAuthorizedEventHandler OXTRANSID Fix (Follow-up)

Fixed issue where `OXTRANSID` and `OXPAID` were not being set on orders created by the early order creation flow.

**Root Cause:** When `EarlyOrderCreationHandler` creates the order (STRP-74), the Payment Intent ID is not available yet. Later when the user returns from Stripe and `PaymentAuthorizedEvent` is handled, the Payment Intent ID is stored in the contract but NOT in the order's `OXTRANSID` field.

**Fix Applied:**
- Updated `PaymentAuthorizedEventHandler` to call `orderPaymentStateService->updateTransactionId()` when the contract already has an order ID
- This links the Payment Intent ID to the existing order so webhooks can update `OXPAID`

**Files Modified:**
- `src/Component/EventSystem/Handler/PaymentAuthorizedEventHandler.php`
- `services.yaml`

**Verification:**
- New orders now have valid `OXTRANSID` (e.g., `pi_3SollNAeMx6SN5PN0Sk8WebS`)
- New orders now have valid `OXPAID` timestamps
- Stripe dashboard links work correctly

### StripeOrderCreationHandler Duplicate Order Fix (Critical)

Fixed critical bug where TWO orders were created for each checkout:
1. First order by `EarlyOrderCreationHandler` (early creation for STRP-74)
2. Second order by `StripeOrderCreationHandler` (legacy creation)

**Root Cause:** `StripeOrderCreationHandler` was creating a new order regardless of whether one already existed from early creation.

**Fix Applied:**
- Updated `StripeOrderCreationHandler::handle()` to check if `contract->getOrderId()` already exists
- If order exists, skip new order creation and use existing order
- Added `handleExistingOrder()` method to:
  - Set context variables for downstream handlers (thankyou page)
  - Commit contract to existing order
  - Update OXPAID on existing order

**Files Modified:**
- `src/Stripe/EventSystem/Handler/StripeOrderCreationHandler.php`

**Log Verification:**
```
StripeOrderCreationHandler: Order already exists (early creation), skipping new order
StripeOrderCreationHandler: Using existing order {"orderId":"...","orderNumber":92}
StripeOrderCreationHandler: Updating OXPAID on existing order (automatic capture)
```

**Result:**
- No more duplicate orders
- Single order per checkout with proper OXPAID and OXTRANSID
- Stripe dashboard link working correctly

---

## Quick Commands

### Run Unit Tests
```bash
docker compose exec php php vendor/bin/phpunit -c extensions/stripe/tests/phpunit.xml --testsuite Unit
```

### Run Pre-commit Check
```bash
./bin/pre-commit-check.sh
```

---

**Last Updated:** 2026-01-12
